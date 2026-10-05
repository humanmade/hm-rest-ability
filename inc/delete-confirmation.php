<?php
/**
 * Serves `rest-api-delete` as a direct MCP tool that asks the user to confirm
 * each deletion through MCP elicitation, when the client supports it.
 *
 * Clients that can't show an elicitation form get the delete straight away,
 * as before. Needs MCP Adapter 0.7.0 or later; on older versions the
 * `rest-api/delete` ability is served as a plain ability-backed tool.
 *
 * @package HM\RestAbility
 */

namespace HM\DeleteConfirmation;

use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP_Error;
use WP_REST_Request;

use function HM\RestApiAbilities\execute;
use function HM\RestApiAbilities\input_schema;
use function HM\RestApiAbilities\tool_definitions;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ABILITY_NAME = 'rest-api/delete';
const TOOL_NAME    = 'rest-api-delete';

/**
 * Key of the confirmation form in the elicitation request and response maps.
 */
const INPUT_KEY = 'confirm_delete';

/**
 * How long, in seconds, a confirmation request stays valid.
 */
const STATE_TTL = 600;

add_filter( 'wp_register_ability_args', __NAMESPACE__ . '\\hide_ability_from_mcp', 10, 2 );
add_filter( 'mcp_adapter_default_server_config', __NAMESPACE__ . '\\filter_mcp_server_config', 20 );

/**
 * Whether the loaded MCP Adapter supports elicitation from direct tools.
 *
 * @return bool
 */
function is_available(): bool {
	return class_exists( McpInputRequired::class ) && class_exists( McpToolCallContext::class );
}

/**
 * Takes the delete ability off the MCP server's generic ability tools, so
 * `mcp-adapter-execute-ability` can't run a delete without confirmation.
 *
 * @param array  $args Ability registration args.
 * @param string $name Ability name.
 * @return array
 */
function hide_ability_from_mcp( array $args, string $name ): array {
	if ( ABILITY_NAME !== $name || ! is_available() ) {
		return $args;
	}

	$args['meta']['mcp']['public'] = false;

	return $args;
}

/**
 * Swaps the ability-backed delete tool for the direct tool.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	if ( ! is_available() ) {
		return $config;
	}

	$tool = build_tool();

	if ( is_wp_error( $tool ) ) {
		return $config;
	}

	$tools   = array_values( array_diff( $config['tools'] ?? [], [ ABILITY_NAME ] ) );
	$tools[] = $tool;

	$config['tools'] = $tools;

	return $config;
}

/**
 * Builds the direct delete tool from the ability's definition.
 *
 * @return McpTool|WP_Error
 */
function build_tool(): McpTool|WP_Error {
	$definition  = tool_definitions()[ ABILITY_NAME ];
	$annotations = McpAnnotationMapper::map( $definition['annotations'], 'tool' );

	$annotations['title'] = $definition['label'];

	return McpTool::fromArray(
		[
			'name'        => TOOL_NAME,
			'title'       => $definition['label'],
			'description' => $definition['description'],
			'inputSchema' => input_schema( $definition['methods'] ),
			'annotations' => $annotations,
			'handler'     => __NAMESPACE__ . '\\handle',
			'permission'  => 'HM\\RestApiAbilities\\check_permission',
		]
	);
}

/**
 * Handles a call to the delete tool.
 *
 * The first call asks the client to confirm with the user. The client then
 * calls again with the user's answer, and the delete runs only if they
 * accepted.
 *
 * @param array                    $args    Tool arguments, keyed by `method`, `route`, `params`.
 * @param McpToolCallContext|null $context Client input for this call.
 * @return array|McpInputRequired|WP_Error
 */
function handle( array $args, ?McpToolCallContext $context = null ) {
	if ( null !== $context && $context->is_continuation() ) {
		$confirmed = check_confirmation( $context, $args );

		return true === $confirmed ? execute( $args ) : $confirmed;
	}

	if ( null === $context || ! $context->client_supports_elicitation( 'form' ) ) {
		return execute( $args );
	}

	return new McpInputRequired(
		[ INPUT_KEY => confirmation_request( $args ) ],
		sign_state( $args, time() + STATE_TTL )
	);
}

