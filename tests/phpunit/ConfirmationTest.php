<?php
/**
 * Unit tests for inc/confirmation.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use WP\MCP\Core\McpRequestContext;
use WP\MCP\Domain\Tools\McpInputRequired;
use WP\MCP\Domain\Tools\McpTool;
use WP\MCP\Domain\Tools\McpToolCallContext;
use WP\McpSchema\Schemas;
use WP_Error;
use WP_REST_Response;

use function HM\Confirmation\check_permission;
use function HM\Confirmation\confirmation_message;
use function HM\Confirmation\filter_mcp_server_config;
use function HM\Confirmation\handle;
use function HM\Confirmation\hide_ability_from_mcp;
use function HM\Confirmation\needs_confirmation;
use function HM\Confirmation\sign_state;
use function HM\Confirmation\verify_state;

class ConfirmationTest extends TestCase {

	private const ARGS = [
		'method' => 'DELETE',
		'route'  => '/wp/v2/posts/12',
		'params' => [ 'force' => true ],
	];

	private const ROUTINE_ARGS = [
		'method' => 'POST',
		'route'  => '/wp/v2/posts/1',
		'params' => [ 'title' => 'New title' ],
	];

	private const SETTINGS_ARGS = [
		'method' => 'POST',
		'route'  => '/wp/v2/settings',
		'params' => [ 'title' => 'New site title' ],
	];

	/**
	 * Requests passed to the stubbed rest_do_request(), in order.
	 *
	 * @var array
	 */
	private array $requests = [];

	/**
	 * Value the stubbed hm_rest_ability_needs_confirmation filter returns, or
	 * null to leave the default alone.
	 */
	private ?bool $needs_override = null;

	/**
	 * ID of the user the stubbed get_current_user_id() returns.
	 */
	private int $user_id = 7;

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-risk.php' );
		$this->load_plugin_file( 'inc/route-suggestions.php' );
		$this->load_plugin_file( 'inc/rest-api-abilities.php' );
		$this->load_plugin_file( 'inc/confirmation.php' );

		$this->requests = [];
		$this->user_id  = 7;

		$this->needs_override = null;

		Functions\when( 'apply_filters' )->alias(
			fn( $tag, $value ) => 'hm_rest_ability_needs_confirmation' === $tag && null !== $this->needs_override ? $this->needs_override : $value
		);
		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user_id );
		Functions\when( 'rest_get_server' )->justReturn( new \WP_REST_Server() );
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'rest_validate_value_from_schema' )->alias(
			fn( $value, $schema ) => in_array( $value['method'] ?? null, $schema['properties']['method']['enum'], true )
				? true
				: new WP_Error( 'rest_not_in_enum', 'method is not one of the allowed values.' )
		);
		Functions\when( 'rest_do_request' )->alias(
			function ( $request ) {
				$this->requests[] = $request;

				return 'GET' === $request->get_method()
					? new WP_REST_Response( [ 'title' => [ 'rendered' => 'Hello &amp; welcome' ] ], 200 )
					: new WP_REST_Response( null, 200 );
			}
		);
	}

	/**
	 * Builds the context the adapter passes to a tool handler.
	 *
	 * @param string      $revision     MCP revision of the request.
	 * @param array       $capabilities Client capabilities.
	 * @param array       $responses    Input responses keyed by request key, or null for a first call.
	 * @param string|null $state        Request state sent back by the client.
	 */
	private function context( string $revision, array $capabilities, ?array $responses = null, ?string $state = null ): McpToolCallContext {
		$schema  = Schemas::create()->forVersion( $revision );
		$request = new McpRequestContext( $schema, json_decode( wp_json_encode( (object) $capabilities ) ), null, 'http' );

		return new McpToolCallContext(
			$request,
			json_decode( wp_json_encode( (object) ( $responses ?? [] ) ) ),
			$state,
			null !== $responses || null !== $state
		);
	}

	private function elicitation_context( ?array $responses = null, ?string $state = null ): McpToolCallContext {
		return $this->context( Schemas::V2026_07_28, [ 'elicitation' => [ 'form' => (object) [] ] ], $responses, $state );
	}

	private function accept( bool $confirm = true ): array {
		return [
			'confirm' => [
				'action'  => 'accept',
				'content' => [ 'confirm' => $confirm ],
			],
		];
	}

	private function valid_state( array $args = self::ARGS ): string {
		return sign_state( $args, time() + 300 );
	}

	/**
	 * Returns the methods of the requests that changed something.
	 *
	 * @return string[]
	 */
	private function writes(): array {
		return array_values(
			array_filter(
				array_map( fn( $request ) => $request->get_method(), $this->requests ),
				fn( $method ) => 'GET' !== $method
			)
		);
	}

	private function assert_refused( $result ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( [], $this->writes() );
	}

	public function test_handle_asks_for_confirmation_when_the_client_supports_elicitation(): void {
		$result = handle( self::ARGS, $this->elicitation_context() );

		$this->assertInstanceOf( McpInputRequired::class, $result );
		$this->assertSame( [], $this->writes() );

		$requests = $result->input_requests();
		$this->assertSame( [ 'confirm' ], array_keys( $requests ) );
		$this->assertSame( 'elicitation/create', $requests['confirm']['method'] );
		$this->assertSame( 'form', $requests['confirm']['params']['mode'] );
		$this->assertStringContainsString( 'Hello & welcome', $requests['confirm']['params']['message'] );
		$this->assertTrue( verify_state( $result->request_state(), self::ARGS ) );
	}

	public function test_handle_deletes_straight_away_without_the_elicitation_capability(): void {
		$result = handle( self::ARGS, $this->context( Schemas::V2026_07_28, [] ) );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'DELETE' ], $this->writes() );
	}

	public function test_handle_deletes_straight_away_on_the_2025_revision(): void {
		$result = handle( self::ARGS, $this->context( Schemas::V2025_11_25, [ 'elicitation' => [ 'form' => (object) [] ] ] ) );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'DELETE' ], $this->writes() );
	}

	public function test_handle_deletes_straight_away_without_a_context(): void {
		$result = handle( self::ARGS );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'DELETE' ], $this->writes() );
	}

	public function test_handle_deletes_when_the_user_accepted(): void {
		$result = handle( self::ARGS, $this->elicitation_context( $this->accept(), $this->valid_state() ) );

		$this->assertIsArray( $result );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( [ 'DELETE' ], $this->writes() );
	}

	public function test_handle_refuses_when_the_user_declined(): void {
		$responses = [ 'confirm' => [ 'action' => 'decline' ] ];

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $responses, $this->valid_state() ) ) );
	}

	public function test_handle_refuses_when_the_user_cancelled(): void {
		$responses = [ 'confirm' => [ 'action' => 'cancel' ] ];

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $responses, $this->valid_state() ) ) );
	}

	public function test_handle_refuses_when_the_user_accepted_without_confirming(): void {
		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept( false ), $this->valid_state() ) ) );
	}

	public function test_handle_refuses_when_the_answer_is_missing(): void {
		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( [], $this->valid_state() ) ) );
	}

	public function test_handle_refuses_state_signed_for_another_route(): void {
		$state = $this->valid_state( [ 'route' => '/wp/v2/posts/13' ] + self::ARGS );

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_state_signed_for_other_params(): void {
		$state = $this->valid_state( [ 'params' => [ 'force' => false ] ] + self::ARGS );

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_state_signed_for_another_user(): void {
		$state         = $this->valid_state();
		$this->user_id = 8;

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_expired_state(): void {
		$state = sign_state( self::ARGS, time() - 1 );

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_state_with_a_tampered_signature(): void {
		[ $expires, $hash ] = explode( '.', $this->valid_state() );
		$state              = $expires . '.' . strrev( $hash );

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_state_with_a_later_expiry(): void {
		[ $expires, $hash ] = explode( '.', $this->valid_state() );
		$state              = ( (int) $expires + 3600 ) . '.' . $hash;

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept(), $state ) ) );
	}

	public function test_handle_refuses_missing_state(): void {
		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $this->accept() ) ) );
	}

	public function test_handle_writes_straight_away_for_a_routine_write_on_an_elicitation_client(): void {
		$result = handle( self::ROUTINE_ARGS, $this->elicitation_context() );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'POST' ], $this->writes() );
	}

	public function test_handle_asks_for_confirmation_before_a_site_config_write(): void {
		$result = handle( self::SETTINGS_ARGS, $this->elicitation_context() );

		$this->assertInstanceOf( McpInputRequired::class, $result );
		$this->assertSame( [], $this->writes() );

		$request = $result->input_requests()['confirm'];
		$message = $request['params']['message'];
		$this->assertStringContainsString( 'Change /wp/v2/settings (POST) on My Site?', $message );
		$this->assertStringContainsString( 'This changes site settings or who has access.', $message );
		$this->assertStringContainsString( '{"title":"New site title"}', $message );
		$this->assertSame( 'Yes, make this change', $request['params']['requestedSchema']['properties']['confirm']['title'] );
		$this->assertSame( [], array_filter( $this->requests, fn( $request ) => 'GET' === $request->get_method() ) );
		$this->assertTrue( verify_state( $result->request_state(), self::SETTINGS_ARGS ) );
	}

	public function test_handle_runs_a_site_config_write_when_the_user_accepted(): void {
		$state  = $this->valid_state( self::SETTINGS_ARGS );
		$result = handle( self::SETTINGS_ARGS, $this->elicitation_context( $this->accept(), $state ) );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'POST' ], $this->writes() );
	}

	public function test_handle_refuses_a_site_config_write_when_the_user_declined(): void {
		$responses = [ 'confirm' => [ 'action' => 'decline' ] ];
		$state     = $this->valid_state( self::SETTINGS_ARGS );

		$this->assert_refused( handle( self::SETTINGS_ARGS, $this->elicitation_context( $responses, $state ) ) );
	}

	public function test_handle_uses_the_delete_wording_for_a_delete(): void {
		$request = handle( self::ARGS, $this->elicitation_context() )->input_requests()['confirm'];

		$this->assertSame( 'Yes, delete it', $request['params']['requestedSchema']['properties']['confirm']['title'] );
	}

	public function test_refusals_use_the_documented_error_codes(): void {
		$declined = handle( self::ARGS, $this->elicitation_context( [ 'confirm' => [ 'action' => 'decline' ] ], $this->valid_state() ) );
		$missing  = handle( self::ARGS, $this->elicitation_context( $this->accept() ) );

		$this->assertSame( 'rest_ability_not_confirmed', $declined->get_error_code() );
		$this->assertSame( 'rest_ability_confirmation_invalid', $missing->get_error_code() );
		$this->assertStringContainsString( 'Nothing was changed', $declined->get_error_message() );
		$this->assertStringContainsString( 'Nothing was changed', $missing->get_error_message() );
	}

	public function test_needs_confirmation_covers_deletes_and_risky_writes_only(): void {
		$this->assertTrue( needs_confirmation( self::ARGS ) );
		$this->assertTrue( needs_confirmation( [ 'method' => 'delete', 'route' => '/wp/v2/posts/12' ] ) );
		$this->assertTrue( needs_confirmation( self::SETTINGS_ARGS ) );
		$this->assertFalse( needs_confirmation( self::ROUTINE_ARGS ) );
	}

	public function test_the_needs_confirmation_filter_can_turn_confirmation_on_for_a_routine_write(): void {
		$this->needs_override = true;

		$result = handle( self::ROUTINE_ARGS, $this->elicitation_context() );

		$this->assertInstanceOf( McpInputRequired::class, $result );
		$this->assertSame( [], $this->writes() );
	}

	public function test_the_needs_confirmation_filter_can_turn_confirmation_off_for_a_delete(): void {
		$this->needs_override = false;

		$result = handle( self::ARGS, $this->elicitation_context() );

		$this->assertIsArray( $result );
		$this->assertSame( [ 'DELETE' ], $this->writes() );
	}

	public function test_the_needs_confirmation_filter_receives_the_call_and_its_risk(): void {
		$seen = [];

		Functions\when( 'apply_filters' )->alias(
			function ( $tag, ...$args ) use ( &$seen ) {
				if ( 'hm_rest_ability_needs_confirmation' === $tag ) {
					$seen = $args;
				}

				return $args[0];
			}
		);

		needs_confirmation( self::SETTINGS_ARGS );

		$this->assertSame( [ true, 'POST', '/wp/v2/settings', [ 'title' => 'New site title' ], 'site-config' ], $seen );
	}

	public function test_check_permission_refuses_a_delete_on_the_write_tool(): void {
		$result = check_permission( 'rest-api/write', self::ARGS );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_in_enum', $result->get_error_code() );
	}

	public function test_check_permission_refuses_a_post_on_the_delete_tool(): void {
		$result = check_permission( 'rest-api/delete', self::ROUTINE_ARGS );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_in_enum', $result->get_error_code() );
	}

	public function test_check_permission_passes_valid_args_to_the_rest_api_permission_check(): void {
		$server = new \WP_REST_Server();
		$server->set_routes(
			[
				'/wp/v2/posts/(?P<id>[\d]+)' => [
					[
						'methods'             => [
							'POST'   => true,
							'DELETE' => true,
						],
						'permission_callback' => static fn() => true,
					],
				],
			]
		);
		Functions\when( 'rest_get_server' )->justReturn( $server );

		$this->assertTrue( check_permission( 'rest-api/write', self::ROUTINE_ARGS ) );
		$this->assertTrue( check_permission( 'rest-api/delete', self::ARGS ) );

		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = check_permission( 'rest-api/write', self::ROUTINE_ARGS );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
	}

	public function test_filter_mcp_server_config_swaps_in_the_direct_tools(): void {
		$config = filter_mcp_server_config(
			[ 'tools' => [ 'mcp-adapter/execute-ability', 'rest-api/read', 'rest-api/write', 'rest-api/delete' ] ]
		);

		$this->assertCount( 4, $config['tools'] );
		$this->assertSame( [ 'mcp-adapter/execute-ability', 'rest-api/read' ], array_slice( $config['tools'], 0, 2 ) );
		$this->assertInstanceOf( McpTool::class, $config['tools'][2] );
		$this->assertInstanceOf( McpTool::class, $config['tools'][3] );
		$this->assertSame( 'rest-api-write', $config['tools'][2]->get_name() );
		$this->assertSame( 'rest-api-delete', $config['tools'][3]->get_name() );
		$this->assertNotContains( 'rest-api/write', $config['tools'] );
		$this->assertNotContains( 'rest-api/delete', $config['tools'] );
	}

	public function test_filter_mcp_server_config_adds_the_direct_tools_when_the_config_has_no_tools(): void {
		$config = filter_mcp_server_config( [] );

		$this->assertCount( 2, $config['tools'] );
		$this->assertSame( 'rest-api-write', $config['tools'][0]->get_name() );
		$this->assertSame( 'rest-api-delete', $config['tools'][1]->get_name() );
	}

	public function test_hide_ability_from_mcp_hides_the_write_and_delete_abilities_only(): void {
		$args = [ 'meta' => [ 'mcp' => [ 'public' => true ] ] ];

		$this->assertFalse( hide_ability_from_mcp( $args, 'rest-api/delete' )['meta']['mcp']['public'] );
		$this->assertFalse( hide_ability_from_mcp( $args, 'rest-api/write' )['meta']['mcp']['public'] );
		$this->assertSame( $args, hide_ability_from_mcp( $args, 'rest-api/read' ) );
	}

	public function test_confirmation_message_names_the_item_title(): void {
		$message = confirmation_message( self::ARGS );

		$this->assertStringContainsString( 'Delete "Hello & welcome" (/wp/v2/posts/12) on My Site?', $message );
		$this->assertStringContainsString( 'This cannot be undone.', $message );
		$this->assertStringContainsString( 'Params: {"force":true}.', $message );
	}

	public function test_confirmation_message_falls_back_to_the_route(): void {
		Functions\when( 'rest_do_request' )->justReturn( new WP_REST_Response( null, 404 ) );

		$message = confirmation_message( [ 'method' => 'DELETE', 'route' => '/wp/v2/posts/12' ] );

		$this->assertSame( 'Delete /wp/v2/posts/12 on My Site? It may not be possible to undo this.', $message );
	}

	public function test_confirmation_message_for_a_write_skips_the_title_lookup(): void {
		$message = confirmation_message( self::ROUTINE_ARGS );

		$this->assertSame( 'Change /wp/v2/posts/1 (POST) on My Site? It may not be possible to undo this. Params: {"title":"New title"}.', $message );
		$this->assertSame( [], $this->requests );
	}
}
