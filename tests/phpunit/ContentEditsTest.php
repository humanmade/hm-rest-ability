<?php
/**
 * Unit tests for inc/content-edits.php, and for the REST tools that use it.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function HM\ContentEdits\apply_to_input;
use function HM\ContentEdits\nesting_errors;
use function HM\RestApiAbilities\check_permission;
use function HM\RestApiAbilities\execute;

class ContentEditsTest extends TestCase {

	private const ROUTE = '/wp/v2/pages/12';

	/**
	 * The page the stubbed REST API holds.
	 *
	 * @var array
	 */
	private array $page = [];

	/**
	 * Every request the plugin dispatched.
	 *
	 * @var WP_REST_Request[]
	 */
	private array $requests = [];

	/**
	 * Every request the write handler's permission callback saw.
	 *
	 * @var WP_REST_Request[]
	 */
	private array $permission_requests = [];

	/**
	 * Value the policy filter returns.
	 */
	private string $policy = 'allow';

	/**
	 * Arguments the policy filter last received for a write.
	 *
	 * @var array|null
	 */
	private ?array $policy_args = null;

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-risk.php' );
		$this->load_plugin_file( 'inc/route-suggestions.php' );
		$this->load_plugin_file( 'inc/content-edits.php' );
		$this->load_plugin_file( 'inc/rest-api-abilities.php' );

		$this->page                = [ 'content' => [ 'raw' => $this->markup() ] ];
		$this->requests            = [];
		$this->permission_requests = [];
		$this->policy              = 'allow';
		$this->policy_args         = null;

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/pages/(?P<id>[\d]+)' => [
				[
					'methods'             => [ 'GET' => true ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => [ 'POST' => true, 'PUT' => true, 'PATCH' => true ],
					'permission_callback' => function ( WP_REST_Request $request ) {
						$this->permission_requests[] = $request;

						return true;
					},
				],
			],
		] );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'rest_get_server' )->justReturn( $server );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value, ...$args ) {
				if ( 'hm_rest_ability_policy' === $tag ) {
					if ( 'GET' !== $args[0] ) {
						$this->policy_args = $args;
					}

					return $this->policy;
				}

				return $value;
			}
		);
		Functions\when( 'rest_do_request' )->alias( [ $this, 'dispatch' ] );
	}

	/**
	 * Stands in for the REST API: a GET returns the page, a write saves content.
	 */
	public function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$this->requests[] = $request;

		if ( self::ROUTE !== $request->get_route() ) {
			return new WP_REST_Response( [ 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ], 404 );
		}

		if ( 'GET' !== $request->get_method() ) {
			$this->page['content'] = [ 'raw' => $request->get_body_params()['content'] ];
		}

		return new WP_REST_Response( $this->page, 200 );
	}

	/**
	 * Block markup with nested blocks and a self-closing block.
	 */
	private function markup(): string {
		return implode(
			"\n",
			[
				'<!-- wp:group -->',
				'<div class="wp-block-group"><!-- wp:paragraph -->',
				'<p>Alpha</p>',
				'<!-- /wp:paragraph -->',
				'',
				'<!-- wp:paragraph {"className":"x"} -->',
				'<p>Beta</p>',
				'<!-- /wp:paragraph --></div>',
				'<!-- /wp:group -->',
				'<!-- wp:spacer {"height":"10px"} /-->',
			]
		);
	}

	/**
	 * A write that makes the given edits to the page, Alpha to Gamma by default.
	 *
	 * @param array $edits  Edits, or [] for the default.
	 * @param array $params Extra or replacement params.
	 */
	private function write( array $edits = [], array $params = [] ): array {
		return [
			'method' => 'POST',
			'route'  => self::ROUTE,
			'params' => $params + [
				'_content_edits'  => $edits ?: [ [ 'old_string' => 'Alpha', 'new_string' => 'Gamma' ] ],
				'_content_sha256' => hash( 'sha256', $this->page['content']['raw'] ),
				'title'           => 'Kept',
			],
		];
	}

	/**
	 * The methods of the requests dispatched so far.
	 *
	 * @return string[]
	 */
	private function methods(): array {
		return array_map( static fn ( WP_REST_Request $request ) => $request->get_method(), $this->requests );
	}

	private function assert_error( string $code, $result ): WP_Error {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );

		return $result;
	}

	public function test_input_without_edit_params_is_left_alone(): void {
		$input = [ 'method' => 'POST', 'route' => self::ROUTE, 'params' => [ 'content' => 'x' ] ];

		$this->assertSame( $input, apply_to_input( $input ) );
		$this->assertSame( [], $this->requests );
	}

	public function test_edits_become_the_content_param(): void {
		$result = apply_to_input( $this->write() );

		$this->assertSame(
			[
				'title'   => 'Kept',
				'content' => str_replace( 'Alpha', 'Gamma', $this->markup() ),
			],
			$result['params']
		);
		$this->assertSame( [ 'GET' ], $this->methods() );
		$this->assertSame( [ 'context' => 'edit' ], $this->requests[0]->get_query_params() );
	}

	public function test_edits_apply_in_order(): void {
		$result = apply_to_input( $this->write( [
			[ 'old_string' => 'Alpha', 'new_string' => 'Gamma' ],
			[ 'old_string' => '<p>Gamma</p>', 'new_string' => '<p>Delta</p>' ],
		] ) );

		$this->assertStringContainsString( '<p>Delta</p>', $result['params']['content'] );
		$this->assertStringNotContainsString( 'Gamma', $result['params']['content'] );
	}

	public function test_an_empty_new_string_deletes_the_text(): void {
		$result = apply_to_input( $this->write( [ [ 'old_string' => 'Alpha', 'new_string' => '' ] ] ) );

		$this->assertSame( '<p></p>', explode( "\n", $result['params']['content'] )[2] );
	}

	public function test_expected_matches_replaces_every_match(): void {
		$result = apply_to_input( $this->write( [ [ 'old_string' => '<p>', 'new_string' => '<p class="a">', 'expected_matches' => 2 ] ] ) );

		$this->assertSame( 2, substr_count( $result['params']['content'], '<p class="a">' ) );
	}

	public function test_refuses_a_change_saved_since_the_read(): void {
		$input = $this->write();

		// A change in the same second leaves modified_gmt alone, so this
		// checks the content itself.
		$this->page['content']['raw'] = str_replace( 'Beta', 'Delta', $this->markup() );

		$error = $this->assert_error( 'hm_content_changed', apply_to_input( $input ) );

		$this->assertStringContainsString( 'context=edit', $error->get_error_message() );
		$this->assertSame( [ 'status' => 409 ], $error->get_error_data() );
	}

	public function test_refuses_a_missing_hash(): void {
		$input = $this->write();
		unset( $input['params']['_content_sha256'] );

		$this->assert_error( 'hm_content_edits_no_hash', apply_to_input( $input ) );
		$this->assertSame( [], $this->requests );
	}

	public function test_refuses_a_hash_without_edits(): void {
		$input = $this->write();
		unset( $input['params']['_content_edits'] );

		$this->assert_error( 'hm_content_edits_invalid', apply_to_input( $input ) );
	}

	public function test_refuses_edits_on_a_read_or_delete(): void {
		foreach ( [ 'GET', 'DELETE' ] as $method ) {
			$input           = $this->write();
			$input['method'] = $method;

			$this->assert_error( 'hm_content_edits_method', apply_to_input( $input ) );
		}
	}

	public function test_refuses_content_and_edits_together(): void {
		$this->assert_error( 'hm_content_edits_with_content', apply_to_input( $this->write( [], [ 'content' => 'x' ] ) ) );
	}

	public function test_refuses_badly_shaped_edits(): void {
		$cases = [
			'not a list'         => [ 'old_string' => 'Alpha', 'new_string' => 'Gamma' ],
			'empty list'         => [],
			'empty old_string'   => [ [ 'old_string' => '', 'new_string' => 'Gamma' ] ],
			'missing new_string' => [ [ 'old_string' => 'Alpha' ] ],
			'zero matches'       => [ [ 'old_string' => 'Alpha', 'new_string' => 'Gamma', 'expected_matches' => 0 ] ],
			'string matches'     => [ [ 'old_string' => 'Alpha', 'new_string' => 'Gamma', 'expected_matches' => '1' ] ],
		];

		foreach ( $cases as $label => $edits ) {
			$input                             = $this->write();
			$input['params']['_content_edits'] = $edits;

			$this->assertInstanceOf( WP_Error::class, apply_to_input( $input ), $label );
			$this->assertSame( 'hm_content_edits_invalid', apply_to_input( $input )->get_error_code(), $label );
		}
	}

	public function test_refuses_when_nothing_matches(): void {
		$error = $this->assert_error( 'hm_content_edits_match_count', apply_to_input( $this->write( [ [ 'old_string' => 'Missing', 'new_string' => 'x' ] ] ) ) );

		$this->assertSame(
			'Edit 1: found 0 matches for old_string, expected 1. Nothing was saved. Copy old_string exactly from the content, including whitespace.',
			$error->get_error_message()
		);
	}

	public function test_refuses_when_there_are_too_many_matches(): void {
		$error = $this->assert_error(
			'hm_content_edits_match_count',
			apply_to_input( $this->write( [
				[ 'old_string' => 'Alpha', 'new_string' => 'Gamma' ],
				[ 'old_string' => '<p>', 'new_string' => '<p class="a">' ],
			] ) )
		);

		$this->assertSame(
			'Edit 2: found 2 matches for old_string, expected 1. Nothing was saved. Include more surrounding text so it matches once, or set expected_matches to 2 to replace them all.',
			$error->get_error_message()
		);
	}

	public function test_explains_a_route_with_no_content(): void {
		$input      = $this->write();
		$this->page = [ 'id' => 12 ];

		$this->assert_error( 'hm_content_edits_no_content', apply_to_input( $input ) );
	}

	public function test_passes_a_read_error_through(): void {
		$input          = $this->write();
		$input['route'] = '/wp/v2/pages/99';

		$error = $this->assert_error( 'rest_no_route', apply_to_input( $input ) );

		$this->assertSame( [ 'status' => 404 ], $error->get_error_data() );
	}

	public function test_refuses_removing_a_closing_comment(): void {
		$error = $this->assert_error(
			'hm_content_edits_break_blocks',
			apply_to_input( $this->write( [ [ 'old_string' => '<!-- /wp:group -->', 'new_string' => '' ] ] ) )
		);

		$this->assertStringContainsString( 'core/group is never closed', $error->get_error_message() );
	}

	public function test_refuses_moving_a_block_across_a_closer(): void {
		// Moves the second paragraph's closing comment below the group's, so
		// the paragraph opens inside the group and closes outside it. The
		// count of each block's comments doesn't change.
		$error = $this->assert_error(
			'hm_content_edits_break_blocks',
			apply_to_input( $this->write( [
				[
					'old_string' => "<p>Beta</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->",
					'new_string' => "<p>Beta</p></div>\n<!-- /wp:group -->\n<!-- /wp:paragraph -->",
				],
			] ) )
		);

		$this->assertStringContainsString( 'core/paragraph is still open when core/group closes', $error->get_error_message() );
		$this->assertStringContainsString( 'a core/paragraph closing comment has no opening comment before it', $error->get_error_message() );
	}

	public function test_refuses_swapping_a_blocks_opener_and_closer(): void {
		$this->assert_error(
			'hm_content_edits_break_blocks',
			apply_to_input( $this->write( [
				[
					'old_string' => "<!-- wp:paragraph -->\n<p>Alpha</p>\n<!-- /wp:paragraph -->",
					'new_string' => "<!-- /wp:paragraph -->\n<p>Alpha</p>\n<!-- wp:paragraph -->",
				],
			] ) )
		);
	}

	public function test_allows_moving_a_whole_block_out_of_its_parent(): void {
		$result = apply_to_input( $this->write( [
			[ 'old_string' => "<!-- wp:paragraph -->\n<p>Alpha</p>\n<!-- /wp:paragraph -->\n", 'new_string' => '' ],
			[ 'old_string' => "<!-- /wp:group -->\n", 'new_string' => "<!-- /wp:group -->\n<!-- wp:paragraph -->\n<p>Alpha</p>\n<!-- /wp:paragraph -->\n" ],
		] ) );

		$this->assertIsArray( $result );
	}

	public function test_allows_an_edit_that_leaves_an_existing_problem_alone(): void {
		$this->page['content']['raw'] = "<!-- wp:quote -->\n<!-- wp:quote -->\n<p>Alpha</p>\n<!-- /wp:quote -->";

		$this->assertIsArray( apply_to_input( $this->write() ) );
	}

	public function test_allows_an_edit_that_fixes_a_problem(): void {
		$this->page['content']['raw'] = "<!-- wp:quote -->\n<!-- wp:quote -->\n<p>Alpha</p>\n<!-- /wp:quote -->";

		$this->assertIsArray( apply_to_input( $this->write( [ [ 'old_string' => '<p>Alpha</p>', 'new_string' => "<p>Alpha</p>\n<!-- /wp:quote -->" ] ] ) ) );
	}

	public function test_nesting_errors_normalise_names_and_skip_self_closing_blocks(): void {
		$this->assertSame(
			[],
			nesting_errors( '<!-- wp:paragraph --><!-- wp:spacer {"height":"1px"} /--><!-- /wp:core/paragraph --><!-- wp:rt-carousel/slide {"a":1} --><!-- /wp:rt-carousel/slide -->' )
		);
		$this->assertSame(
			[ 'core/group is still open when core/quote closes', 'core/columns is never closed' ],
			nesting_errors( '<!-- wp:columns --><!-- wp:quote --><!-- wp:group --><!-- /wp:quote -->' )
		);
	}

	public function test_check_permission_runs_the_permission_callback_on_the_edited_content(): void {
		$this->assertTrue( check_permission( $this->write() ) );

		$expected = str_replace( 'Alpha', 'Gamma', $this->markup() );

		$this->assertCount( 1, $this->permission_requests );
		$this->assertSame( $expected, $this->permission_requests[0]->get_param( 'content' ) );
		$this->assertNull( $this->permission_requests[0]->get_param( '_content_edits' ) );
		$this->assertSame( [ 'POST', self::ROUTE, [ 'title' => 'Kept', 'content' => $expected ] ], $this->policy_args );
	}

	public function test_check_permission_reports_a_failed_edit(): void {
		$this->assert_error( 'hm_content_edits_match_count', check_permission( $this->write( [ [ 'old_string' => 'Missing', 'new_string' => 'x' ] ] ) ) );
		$this->assertSame( [], $this->permission_requests );
	}

	public function test_check_permission_lets_site_policy_deny_an_edit(): void {
		$this->policy = 'deny';

		$this->assert_error( 'rest_ability_policy_denied', check_permission( $this->write() ) );
	}

	public function test_execute_sends_a_normal_write_and_returns_the_new_hash(): void {
		$result   = execute( $this->write() );
		$expected = str_replace( 'Alpha', 'Gamma', $this->markup() );

		$this->assertSame( [ 'GET', 'POST' ], $this->methods() );
		$this->assertSame( [ 'title' => 'Kept', 'content' => $expected ], $this->requests[1]->get_body_params() );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( hash( 'sha256', $expected ), $result['content_sha256'] );
		$this->assertSame( $expected, $this->page['content']['raw'] );
	}

	public function test_execute_saves_nothing_when_an_edit_fails(): void {
		$input = $this->write();

		$this->page['content']['raw'] .= "\n<!-- wp:paragraph -->\n<p>Added by someone else</p>\n<!-- /wp:paragraph -->";

		$result = execute( $input );

		$this->assertSame( 409, $result['status'] );
		$this->assertSame( 'hm_content_changed', $result['data']['code'] );
		$this->assertSame( [ 'GET' ], $this->methods() );
	}

	public function test_execute_returns_the_hash_with_a_read_in_the_edit_context(): void {
		$result = execute( [ 'method' => 'GET', 'route' => self::ROUTE, 'params' => [ 'context' => 'edit' ] ] );

		$this->assertSame( hash( 'sha256', $this->markup() ), $result['content_sha256'] );
	}

	public function test_execute_returns_the_hash_when_the_content_is_trimmed(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn ( string $tag, $value ) => 'hm_rest_ability_max_response_bytes' === $tag ? 50 : $value
		);

		$result = execute( [ 'method' => 'GET', 'route' => self::ROUTE, 'params' => [ 'context' => 'edit' ] ] );

		$this->assertArrayHasKey( 'truncated', $result );
		$this->assertArrayNotHasKey( 'content', $result['data'] );
		$this->assertSame( hash( 'sha256', $this->markup() ), $result['content_sha256'] );
	}

	public function test_execute_leaves_out_the_hash_without_raw_content(): void {
		$this->page = [ 'content' => [ 'rendered' => '<p>Alpha</p>' ] ];

		$result = execute( [ 'method' => 'GET', 'route' => self::ROUTE ] );

		$this->assertArrayNotHasKey( 'content_sha256', $result );
	}
}
