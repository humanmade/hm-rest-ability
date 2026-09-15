<?php
/**
 * Exposes the WordPress REST API as three abilities — read, write, delete —
 * so MCP clients can dispatch any internal REST API request without needing
 * individual abilities per endpoint, while still being able to gate each kind
 * of request separately.
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

/**
 * Default maximum size, in bytes, of the JSON-encoded response data returned
 * for a single call.
 */
const MAX_RESPONSE_BYTES = 50000;

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
 * Returns the definition of each REST API ability, keyed by ability name.
 *
 * One shared schema shape, permission callback, and execute callback serve
 * all three abilities; only the allowed methods, description, and MCP
 * annotations differ per tool.
 *
 * @return array<string, array{label: string, methods: string[], description: string, annotations: array}>
 */
function tool_definitions(): array {
	$max_bytes = max_response_bytes();

	return [
		'rest-api/read'   => [
			'label'       => 'Read REST API',
			'methods'     => [ 'GET', 'OPTIONS' ],
			'description' => sprintf(
				'Read any WordPress REST API endpoint internally, or inspect a route\'s parameters with OPTIONS. Call GET / for a list of every route and the methods it accepts, then OPTIONS on one route for its parameters. Responses are capped at %d bytes and trimmed when they exceed it, so narrow them with _fields, per_page, or a more specific route.',
				$max_bytes
			),
			'annotations' => [
				'readonly'      => true,
				'destructive'   => false,
				'idempotent'    => true,
				'openWorldHint' => true,
			],
		],
		'rest-api/write'  => [
			'label'       => 'Write REST API',
			'methods'     => [ 'POST', 'PUT', 'PATCH' ],
			'description' => sprintf(
				'Create or update data through any WordPress REST API endpoint internally. Use rest-api/read first to find the route and its parameters — changes take effect on the live site immediately. Responses are capped at %d bytes and trimmed when they exceed it, so narrow them with _fields.',
				$max_bytes
			),
			'annotations' => [
				'readonly'      => false,
				'destructive'   => false,
				'idempotent'    => false,
				'openWorldHint' => true,
			],
		],
		'rest-api/delete' => [
			'label'       => 'Delete REST API',
			'methods'     => [ 'DELETE' ],
			'description' => sprintf(
				'Delete data through any WordPress REST API endpoint internally. Use rest-api/read first to confirm what a route holds — deletions take effect on the live site immediately and may not be reversible. Responses are capped at %d bytes and trimmed when they exceed it.',
				$max_bytes
			),
			'annotations' => [
				'readonly'      => false,
				'destructive'   => true,
				'idempotent'    => false,
				'openWorldHint' => true,
			],
		],
	];
}

/**
 * Registers the REST API read, write, and delete abilities.
 */
function register_ability(): void {
	foreach ( tool_definitions() as $name => $tool ) {
		wp_register_ability(
			$name,
			[
				'label'               => $tool['label'],
				'description'         => $tool['description'],
				'category'            => 'rest-api',
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'method' => [
							'type'        => 'string',
							'enum'        => $tool['methods'],
							'description' => 'HTTP method',
						],
						'route'  => [
							'type'        => 'string',
							'description' => 'REST API route path, e.g. /wp/v2/posts or /wp/v2/posts/123',
						],
						'params' => [
							'type'                 => 'object',
							'description'          => 'Query params (GET/DELETE) or body params (POST/PUT/PATCH). Pass _fields to limit which fields come back.',
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
					'annotations' => $tool['annotations'],
				],
			]
		);
	}
}

/**
 * Checks whether the current user can perform the requested REST API call.
 *
 * Runs the matched endpoint's own permission_callback first, then gives site
 * policy a chance to narrow that further.
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

	$allowed = endpoint_permission( $method, $route, $params );

	if ( true !== $allowed ) {
		return $allowed;
	}

	return apply_policy( $method, $route, $params );
}

/**
 * Checks whether the matched endpoint's own permission_callback allows the
 * requested REST API call.
 *
 * @param string $method HTTP method.
 * @param string $route  REST route path.
 * @param array  $params Query or body params.
 * @return bool|WP_Error
 */