/**
 * Builds the elicitation request that asks the user to confirm a delete.
 *
 * @param array $args Tool arguments.
 * @return array
 */
function confirmation_request( array $args ): array {
	return [
		'method' => 'elicitation/create',
		'params' => [
			'mode'            => 'form',
			'message'         => confirmation_message( $args ),
			'requestedSchema' => [
				'type'       => 'object',
				'properties' => [
					'confirm' => [
						'type'        => 'boolean',
						'title'       => 'Yes, delete it',
						'description' => 'Tick to confirm. This changes the live site and may not be undone.',
						'default'     => false,
					],
				],
				'required'   => [ 'confirm' ],
			],
		],
	];
}

/**
 * Describes the delete for the user, naming the item where the route has one.
 *
 * @param array $args Tool arguments.
 * @return string
 */
function confirmation_message( array $args ): string {
	$route  = $args['route'] ?? '';
	$params = $args['params'] ?? [];
	$label  = target_label( $route );

	$message = '' === $label
		? sprintf( 'Delete %s on %s?', $route, get_bloginfo( 'name' ) )
		: sprintf( 'Delete "%s" (%s) on %s?', $label, $route, get_bloginfo( 'name' ) );

	if ( ! empty( $params ) ) {
		$message .= ' Params: ' . wp_json_encode( $params ) . '.';
	}

	return $message;
}

/**
 * Returns the title or name of the item at a route, or '' if it has none.
 *
 * @param string $route REST route path.
 * @return string
 */
function target_label( string $route ): string {
	$response = rest_do_request( new WP_REST_Request( 'GET', $route ) );

	if ( $response->is_error() ) {
		return '';
	}

	$data  = $response->get_data();
	$label = is_array( $data ) ? ( $data['title']['rendered'] ?? $data['title'] ?? $data['name'] ?? '' ) : '';

	return is_string( $label ) ? trim( wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES ) ) ) : '';
}

/**
 * Checks the user's answer on the follow-up call.
 *
 * @param McpToolCallContext $context Client input for this call.
 * @param array              $args    Tool arguments.
 * @return true|WP_Error
 */
function check_confirmation( McpToolCallContext $context, array $args ): bool|WP_Error {
	if ( ! verify_state( (string) $context->request_state(), $args ) ) {
		return new WP_Error(
			'rest_ability_confirmation_invalid',
			'This confirmation is missing, expired, or for a different request. Nothing was deleted. Call the tool again to ask the user.'
		);
	}

	$response = $context->input_responses()->{INPUT_KEY} ?? null;
	$accepted = 'accept' === ( $response->action ?? null ) && true === ( $response->content->confirm ?? null );

	if ( ! $accepted ) {
		return new WP_Error( 'rest_ability_delete_not_confirmed', 'The user did not confirm the delete. Nothing was deleted.' );
	}

	return true;
}

/**
 * Signs a confirmation request to the current user, this exact call, and an
 * expiry time.
 *
 * @param array $args    Tool arguments.
 * @param int   $expires Unix time the confirmation expires.
 * @return string
 */
function sign_state( array $args, int $expires ): string {
	return $expires . '.' . state_hash( $args, $expires );
}

/**
 * Checks that request state was signed for this user and call, and hasn't
 * expired.
 *
 * @param string $state Request state sent back by the client.
 * @param array  $args  Tool arguments.
 * @return bool
 */
function verify_state( string $state, array $args ): bool {
	$parts = explode( '.', $state, 2 );

	if ( 2 !== count( $parts ) || ! ctype_digit( $parts[0] ) || (int) $parts[0] < time() ) {
		return false;
	}

	return hash_equals( state_hash( $args, (int) $parts[0] ), $parts[1] );
}

/**
 * Returns the signature for a confirmation request.
 *
 * @param array $args    Tool arguments.
 * @param int   $expires Unix time the confirmation expires.
 * @return string
 */
function state_hash( array $args, int $expires ): string {
	$request = [
		get_current_user_id(),
		strtoupper( $args['method'] ?? '' ),
		$args['route'] ?? '',
		$args['params'] ?? [],
		$expires,
	];

	return hash_hmac( 'sha256', (string) wp_json_encode( $request ), wp_salt( 'nonce' ) );
}
