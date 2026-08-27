<?php
/**
 * Unit tests for inc/rest-api-abilities.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Response;
use WP_REST_Server;

use function HM\RestApiAbilities\build_request;
use function HM\RestApiAbilities\check_permission;
use function HM\RestApiAbilities\execute;
use function HM\RestApiAbilities\filter_mcp_server_config;

class RestApiAbilitiesTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/rest-api-abilities.php' );
	}

	public function test_check_permission_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = check_permission( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
	}

	public function test_check_permission_allows_unmatched_routes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'GET', 'route' => '/does/not/exist' ] );

		$this->assertTrue( $result );
	}

	public function test_check_permission_runs_the_route_permission_callback(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/posts' => [
				[
					'methods'             => [ 'GET' => true ],
					'permission_callback' => static fn () => false,
				],
			],
		] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertFalse( $result );
	}

	public function test_check_permission_allows_return_true_shortcut(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/posts' => [
				[
					'methods'             => [ 'GET' => true ],
					'permission_callback' => '__return_true',
				],
			],
		] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertTrue( $result );
	}

	public function test_build_request_sets_query_params_for_get(): void {
		$request = build_request( 'GET', '/wp/v2/posts', [ 'per_page' => 5 ] );

		$this->assertSame( [ 'per_page' => 5 ], $request->get_query_params() );
		$this->assertSame( [], $request->get_body_params() );
	}

	public function test_build_request_sets_body_params_for_post(): void {
		$request = build_request( 'POST', '/wp/v2/posts', [ 'title' => 'Hello' ] );

		$this->assertSame( [ 'title' => 'Hello' ], $request->get_body_params() );
		$this->assertSame( [], $request->get_query_params() );
	}

	public function test_execute_returns_error_message_for_wp_errors(): void {
		Functions\when( 'rest_do_request' )->justReturn( new WP_Error( 'rest_no_route', 'No route found.' ) );

		$result = execute( [ 'method' => 'GET', 'route' => '/nope' ] );

		$this->assertSame( [ 'error' => 'No route found.' ], $result );
	}

	public function test_execute_returns_status_headers_and_data(): void {
		$response = new WP_REST_Response( null, 200 );
		$response->header( 'X-Test', 'yes' );

		Functions\when( 'rest_do_request' )->justReturn( $response );

		$server = new WP_REST_Server();
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = execute( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( [ 'X-Test' => 'yes' ], $result['headers'] );
		$this->assertSame( [ 'status' => 200 ], $result['data'] );
	}

	public function test_filter_mcp_server_config_namespaces_by_site(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );

		$config = filter_mcp_server_config( [] );

		$this->assertSame( 'mcp-my-site', $config['server_id'] );
		$this->assertSame( 'My Site MCP Server', $config['server_name'] );
		$this->assertSame( 'mcp-my-site', $config['server_route'] );
	}
}
