<?php
/**
 * Unit tests for inc/oauth2-discovery.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function HM\OAuth2Discovery\add_www_authenticate_header;
use function HM\OAuth2Discovery\maybe_exempt_well_known_from_login_wall;
use function HM\OAuth2Discovery\maybe_serve_well_known;

class OAuth2DiscoveryTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/oauth2-discovery.php' );
	}

	protected function tear_down(): void {
		unset( $_SERVER['REQUEST_URI'] );
		parent::tear_down();
	}

	public function test_login_wall_is_exempted_for_well_known_requests(): void {
		$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';

		Functions\when( 'apply_filters' )->alias( fn ( $tag, $value ) => $value );

		Actions\expectRemoved( 'init' )
			->once()
			->with( 'HM\\Require_Login\\redirect_user', 999 );

		maybe_exempt_well_known_from_login_wall();
	}

	public function test_login_wall_is_untouched_for_other_requests(): void {
		$_SERVER['REQUEST_URI'] = '/some-page/';

		Actions\expectRemoved( 'init' )->never();

		maybe_exempt_well_known_from_login_wall();
	}

	public function test_unmatched_well_known_path_is_ignored(): void {
		$_SERVER['REQUEST_URI'] = '/.well-known/something-else';

		// No output, no exit — if this reaches an assertion, nothing matched.
		maybe_serve_well_known();
		$this->addToAssertionCount( 1 );
	}

	public function test_www_authenticate_header_ignores_non_401_responses(): void {
		$response = new WP_REST_Response( null, 200 );
		$server   = new WP_REST_Server();
		$request  = new WP_REST_Request( 'GET', '/mcp/v1/tools' );

		$result = add_www_authenticate_header( $response, $server, $request );

		$this->assertSame( [], $result->get_headers() );
	}

	public function test_www_authenticate_header_ignores_non_mcp_routes(): void {
		$response = new WP_REST_Response( null, 401 );
		$server   = new WP_REST_Server();
		$request  = new WP_REST_Request( 'GET', '/wp/v2/posts' );

		$result = add_www_authenticate_header( $response, $server, $request );

		$this->assertSame( [], $result->get_headers() );
	}

	public function test_www_authenticate_header_is_added_for_mcp_401s(): void {
		Functions\when( 'home_url' )->justReturn( 'https://example.com/.well-known/oauth-protected-resource' );

		$response = new WP_REST_Response( null, 401 );
		$server   = new WP_REST_Server();
		$request  = new WP_REST_Request( 'GET', '/mcp/v1/tools' );

		$result = add_www_authenticate_header( $response, $server, $request );

		$this->assertSame(
			'Bearer resource_metadata="https://example.com/.well-known/oauth-protected-resource"',
			$result->get_headers()['WWW-Authenticate']
		);
	}
}
