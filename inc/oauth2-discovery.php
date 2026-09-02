<?php
/**
 * Serves OAuth 2.0 Authorization Server Metadata (RFC 8414) and OAuth
 * Protected Resource Metadata (RFC 9728) for MCP client auto-discovery.
 *
 * Serves /.well-known/oauth-authorization-server so that MCP clients like
 * Claude can auto-discover the OAuth2 endpoints provided by the WP-API/OAuth2
 * plugin.
 *
 * Also serves /.well-known/oauth-protected-resource (RFC 9728) so MCP clients
 * can discover the authorization server from a 401 on the MCP endpoint.
 *
 * Adds a WWW-Authenticate header to 401 responses on MCP REST routes so
 * clients know where to find the protected resource metadata.
 *
 * Uses parse_request to intercept early — no rewrite rule flush needed.
 *
 * @package HM\RestAbility
 */

namespace HM\OAuth2Discovery;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', __NAMESPACE__ . '\\maybe_exempt_well_known_from_login_wall', 998 );
add_action( 'parse_request', __NAMESPACE__ . '\\maybe_serve_well_known' );
add_filter( 'rest_post_dispatch', __NAMESPACE__ . '\\add_www_authenticate_header', 10, 3 );

/**
 * Removes any configured login-wall callbacks from `.well-known/` requests,
 * so unauthenticated MCP clients can reach the discovery documents.
 *
 * No-ops on sites that don't run one of the filtered plugins.
 */
function maybe_exempt_well_known_from_login_wall(): void {
	if ( strpos( $_SERVER['REQUEST_URI'] ?? '', '/.well-known/' ) !== 0 ) {
		return;
	}

	/**
	 * Filters the login-wall callbacks to remove for `.well-known/` requests.
	 *
	 * Each entry is a [ hook, function_to_remove, priority ] tuple passed to
	 * remove_action(). Defaults to Human Made's Require Login plugin.
	 *
	 * @param array $exemptions Array of remove_action() argument tuples.
	 */
	$exemptions = apply_filters( 'hm_rest_ability_login_wall_exemptions', [
		[ 'init', 'HM\\Require_Login\\redirect_user', 999 ],
	] );

	foreach ( $exemptions as [ $hook, $function_to_remove, $priority ] ) {
		remove_action( $hook, $function_to_remove, $priority );
	}
}

/**
 * Intercepts /.well-known/ requests before WordPress tries to match a
 * post/page, and serves the matching discovery document.
 */
function maybe_serve_well_known(): void {
	$document = match_well_known_path( $_SERVER['REQUEST_URI'] ?? '' );

	if ( $document === 'oauth-authorization-server' ) {
		serve_authorization_server_metadata();
	}

	if ( $document === 'oauth-protected-resource' ) {
		serve_protected_resource_metadata();
	}
}

/**
 * Works out which discovery document, if any, a request URI is asking for.
 *
 * Tolerates a trailing slash: some hosts redirect extensionless GET paths to
 * their trailing-slash form before WordPress runs, and clients following that
 * redirect must still get the document.
 *
 * @param string $request_uri Raw request URI, as in `$_SERVER['REQUEST_URI']`.
 * @return string|null `oauth-authorization-server`, `oauth-protected-resource`, or null.
 */
function match_well_known_path( string $request_uri ): ?string {
	$path = rtrim( (string) parse_url( $request_uri, PHP_URL_PATH ), '/' );

	if ( $path === '/.well-known/oauth-authorization-server' ) {
		return 'oauth-authorization-server';
	}

	if ( $path === '/.well-known/oauth-protected-resource' ) {
		return 'oauth-protected-resource';
	}

	return null;
}

/**
 * Outputs the RFC 8414 authorization server metadata document and exits.
 */
function serve_authorization_server_metadata(): void {
	$metadata = [
		'issuer'                                => home_url(),
		'authorization_endpoint'                => rest_url( 'oauth2/authorize' ),
		'token_endpoint'                        => rest_url( 'oauth2/access_token' ),
		'grant_types_supported'                 => [ 'authorization_code' ],
		'response_types_supported'              => [ 'code' ],
		'token_endpoint_auth_methods_supported' => [ 'none', 'client_secret_post', 'client_secret_basic' ],
		'scopes_supported'                      => [ 'basic' ],
		'code_challenge_methods_supported'      => [ 'S256' ],
	];

	/**
	 * Filters the OAuth2 server metadata returned at
	 * /.well-known/oauth-authorization-server.
	 *
	 * @param array $metadata RFC 8414 metadata document.
	 */
	$metadata = apply_filters( 'hm_oauth2_discovery_metadata', $metadata );

	header( 'Content-Type: application/json' );
	header( 'Access-Control-Allow-Origin: *' );
	echo wp_json_encode( $metadata );
	exit;
}

/**
 * Outputs the RFC 9728 protected resource metadata document and exits.
 */
function serve_protected_resource_metadata(): void {
	$metadata = [
		'resource'              => home_url(),
		'authorization_servers' => [ home_url() ],
	];

	/**
	 * Filters the OAuth2 protected resource metadata returned at
	 * /.well-known/oauth-protected-resource.
	 *
	 * @param array $metadata RFC 9728 metadata document.
	 */
	$metadata = apply_filters( 'hm_oauth2_protected_resource_metadata', $metadata );

	header( 'Content-Type: application/json' );
	header( 'Access-Control-Allow-Origin: *' );
	echo wp_json_encode( $metadata );
	exit;
}

/**
 * Adds a WWW-Authenticate header to 401 responses on MCP REST routes.
 *
 * @param \WP_REST_Response $response Result to send to the client.
 * @param \WP_REST_Server   $server   Server instance.
 * @param \WP_REST_Request  $request  Request used to generate the response.
 * @return \WP_REST_Response
 */
function add_www_authenticate_header( \WP_REST_Response $response, \WP_REST_Server $server, \WP_REST_Request $request ): \WP_REST_Response {
	if ( $response->get_status() !== 401 ) {
		return $response;
	}

	if ( strpos( $request->get_route(), '/mcp/' ) === false ) {
		return $response;
	}

	$resource_metadata_url = home_url( '/.well-known/oauth-protected-resource' );
	$response->header( 'WWW-Authenticate', "Bearer resource_metadata=\"{$resource_metadata_url}\"" );

	return $response;
}