function endpoint_permission( string $method, string $route, array $params ): bool|WP_Error {
	$server    = rest_get_server();
	$request   = build_request( $method, $route, $params );
	$endpoints = $server->get_routes();

	foreach ( $endpoints as $pattern => $handlers ) {
		if ( ! preg_match( '#^' . $pattern . '[/]*$#i', $route, $matches ) ) {
			continue;
		}

		// OPTIONS only reads route metadata via describe_route(); no handler
		// registers it as a method, so matching the route is enough.
		if ( 'OPTIONS' === $method ) {
			return true;
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

	// No matching route found, so there's no permission_callback to defer to.
	return new WP_Error( 'rest_no_route', 'No route matches the given path.', [ 'status' => 404 ] );
}

/**
 * Gives site policy a chance to deny a REST API call the matched endpoint's
 * own permission_callback has already allowed.
 *
 * @param string $method HTTP method.
 * @param string $route  REST route path.
 * @param array  $params Query or body params.
 * @return bool|WP_Error
 */
function apply_policy( string $method, string $route, array $params ): bool|WP_Error {
	/**
	 * Filters whether a REST API call is allowed by site policy.
	 *
	 * Runs only once the matched endpoint's own permission_callback has
	 * already allowed the call, so a hooked callback can narrow access but
	 * never grant access a user's capabilities would not otherwise allow.
	 * Nothing is denied by default.
	 *
	 * @param string $decision 'allow' (default) or 'deny'.
	 * @param string $method   HTTP method.
	 * @param string $route    REST route path.
	 * @param array  $params   Query or body params.
	 */
	$decision = apply_filters( 'hm_rest_ability_policy', 'allow', $method, $route, $params );

	if ( 'deny' === $decision ) {
		return new WP_Error( 'rest_ability_policy_denied', 'This action is blocked by site policy.' );
	}

	return true;
}

/**
 * Executes the requested REST API call and returns its response.
 *
 * @param array $input Ability input, keyed by `method`, `route`, `params`.
 * @return array
 */
function execute( array $input ): array {
	$method = strtoupper( $input['method'] ?? 'GET' );
	$route  = $input['route'] ?? '';
	$params = $input['params'] ?? [];

	if ( 'OPTIONS' === $method ) {
		return describe_route( $route );
	}

	$request  = build_request( $method, $route, $params );
	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [ 'error' => $response->get_error_message() ];
	}

	// rest_do_request() dispatches without the `rest_post_dispatch` filter
	// that normally applies `_fields`, so apply it here.
	if ( isset( $params['_fields'] ) ) {
		$response = rest_filter_response_fields( $response, rest_get_server(), $request );
	}

	$data   = rest_get_server()->response_to_data( $response, false );
	$capped = cap_response_data( condense_routes( $data ) );

	$result = [
		'status'  => $response->get_status(),
		'headers' => $response->get_headers(),
		'data'    => $capped['data'],
	];

	if ( isset( $capped['truncated'] ) ) {
		$result['truncated'] = $capped['truncated'];
	}

	return $result;
}

/**
 * Returns one route's supported methods and parameters.
 *
 * Core answers OPTIONS in WP_REST_Server::serve_request(), which
 * rest_do_request() never reaches, so this builds the same description from
 * the route table directly.
 *
 * @param string $route REST route path.
 * @return array
 */
function describe_route( string $route ): array {
	$server = rest_get_server();

	foreach ( $server->get_routes() as $pattern => $handlers ) {
		if ( ! preg_match( '#^' . $pattern . '[/]*$#i', $route ) ) {
			continue;
		}

		$capped = cap_response_data( $server->get_data_for_route( $pattern, $handlers, 'help' ) );
		$result = [
			'status' => 200,
			'data'   => $capped['data'],
		];

		if ( isset( $capped['truncated'] ) ) {
			$result['truncated'] = $capped['truncated'];
		}

		return $result;
	}

	return [
		'status' => 404,
		'error'  => sprintf( 'No route matches %s.', $route ),
	];
}

/**
 * Reduces an index response's routes to a path => methods map.
 *
 * The full index carries every parameter of every route, which is around a
 * megabyte on a stock site. Trimming that by size drops the route list
 * altogether, which is the one part a client actually needs. Keeping the paths
 * and methods costs a few kilobytes, and OPTIONS fills in the detail for
 * whichever route the client picks.
 *
 * @param mixed $data Response data.
 * @return mixed
 */
function condense_routes( $data ) {
	if ( ! is_array( $data ) || empty( $data['routes'] ) || ! is_array( $data['routes'] ) ) {
		return $data;
	}

	$condensed = [];

	foreach ( $data['routes'] as $path => $descriptor ) {
		$condensed[ $path ] = $descriptor['methods'] ?? [];
	}

	$data['routes'] = $condensed;

	return $data;
}

/**
 * Returns the maximum size, in bytes, of the response data for one call.
 *
 * @return int
 */
function max_response_bytes(): int {
	/**
	 * Filters the maximum size, in bytes, of the response data returned for a
	 * single read, write, or delete call. Zero or less disables trimming.
	 *
	 * @param int $max_bytes Maximum response size in bytes.
	 */
	return (int) apply_filters( 'hm_rest_ability_max_response_bytes', MAX_RESPONSE_BYTES );
}

/**
 * Trims response data down to the maximum response size.
 *
 * Lists keep as many leading items as fit. Objects keep their smallest fields
 * and name the ones left out. Strings are cut short. Anything within the limit
 * is returned untouched.
 *
 * @param mixed $data Response data.
 * @return array Keyed by `data`, plus `truncated` when something was removed.
 */
function cap_response_data( $data ): array {
	$max_bytes = max_response_bytes();

	if ( $max_bytes <= 0 || encoded_size( $data ) <= $max_bytes ) {
		return [ 'data' => $data ];
	}

	$truncated = [
		'reason'    => 'response_too_large',
		'max_bytes' => $max_bytes,
		'hint'      => 'Narrow the response with _fields, per_page, or a more specific route.',
	];

	if ( is_string( $data ) ) {
		return [
			'data'      => substr( $data, 0, $max_bytes ),
			'truncated' => $truncated,
		];
	}

	if ( ! is_array( $data ) ) {
		return [ 'data' => $data ];
	}

	if ( wp_is_numeric_array( $data ) ) {
		$kept = [];
		$size = 2;

		foreach ( $data as $item ) {
			$item_size = encoded_size( $item ) + 1;
			if ( $size + $item_size > $max_bytes ) {
				break;
			}
			$kept[] = $item;
			$size  += $item_size;
		}

		$truncated['returned'] = count( $kept );
		$truncated['total']    = count( $data );

		return [
			'data'      => $kept,
			'truncated' => $truncated,
		];
	}

	$field_sizes = [];
	foreach ( $data as $key => $value ) {
		$field_sizes[ $key ] = encoded_size( $value ) + strlen( (string) $key ) + 4;
	}
	asort( $field_sizes );

	$kept    = [];
	$omitted = [];
	$size    = 2;

	foreach ( $field_sizes as $key => $field_size ) {
		if ( $size + $field_size <= $max_bytes ) {
			$kept[ $key ] = true;
			$size        += $field_size;
			continue;
		}
		$omitted[ $key ] = $field_size;
	}

	$truncated['omitted_fields'] = $omitted;

	return [
		// array_intersect_key() against the original keeps the field order.
		'data'      => array_intersect_key( $data, $kept ),
		'truncated' => $truncated,
	];
}

/**
 * Returns the size, in bytes, of a value once JSON-encoded.
 *
 * @param mixed $value Value to measure.
 * @return int
 */
function encoded_size( $value ): int {
	$encoded = wp_json_encode( $value );

	return false === $encoded ? 0 : strlen( $encoded );
}

/**
 * Filters the default MCP Adapter server config to namespace it by site, and
 * to expose these abilities as tools in their own right.
 *
 * Without this the abilities are only reachable through the adapter's generic
 * `execute-ability` tool, which hides their input schemas behind a generic one.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	$site_name              = sanitize_title( get_bloginfo( 'name' ) );
	$config['server_id']    = 'mcp-' . $site_name;
	$config['server_name']  = get_bloginfo( 'name' ) . ' MCP Server';
	$config['server_route'] = 'mcp-' . $site_name;

	$tools           = array_merge( $config['tools'] ?? [], array_keys( tool_definitions() ) );
	$config['tools'] = array_values( array_unique( $tools ) );

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
