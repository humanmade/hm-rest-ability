<?php
/**
 * Unit tests for inc/delete-confirmation.php.
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

use function HM\DeleteConfirmation\confirmation_message;
use function HM\DeleteConfirmation\filter_mcp_server_config;
use function HM\DeleteConfirmation\handle;
use function HM\DeleteConfirmation\hide_ability_from_mcp;
use function HM\DeleteConfirmation\sign_state;
use function HM\DeleteConfirmation\verify_state;

class DeleteConfirmationTest extends TestCase {

	private const ARGS = [
		'method' => 'DELETE',
		'route'  => '/wp/v2/posts/12',
		'params' => [ 'force' => true ],
	];

	/**
	 * Requests passed to the stubbed rest_do_request(), in order.
	 *
	 * @var array
	 */
	private array $requests = [];

	/**
	 * ID of the user the stubbed get_current_user_id() returns.
	 */
	private int $user_id = 7;

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-risk.php' );
		$this->load_plugin_file( 'inc/route-suggestions.php' );
		$this->load_plugin_file( 'inc/rest-api-abilities.php' );
		$this->load_plugin_file( 'inc/delete-confirmation.php' );

		$this->requests = [];
		$this->user_id  = 7;

		Functions\when( 'wp_salt' )->justReturn( 'test-salt' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'get_bloginfo' )->justReturn( 'My Site' );
		Functions\when( 'sanitize_title' )->justReturn( 'my-site' );
		Functions\when( 'get_current_user_id' )->alias( fn() => $this->user_id );
		Functions\when( 'rest_get_server' )->justReturn( new \WP_REST_Server() );
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
			'confirm_delete' => [
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
		$this->assertSame( [ 'confirm_delete' ], array_keys( $requests ) );
		$this->assertSame( 'elicitation/create', $requests['confirm_delete']['method'] );
		$this->assertSame( 'form', $requests['confirm_delete']['params']['mode'] );
		$this->assertStringContainsString( 'Hello & welcome', $requests['confirm_delete']['params']['message'] );
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
		$responses = [ 'confirm_delete' => [ 'action' => 'decline' ] ];

		$this->assert_refused( handle( self::ARGS, $this->elicitation_context( $responses, $this->valid_state() ) ) );
	}

	public function test_handle_refuses_when_the_user_cancelled(): void {
		$responses = [ 'confirm_delete' => [ 'action' => 'cancel' ] ];

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

	public function test_filter_mcp_server_config_swaps_in_the_direct_tool(): void {
		$config = filter_mcp_server_config(
			[ 'tools' => [ 'mcp-adapter/execute-ability', 'rest-api/read', 'rest-api/write', 'rest-api/delete' ] ]
		);

		$this->assertCount( 4, $config['tools'] );
		$this->assertSame( [ 'mcp-adapter/execute-ability', 'rest-api/read', 'rest-api/write' ], array_slice( $config['tools'], 0, 3 ) );
		$this->assertInstanceOf( McpTool::class, $config['tools'][3] );
		$this->assertSame( 'rest-api-delete', $config['tools'][3]->get_name() );
		$this->assertNotContains( 'rest-api/delete', $config['tools'] );
	}

	public function test_filter_mcp_server_config_adds_the_direct_tool_when_the_config_has_no_tools(): void {
		$config = filter_mcp_server_config( [] );

		$this->assertCount( 1, $config['tools'] );
		$this->assertSame( 'rest-api-delete', $config['tools'][0]->get_name() );
	}

	public function test_hide_ability_from_mcp_hides_only_the_delete_ability(): void {
		$args = [ 'meta' => [ 'mcp' => [ 'public' => true ] ] ];

		$this->assertFalse( hide_ability_from_mcp( $args, 'rest-api/delete' )['meta']['mcp']['public'] );
		$this->assertSame( $args, hide_ability_from_mcp( $args, 'rest-api/read' ) );
		$this->assertSame( $args, hide_ability_from_mcp( $args, 'rest-api/write' ) );
	}

	public function test_confirmation_message_names_the_item_title(): void {
		$message = confirmation_message( self::ARGS );

		$this->assertStringContainsString( '"Hello & welcome"', $message );
		$this->assertStringContainsString( '/wp/v2/posts/12', $message );
		$this->assertStringContainsString( 'My Site', $message );
		$this->assertStringContainsString( '{"force":true}', $message );
	}

	public function test_confirmation_message_falls_back_to_the_route(): void {
		Functions\when( 'rest_do_request' )->justReturn( new WP_REST_Response( null, 404 ) );

		$message = confirmation_message( [ 'route' => '/wp/v2/posts/12' ] );

		$this->assertSame( 'Delete /wp/v2/posts/12 on My Site?', $message );
	}
}
