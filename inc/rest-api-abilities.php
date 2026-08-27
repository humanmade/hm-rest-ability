<?php
/**
 * Exposes a single "call REST API" ability so MCP clients can dispatch any
 * internal WordPress REST API request without needing individual abilities
 * per endpoint.
 *
 * @package HM\RestAbility
 */

namespace HM\RestApiAbilities;

use WP_Error;
use WP_REST_Request;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_ability' );
add_filter( 'mcp_adapter_default_server_config', __NAMESPACE__ . '\\filter_mcp_server_config' );

/**
 * Registers the "REST API" ability category.
 */
function register_category(): void {
	wp_register_ability_category(
		'rest-api',
		[
			'label'       => 'REST API',
			'description' => 'WordPress REST API',
		]
	);
}

/**
 * Registers the "call REST API" ability.
 */
function register_ability(): void {
	wp_register_ability(
		'rest-api/call',
		[
			'label'               => 'Call REST API',
			'description'         => 'Execute any WordPress REST API endpoint internally. Use the index endpoint (GET /) to discover available routes and their supported methods and parameters.',
			'category'            => 'rest-api',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'method' => [
						'type'        => 'string',
						'enum'        => [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS' ],
						'description' => 'HTTP method',
					],
					'route'  => [
						'type'        => 'string',
						'description' => 'REST API route path, e.g. /wp/v2/posts or /wp/v2/posts/123',
					],
					'params' => [
						'type'                 => 'object',
						'description'          => 'Query params (GET/DELETE) or body params (POST/PUT/PATCH)',
						'additionalProperties' => true,
					],
				],
				'required'   => [ 'method', 'route' ],
			],
			'permission_callback' => __NAMESPACE__ . '\\check_permission',
			'execute_callback'    => __NAMESPACE__ . '\\execute',
			'meta'                => [
				'mcp'         => [
					'public' => true,
					'type'   => 'tool',
				],
				'annotations' => [
					'readonly'    => false,
					'destructive' => true,
					'idempotent'  => false,
				],
			],
		]
	);
}

/**
 * Checks whether the current user can perform the requested REST API call,
 * by running the matched endpoint's own permission_callback.
 *
 * @param array $input Ability input, keyed by `method`, `route`, `params`.
 * @return bool|WP_Error
 */
function check_permission( array $input ): bool|WP_Error {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'Authentication required.' );
	}

	$route  = $input['route'] ?? '';
	$method = strtoupper( $input['method'] ?? 'GET' );
	$params = $input['params'] ?? [];

	// Run the endpoint's own permission_callback to enforce its access rules.
	$server    = rest_get_server();
	$request   = build_request( $method, $route, $params );
	$endpoints = $server->get_routes();

	foreach ( $endpoints as $pattern => $handlers ) {
		if ( ! preg_match( '#^' . $pattern . '[/]*$#i', $route, $matches ) ) {
			continue;
		}
		$url_params = array_filter( $matches, 'is_string', ARRAY_FILTER_USE_KEY );
		$request->set_url_params( $url_params );
		foreach ( $handlers as $handler ) {
			if ( empty( $handler['methods'][ $method ] ) ) {
				continue;
			}
			$permission_callback = $handler['permission_callback'] ?? null;
			if ( ! $permission_callback || $permission_callback === '__return_true' ) {
				return true;
			}
			$result = call_user_func( $permission_callback, $request );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return $result !== false;
		}
	}

	// No matching route found — let the dispatch handle the 404.
	return true;
}

/**
 * Executes the requested REST API call and returns its response.
 *
 * @param array $input Ability input, keyed by `method`, `route`, `params`.
 * @return array
 */
function execute( array $input ): array {
	$method   = strtoupper( $input['method'] ?? 'GET' );
	$route    = $input['route'] ?? '';
	$params   = $input['params'] ?? [];
	$request  = build_request( $method, $route, $params );
	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [ 'error' => $response->get_error_message() ];
	}

	return [
		'status'  => $response->get_status(),
		'headers' => $response->get_headers(),
		'data'    => rest_get_server()->response_to_data( $response, false ),
	];
}

/**
 * Filters the default MCP Adapter server config to namespace it by site.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	$site_name              = sanitize_title( get_bloginfo( 'name' ) );
	$config['server_id']    = 'mcp-' . $site_name;
	$config['server_name']  = get_bloginfo( 'name' ) . ' MCP Server';
	$config['server_route'] = 'mcp-' . $site_name;
	return $config;
}

/**
 * Builds a WP_REST_Request for the given method, route and params.
 *
 * @param string $method HTTP method.
 * @param string $route  REST route path.
 * @param array  $params Query or body params.
 * @return WP_REST_Request
 */
function build_request( string $method, string $route, array $params ): WP_REST_Request {
	$request = new WP_REST_Request( $method, $route );

	if ( in_array( $method, [ 'GET', 'HEAD', 'DELETE' ], true ) ) {
		$request->set_query_params( $params );
	} else {
		$request->set_body_params( $params );
	}

	return $request;
}
