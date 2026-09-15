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
use function HM\RestApiAbilities\cap_response_data;
use function HM\RestApiAbilities\condense_routes;
use function HM\RestApiAbilities\check_permission;
use function HM\RestApiAbilities\execute;
use function HM\RestApiAbilities\filter_mcp_server_config;
use function HM\RestApiAbilities\max_response_bytes;

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

	public function test_check_permission_denies_unmatched_routes(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value ) => $value );

		$server = new WP_REST_Server();
		$server->set_routes( [] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'GET', 'route' => '/does/not/exist' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
	}

	public function test_check_permission_allows_options_on_a_matched_route(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/posts' => [
				[
					// OPTIONS is never a registered method here — core handles
					// it separately from dispatch — so only GET is listed.
					'methods'             => [ 'GET' => true ],
					'permission_callback' => static fn () => false,
				],
			],
		] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'OPTIONS', 'route' => '/wp/v2/posts' ] );

		$this->assertTrue( $result );
	}

	public function test_check_permission_denies_options_on_an_unmatched_route(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = check_permission( [ 'method' => 'OPTIONS', 'route' => '/does/not/exist' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_no_route', $result->get_error_code() );
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
		Functions\when( 'apply_filters' )->alias( static fn ( $tag, $value ) => $value );

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

	public function test_check_permission_policy_filter_never_runs_after_a_capability_denial(): void {
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

		// apply_filters is deliberately left unstubbed: if the policy filter
		// ran after a capability denial, Brain Monkey would fail this test
		// for calling an unexpected function.
		$result = check_permission( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertFalse( $result );
	}

	public function test_check_permission_applies_the_policy_filter_with_the_call_details(): void {
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

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'hm_rest_ability_policy', 'allow', 'GET', '/wp/v2/posts', [ 'per_page' => 5 ] )
			->andReturn( 'allow' );

		$result = check_permission( [
			'method' => 'GET',
			'route'  => '/wp/v2/posts',
			'params' => [ 'per_page' => 5 ],
		] );

		$this->assertTrue( $result );
	}

	public function test_check_permission_denies_when_the_policy_filter_returns_deny(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/posts' => [
				[
					'methods'             => [ 'DELETE' => true ],
					'permission_callback' => '__return_true',
				],
			],
		] );
		Functions\when( 'rest_get_server' )->justReturn( $server );
		Functions\when( 'apply_filters' )->justReturn( 'deny' );

		$result = check_permission( [ 'method' => 'DELETE', 'route' => '/wp/v2/posts' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_ability_policy_denied', $result->get_error_code() );
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

	/**
	 * Overrides the response size cap for one test, leaving every other
	 * filtered value passing straight through.
	 */
	private function set_max_response_bytes( int $bytes ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( $bytes ) {
				return 'hm_rest_ability_max_response_bytes' === $hook ? $bytes : $value;
			}
		);
	}

	public function test_max_response_bytes_is_filterable(): void {
		$this->set_max_response_bytes( 123 );

		$this->assertSame( 123, max_response_bytes() );
	}

	public function test_cap_response_data_leaves_small_payloads_alone(): void {
		$data = [ 'id' => 1, 'title' => 'Hello' ];

		$result = cap_response_data( $data );

		$this->assertSame( $data, $result['data'] );
		$this->assertArrayNotHasKey( 'truncated', $result );
	}

	public function test_cap_response_data_is_skipped_when_the_cap_is_zero(): void {
		$this->set_max_response_bytes( 0 );
		$data = [ str_repeat( 'a', 1000 ) ];

		$result = cap_response_data( $data );

		$this->assertSame( $data, $result['data'] );
		$this->assertArrayNotHasKey( 'truncated', $result );
	}

	public function test_cap_response_data_truncates_lists(): void {
		$this->set_max_response_bytes( 100 );
		$data = array_fill( 0, 50, str_repeat( 'z', 20 ) );

		$result = cap_response_data( $data );

		$this->assertLessThan( 50, count( $result['data'] ) );
		$this->assertNotEmpty( $result['data'] );
		$this->assertSame( array_slice( $data, 0, count( $result['data'] ) ), $result['data'] );
		$this->assertSame( 'response_too_large', $result['truncated']['reason'] );
		$this->assertSame( count( $result['data'] ), $result['truncated']['returned'] );
		$this->assertSame( 50, $result['truncated']['total'] );
	}

	public function test_cap_response_data_drops_the_largest_object_fields(): void {
		$this->set_max_response_bytes( 100 );
		$data = [
			'name'   => 'Test site',
			'routes' => array_fill( 0, 50, str_repeat( 'z', 20 ) ),
		];

		$result = cap_response_data( $data );

		$this->assertSame( [ 'name' => 'Test site' ], $result['data'] );
		$this->assertArrayHasKey( 'routes', $result['truncated']['omitted_fields'] );
	}

	public function test_execute_applies_fields_when_requested(): void {
		$response = new WP_REST_Response( null, 200 );

		Functions\when( 'rest_do_request' )->justReturn( $response );
		Functions\when( 'rest_get_server' )->justReturn( new WP_REST_Server() );
		Functions\expect( 'rest_filter_response_fields' )->once()->andReturn( $response );

		$result = execute( [
			'method' => 'GET',
			'route'  => '/wp/v2/posts',
			'params' => [ '_fields' => 'id,title' ],
		] );

		$this->assertSame( 200, $result['status'] );
	}

	public function test_execute_reports_truncation(): void {
		$this->set_max_response_bytes( 20 );

		$server = new WP_REST_Server();
		$server->set_response_data( array_fill( 0, 50, 'padding' ) );

		$response = new WP_REST_Response( null, 200 );
		Functions\when( 'rest_do_request' )->justReturn( $response );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = execute( [ 'method' => 'GET', 'route' => '/wp/v2/posts' ] );

		$this->assertArrayHasKey( 'truncated', $result );
		$this->assertSame( 'response_too_large', $result['truncated']['reason'] );
		$this->assertNotEmpty( $result['truncated']['hint'] );
	}

	public function test_condense_routes_keeps_paths_and_methods(): void {
		$data = [
			'name'   => 'Test site',
			'routes' => [
				'/wp/v2/posts' => [
					'namespace' => 'wp/v2',
					'methods'   => [ 'GET', 'POST' ],
					'endpoints' => [ [ 'args' => [ 'per_page' => [ 'type' => 'integer' ] ] ] ],
				],
			],
		];

		$result = condense_routes( $data );

		$this->assertSame( [ '/wp/v2/posts' => [ 'GET', 'POST' ] ], $result['routes'] );
		$this->assertSame( 'Test site', $result['name'] );
	}

	public function test_condense_routes_leaves_other_responses_alone(): void {
		$data = [ 'id' => 1, 'title' => 'Hello' ];

		$this->assertSame( $data, condense_routes( $data ) );
	}

	public function test_execute_describes_a_route_for_options(): void {
		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/posts' => [
				[ 'methods' => [ 'GET' => true, 'POST' => true ] ],
			],
		] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = execute( [ 'method' => 'OPTIONS', 'route' => '/wp/v2/posts' ] );

		$this->assertSame( 200, $result['status'] );
		$this->assertSame( [ 'GET', 'POST' ], $result['data']['methods'] );
	}

	public function test_execute_reports_an_unknown_route_for_options(): void {
		$server = new WP_REST_Server();
		$server->set_routes( [] );
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$result = execute( [ 'method' => 'OPTIONS', 'route' => '/nope' ] );

		$this->assertSame( 404, $result['status'] );
		$this->assertNotEmpty( $result['error'] );
	}

	public function test_filter_mcp_server_config_namespaces_by_site(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );

		$config = filter_mcp_server_config( [] );

		$this->assertSame( 'mcp-my-site', $config['server_id'] );
		$this->assertSame( 'My Site MCP Server', $config['server_name'] );
		$this->assertSame( 'mcp-my-site', $config['server_route'] );
	}

	public function test_filter_mcp_server_config_exposes_the_ability_as_a_tool(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );

		$config = filter_mcp_server_config( [ 'tools' => [ 'mcp-adapter/execute-ability' ] ] );

		$this->assertSame(
			[ 'mcp-adapter/execute-ability', 'rest-api/call' ],
			$config['tools']
		);
	}

	public function test_filter_mcp_server_config_does_not_duplicate_the_tool(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );

		$config = filter_mcp_server_config( [ 'tools' => [ 'rest-api/call' ] ] );

		$this->assertSame( [ 'rest-api/call' ], $config['tools'] );
	}
}
