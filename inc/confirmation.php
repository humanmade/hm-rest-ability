<?php
/**
 * Serves `rest-api-write` and `rest-api-delete` as direct MCP tools that ask
 * the user to confirm risky calls through MCP elicitation, when the client
 * supports it.
 *
 * Every delete is risky, and so is any write to a route that
 * `classify_route()` rates above `routine`. Clients that can't show an
 * elicitation form get the call straight away, as before. Needs MCP Adapter
 * 0.7.0 or later; on older versions both abilities are served as plain
 * ability-backed tools.
 *
 * @package HM\RestAbility
 */

namespace HM\Confirmation;

use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\MCP\Domain\Utils\McpAnnotationMapper;
use WP_Error;
use WP_REST_Request;

use function HM\RestApiAbilities\execute;
use function HM\RestApiAbilities\input_schema;
use function HM\RestApiAbilities\tool_definitions;
use function HM\RouteRisk\classify_route;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abilities served as direct tools, so they can ask for confirmation.
 */
const ABILITY_NAMES = [ 'rest-api/write', 'rest-api/delete' ];

/**
 * Key of the confirmation form in the elicitation request and response maps.
 */
const INPUT_KEY = 'confirm';

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
 * Takes the write and delete abilities off the MCP server's generic ability
 * tools, so `mcp-adapter-execute-ability` can't run them without confirmation.
 *
 * @param array  $args Ability registration args.
 * @param string $name Ability name.
 * @return array
 */
function hide_ability_from_mcp( array $args, string $name ): array {
	if ( ! in_array( $name, ABILITY_NAMES, true ) || ! is_available() ) {
		return $args;
	}

	$args['meta']['mcp']['public'] = false;

	return $args;
}

/**
 * Swaps the ability-backed write and delete tools for direct tools.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	if ( ! is_available() ) {
		return $config;
	}

	$tools = $config['tools'] ?? [];

	foreach ( ABILITY_NAMES as $ability_name ) {
		$tool = build_tool( $ability_name );

		if ( is_wp_error( $tool ) ) {
			continue;
		}

		$tools   = array_values( array_filter( $tools, static fn( $existing ) => $existing !== $ability_name ) );
		$tools[] = $tool;
	}

	$config['tools'] = $tools;

	return $config;
}

/**
 * Builds a direct tool from an ability's definition.
 *
 * @param string $ability_name `rest-api/write` or `rest-api/delete`.
 * @return McpTool|WP_Error
 */
function build_tool( string $ability_name ): McpTool|WP_Error {
	$definition  = tool_definitions()[ $ability_name ];
	$annotations = McpAnnotationMapper::map( $definition['annotations'], 'tool' );

	$annotations['title'] = $definition['label'];

	return McpTool::fromArray(
		[
			'name'        => str_replace( '/', '-', $ability_name ),
			'title'       => $definition['label'],
			'description' => $definition['description'],
			'inputSchema' => input_schema( $definition['methods'] ),
			'annotations' => $annotations,
			'handler'     => __NAMESPACE__ . '\\handle',
			'permission'  => static fn( $args ) => check_permission( $ability_name, (array) $args ),
		]
	);
}

/**
 * Checks a call against the tool's input schema, then runs the REST API
 * permission checks.
 *
 * The adapter doesn't validate direct tool arguments, so without this the
 * write tool would also send a DELETE.
 *
 * @param string $ability_name `rest-api/write` or `rest-api/delete`.
 * @param array  $args         Tool arguments.
 * @return bool|WP_Error
 */
function check_permission( string $ability_name, array $args ): bool|WP_Error {
	$schema = input_schema( tool_definitions()[ $ability_name ]['methods'] );
	$valid  = rest_validate_value_from_schema( $args, $schema, 'input' );

	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	return \HM\RestApiAbilities\check_permission( $args );
}

/**
 * Whether a call should ask the user to confirm it first.
 *
 * @param array $args Tool arguments, keyed by `method`, `route`, `params`.
 * @return bool
 */
function needs_confirmation( array $args ): bool {
	$method = strtoupper( $args['method'] ?? '' );
	$route  = $args['route'] ?? '';
	$params = $args['params'] ?? [];
	$risk   = classify_route( $route, $method, $params );

	/**
	 * Filters whether a REST API call asks the user to confirm it first, when
	 * the MCP client supports elicitation.
	 *
	 * @param bool   $needs  True for every DELETE, and for any write to a route rated above `routine`.
	 * @param string $method HTTP method.
	 * @param string $route  REST route path.
	 * @param array  $params Query or body params.
	 * @param string $risk   One of `routine`, `site-config`, `irreversible`.
	 */
	return (bool) apply_filters(
		'hm_rest_ability_needs_confirmation',
		'DELETE' === $method || 'routine' !== $risk,
		$method,
		$route,
		$params,
		$risk
	);
}

/**
 * Handles a call to the write or delete tool.
 *
 * A risky call first asks the client to confirm with the user. The client
 * then calls again with the user's answer, and the call runs only if they
 * accepted.
 *
 * @param array                   $args    Tool arguments, keyed by `method`, `route`, `params`.
 * @param McpToolCallContext|null $context Client input for this call.
 * @return array|McpInputRequired|WP_Error
 */
function handle( array $args, ?McpToolCallContext $context = null ) {
	if ( ! needs_confirmation( $args ) ) {
		return execute( $args );
	}

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
 * Builds the elicitation request that asks the user to confirm a call.
 *
 * @param array $args Tool arguments.
 * @return array
 */
function confirmation_request( array $args ): array {
	$is_delete = 'DELETE' === strtoupper( $args['method'] ?? '' );

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
						'title'       => $is_delete ? 'Yes, delete it' : 'Yes, make this change',
						'description' => 'Tick to confirm. This changes the live site.',
						'default'     => false,
					],
				],
				'required'   => [ 'confirm' ],
			],
		],
	];
}

/**
 * Describes the call for the user: what it does, where, and how risky it is.
 *
 * @param array $args Tool arguments.
 * @return string
 */
function confirmation_message( array $args ): string {
	$method = strtoupper( $args['method'] ?? '' );
	$route  = $args['route'] ?? '';
	$params = $args['params'] ?? [];
	$site   = get_bloginfo( 'name' );

	if ( 'DELETE' === $method ) {
		$label   = target_label( $route );
		$message = '' === $label
			? sprintf( 'Delete %s on %s?', $route, $site )
			: sprintf( 'Delete "%s" (%s) on %s?', $label, $route, $site );
	} else {
		$message = sprintf( 'Change %s (%s) on %s?', $route, $method, $site );
	}

	$message .= ' ' . risk_sentence( classify_route( $route, $method, $params ) );

	if ( ! empty( $params ) ) {
		$message .= ' Params: ' . wp_json_encode( $params ) . '.';
	}

	return $message;
}

/**
 * Returns one sentence telling the user what kind of change this is.
 *
 * @param string $risk One of `routine`, `site-config`, `irreversible`.
 * @return string
 */
function risk_sentence( string $risk ): string {
	switch ( $risk ) {
		case 'irreversible':
			return 'This cannot be undone.';
		case 'site-config':
			return 'This changes site settings or who has access.';
		default:
			return 'It may not be possible to undo this.';
	}
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
			'This confirmation is missing, expired, or for a different request. Nothing was changed. Call the tool again to ask the user.'
		);
	}

	$response = $context->input_responses()->{INPUT_KEY} ?? null;
	$accepted = 'accept' === ( $response->action ?? null ) && true === ( $response->content->confirm ?? null );

	if ( ! $accepted ) {
		return new WP_Error( 'rest_ability_not_confirmed', 'The user did not confirm. Nothing was changed.' );
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
