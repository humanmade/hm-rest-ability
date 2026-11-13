<?php
/**
 * Unit tests for inc/content-abilities.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function HM\ContentAbilities\block_counts;
use function HM\ContentAbilities\check_permission;
use function HM\ContentAbilities\filter_mcp_server_config;
use function HM\ContentAbilities\find;
use function HM\ContentAbilities\replace;
use function HM\ContentAbilities\unbalanced_blocks;

class ContentAbilitiesTest extends TestCase {

	private const ROUTE = '/wp/v2/pages/12';

	private const MODIFIED = '2026-11-01T10:00:00';

	/**
	 * The page the stubbed REST API holds. Null content leaves the page without
	 * a `content` field.
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
	 * Rewrites content on save, to stand in for a filter that changes it.
	 *
	 * @var callable|null
	 */
	private $save_filter = null;

	/**
	 * Value the policy filter returns.
	 */
	private string $policy = 'allow';

	/**
	 * Value the needs_confirmation filter returns, or null to leave the default.
	 */
	private ?bool $needs_confirmation = null;

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-risk.php' );
		$this->load_plugin_file( 'inc/route-suggestions.php' );
		$this->load_plugin_file( 'inc/rest-api-abilities.php' );
		$this->load_plugin_file( 'inc/confirmation.php' );
		$this->load_plugin_file( 'inc/content-abilities.php' );

		$this->page               = [
			'modified_gmt' => self::MODIFIED,
			'content'      => [ 'raw' => $this->markup() ],
		];
		$this->requests           = [];
		$this->save_filter        = null;
		$this->policy             = 'allow';
		$this->needs_confirmation = null;

		$server = new WP_REST_Server();
		$server->set_routes( [
			'/wp/v2/pages/(?P<id>[\d]+)' => [
				[
					'methods'             => [ 'GET' => true ],
					'permission_callback' => '__return_true',
				],
				[
					'methods'             => [ 'POST' => true, 'PUT' => true, 'PATCH' => true ],
					'permission_callback' => '__return_true',
				],
			],
		] );

		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'rest_get_server' )->justReturn( $server );
		Functions\when( 'apply_filters' )->alias(
			function ( string $tag, $value ) {
				if ( 'hm_rest_ability_policy' === $tag ) {
					return $this->policy;
				}

				if ( 'hm_rest_ability_needs_confirmation' === $tag && null !== $this->needs_confirmation ) {
					return $this->needs_confirmation;
				}

				return $value;
			}
		);
		Functions\when( 'rest_do_request' )->alias( [ $this, 'dispatch' ] );
	}

	/**
	 * Stands in for the REST API: a GET returns the page, a POST saves content.
	 */
	public function dispatch( WP_REST_Request $request ): WP_REST_Response {
		$this->requests[] = $request;

		if ( '/wp/v2/pages/12' !== $request->get_route() ) {
			return new WP_REST_Response( [ 'code' => 'rest_no_route', 'message' => 'No route was found matching the URL and request method.' ], 404 );
		}

		if ( 'POST' === $request->get_method() ) {
			$content = $request->get_body_params()['content'];

			if ( $this->save_filter ) {
				$content = ( $this->save_filter )( $content );
			}

			$this->page['content']      = [ 'raw' => $content ];
			$this->page['modified_gmt'] = '2026-11-01T10:05:00';
		}

		return new WP_REST_Response( $this->page, 200 );
	}

	/**
	 * Block markup with nested blocks and a self-closing block. Lines are
	 * numbered from 1.
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
	 * Arguments for a replace call that changes Alpha to Gamma.
	 */
	private function replace_args( array $overrides = [] ): array {
		return $overrides + [
			'route'        => self::ROUTE,
			'old_string'   => 'Alpha',
			'new_string'   => 'Gamma',
			'modified_gmt' => self::MODIFIED,
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
		$this->assertSame( $code, $result->get_error_code() );

		return $result;
	}

	public function test_find_returns_matches_with_line_numbers_and_a_copyable_excerpt(): void {
		$result = find( [ 'route' => self::ROUTE, 'search' => 'Beta' ] );

		$this->assertSame( self::ROUTE, $result['route'] );
		$this->assertSame( self::MODIFIED, $result['modified_gmt'] );
		$this->assertSame( strlen( $this->markup() ), $result['content_bytes'] );
		$this->assertSame( 10, $result['line_count'] );
		$this->assertSame( 1, $result['match_count'] );
		$this->assertSame(
			[
				'start_line'         => 7,
				'end_line'           => 7,
				'excerpt_start_line' => 5,
				'excerpt'            => implode(
					"\n",
					[
						'',
						'<!-- wp:paragraph {"className":"x"} -->',
						'<p>Beta</p>',
						'<!-- /wp:paragraph --></div>',
						'<!-- /wp:group -->',
					]
				),
			],
			$result['matches'][0]
		);
		$this->assertSame( [ 'GET' ], $this->methods() );
		$this->assertSame( [ 'context' => 'edit' ], $this->requests[0]->get_query_params() );
	}

	public function test_find_honours_context_lines(): void {
		$result = find( [ 'route' => self::ROUTE, 'search' => 'Beta', 'context_lines' => 0 ] );

		$this->assertSame( 7, $result['matches'][0]['excerpt_start_line'] );
		$this->assertSame( '<p>Beta</p>', $result['matches'][0]['excerpt'] );
	}

	public function test_find_reports_a_match_that_spans_lines(): void {
		$result = find( [
			'route'         => self::ROUTE,
			'search'        => "Alpha</p>\n<!-- /wp:paragraph -->",
			'context_lines' => 1,
		] );

		$match = $result['matches'][0];

		$this->assertSame( 3, $match['start_line'] );
		$this->assertSame( 4, $match['end_line'] );
		$this->assertSame( 2, $match['excerpt_start_line'] );
		$this->assertSame(
			"<div class=\"wp-block-group\"><!-- wp:paragraph -->\n<p>Alpha</p>\n<!-- /wp:paragraph -->\n",
			$match['excerpt']
		);
	}

	public function test_find_matches_are_case_sensitive_and_can_be_empty(): void {
		$result = find( [ 'route' => self::ROUTE, 'search' => 'alpha' ] );

		$this->assertSame( 0, $result['match_count'] );
		$this->assertSame( [], $result['matches'] );
	}

	public function test_find_counts_every_match(): void {
		$result = find( [ 'route' => self::ROUTE, 'search' => '<p>' ] );

		$this->assertSame( 2, $result['match_count'] );
		$this->assertSame( [ 3, 7 ], array_column( $result['matches'], 'start_line' ) );
	}

	public function test_find_refuses_an_empty_search(): void {
		$this->assert_error( 'hm_content_empty_search', find( [ 'route' => self::ROUTE, 'search' => '' ] ) );
		$this->assertSame( [], $this->requests );
	}

	public function test_find_explains_a_route_with_no_content(): void {
		$this->page = [ 'id' => 12 ];

		$error = $this->assert_error( 'hm_content_no_content', find( [ 'route' => self::ROUTE, 'search' => 'Alpha' ] ) );

		$this->assertSame(
			'This route has no editable content. Use a single post route such as /wp/v2/pages/123.',
			$error->get_error_message()
		);
	}

	public function test_find_passes_a_rest_error_through(): void {
		$error = $this->assert_error( 'rest_no_route', find( [ 'route' => '/wp/v2/pages/99', 'search' => 'Alpha' ] ) );

		$this->assertSame( 'No route was found matching the URL and request method.', $error->get_error_message() );
		$this->assertSame( [ 'status' => 404 ], $error->get_error_data() );
	}

	public function test_find_trims_an_oversized_result_and_says_how_to_narrow_it(): void {
		$this->page['content']['raw'] = str_repeat( "needle\n", 3000 );

		$result = find( [ 'route' => self::ROUTE, 'search' => 'needle' ] );

		$this->assertArrayNotHasKey( 'matches', $result );
		$this->assertSame( 3000, $result['match_count'] );
		$this->assertSame( 'response_too_large', $result['truncated']['reason'] );
		$this->assertStringContainsString( 'longer piece of text', $result['truncated']['hint'] );
	}

	public function test_check_permission_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$this->assert_error( 'rest_not_logged_in', check_permission( [ 'route' => self::ROUTE ] ) );
	}

	public function test_check_permission_reads_the_route_with_the_edit_context(): void {
		$this->assertTrue( check_permission( [ 'route' => self::ROUTE ] ) );
	}

	public function test_check_permission_fails_for_an_unknown_route(): void {
		$this->assert_error( 'rest_no_route', check_permission( [ 'route' => '/wp/v2/nothing/1' ] ) );
	}

	public function test_replace_dry_run_saves_nothing(): void {
		$result = replace( $this->replace_args() );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 1, $result['match_count'] );
		$this->assertSame( strlen( $this->markup() ), $result['content_bytes_before'] );
		$this->assertSame( strlen( $this->markup() ), $result['content_bytes_after'] );
		$this->assertSame( 'Nothing was saved. Call again with dry_run false to save.', $result['next'] );
		$this->assertSame( [ 'GET' ], $this->methods() );
		$this->assertSame( $this->markup(), $this->page['content']['raw'] );
	}

	public function test_replace_shows_the_changed_lines_in_the_new_content(): void {
		$result = replace( $this->replace_args() );

		$this->assertSame(
			[
				[
					'start_line'         => 3,
					'end_line'           => 3,
					'excerpt_start_line' => 1,
					'excerpt'            => implode(
						"\n",
						[
							'<!-- wp:group -->',
							'<div class="wp-block-group"><!-- wp:paragraph -->',
							'<p>Gamma</p>',
							'<!-- /wp:paragraph -->',
							'',
						]
					),
				],
			],
			$result['changes']
		);
	}

	public function test_replace_reports_lines_in_the_new_content_when_the_line_count_changes(): void {
		$old = "<p>Alpha</p>\n<!-- /wp:paragraph -->";
		$new = "<p>One</p>\n<p>Two</p>\n<p>Three</p>\n<!-- /wp:paragraph -->";

		$result = replace( $this->replace_args( [ 'old_string' => $old, 'new_string' => $new ] ) );

		$this->assertSame( 3, $result['changes'][0]['start_line'] );
		$this->assertSame( 6, $result['changes'][0]['end_line'] );
		$this->assertSame( $result['content_bytes_before'] + strlen( $new ) - strlen( $old ), $result['content_bytes_after'] );
	}

	public function test_replace_with_an_empty_new_string_deletes_the_text(): void {
		$result = replace( $this->replace_args( [ 'old_string' => 'Alpha', 'new_string' => '', 'dry_run' => false ] ) );

		$this->assertSame( '<p></p>', explode( "\n", $this->page['content']['raw'] )[2] );
		$this->assertSame( 3, $result['changes'][0]['start_line'] );
		$this->assertSame( 3, $result['changes'][0]['end_line'] );
	}

	public function test_replace_refuses_when_the_content_has_changed(): void {
		$error = $this->assert_error(
			'hm_content_changed',
			replace( $this->replace_args( [ 'modified_gmt' => '2026-10-31T09:00:00', 'dry_run' => false ] ) )
		);

		$this->assertSame(
			'The content changed at 2026-11-01T10:00:00 since you read it at 2026-10-31T09:00:00. Call content-find again and use its modified_gmt.',
			$error->get_error_message()
		);
		$this->assertSame( [ 'GET' ], $this->methods() );
	}

	public function test_replace_refuses_a_route_that_reports_no_modified_time(): void {
		unset( $this->page['modified_gmt'] );

		$this->assert_error( 'hm_content_no_version', replace( $this->replace_args() ) );
	}

	public function test_replace_refuses_an_empty_old_string(): void {
		$this->assert_error( 'hm_content_empty_old_string', replace( $this->replace_args( [ 'old_string' => '' ] ) ) );
		$this->assertSame( [], $this->requests );
	}

	public function test_replace_refuses_when_nothing_matches(): void {
		$error = $this->assert_error( 'hm_content_match_count', replace( $this->replace_args( [ 'old_string' => 'Missing' ] ) ) );

		$this->assertSame(
			'Found 0 matches for old_string, expected 1. Copy old_string exactly from a content-find excerpt, including whitespace.',
			$error->get_error_message()
		);
	}

	public function test_replace_refuses_when_there_are_too_many_matches(): void {
		$error = $this->assert_error(
			'hm_content_match_count',
			replace( $this->replace_args( [ 'old_string' => '<p>', 'new_string' => '<p class="a">', 'dry_run' => false ] ) )
		);

		$this->assertSame(
			'Found 2 matches for old_string, expected 1. Include more surrounding text so it matches once, or set expected_matches to 2 to replace them all.',
			$error->get_error_message()
		);
		$this->assertSame( [ 'GET' ], $this->methods() );
	}

	public function test_replace_refuses_when_there_are_fewer_matches_than_expected(): void {
		$error = $this->assert_error( 'hm_content_match_count', replace( $this->replace_args( [ 'expected_matches' => 3, 'old_string' => '<p>' ] ) ) );

		$this->assertStringStartsWith( 'Found 2 matches for old_string, expected 3.', $error->get_error_message() );
	}

	public function test_replace_replaces_every_match_when_expected_matches_says_so(): void {
		$result = replace( $this->replace_args( [
			'old_string'       => '<p>',
			'new_string'       => '<p class="a">',
			'expected_matches' => 2,
			'dry_run'          => false,
		] ) );

		$this->assertSame( 2, $result['match_count'] );
		$this->assertCount( 2, $result['changes'] );
		$this->assertSame( [ 3, 7 ], array_column( $result['changes'], 'start_line' ) );
		$this->assertSame( 2, substr_count( $this->page['content']['raw'], '<p class="a">' ) );
		$this->assertTrue( $result['verified'] );
	}

	public function test_replace_refuses_an_edit_that_unbalances_block_markup(): void {
		$error = $this->assert_error(
			'hm_content_unbalanced_blocks',
			replace( $this->replace_args( [ 'old_string' => '<!-- /wp:group -->', 'new_string' => '', 'dry_run' => false ] ) )
		);

		$this->assertStringContainsString( 'core/group (1 opening, 0 closing)', $error->get_error_message() );
		$this->assertSame( [ 'GET' ], $this->methods() );
		$this->assertSame( $this->markup(), $this->page['content']['raw'] );
	}

	public function test_replace_refuses_an_unbalancing_edit_on_a_dry_run_too(): void {
		$this->assert_error(
			'hm_content_unbalanced_blocks',
			replace( $this->replace_args( [ 'old_string' => '<!-- wp:paragraph {"className":"x"} -->', 'new_string' => '' ] ) )
		);
	}

	public function test_replace_allows_an_edit_that_leaves_existing_imbalance_alone(): void {
		$this->page['content']['raw'] = "<!-- wp:quote -->\n<!-- wp:quote -->\n<p>Alpha</p>\n<!-- /wp:quote -->";

		$result = replace( $this->replace_args() );

		$this->assertTrue( $result['dry_run'] );
	}

	public function test_replace_allows_an_edit_that_balances_the_markup(): void {
		$this->page['content']['raw'] = "<!-- wp:quote -->\n<!-- wp:quote -->\n<p>Alpha</p>\n<!-- /wp:quote -->";

		$result = replace( $this->replace_args( [ 'old_string' => '<p>Alpha</p>', 'new_string' => "<p>Alpha</p>\n<!-- /wp:quote -->" ] ) );

		$this->assertTrue( $result['dry_run'] );
	}

	public function test_replace_saves_a_balanced_edit_and_verifies_it(): void {
		$result = replace( $this->replace_args( [ 'dry_run' => false ] ) );

		$expected = str_replace( 'Alpha', 'Gamma', $this->markup() );

		$this->assertFalse( $result['dry_run'] );
		$this->assertSame( 200, $result['status'] );
		$this->assertSame( '2026-11-01T10:05:00', $result['modified_gmt'] );
		$this->assertSame( 1, $result['match_count'] );
		$this->assertSame( strlen( $this->markup() ), $result['content_bytes_before'] );
		$this->assertSame( strlen( $expected ), $result['content_bytes_after'] );
		$this->assertSame( hash( 'sha256', $expected ), $result['sha256'] );
		$this->assertTrue( $result['verified'] );
		$this->assertArrayNotHasKey( 'message', $result );
		$this->assertSame( $expected, $this->page['content']['raw'] );

		$this->assertSame( [ 'GET', 'POST' ], $this->methods() );
		$this->assertSame( [ 'content' => $expected, 'context' => 'edit' ], $this->requests[1]->get_body_params() );
	}

	public function test_replace_defaults_to_a_dry_run(): void {
		$result = replace( $this->replace_args() );

		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( [ 'GET' ], $this->methods() );
	}

	public function test_replace_reports_when_the_saved_content_differs(): void {
		$this->save_filter = static fn ( string $content ) => str_replace( 'Gamma', 'Gamma!', $content );

		$result = replace( $this->replace_args( [ 'dry_run' => false ] ) );

		$this->assertFalse( $result['verified'] );
		$this->assertSame( hash( 'sha256', str_replace( 'Alpha', 'Gamma!', $this->markup() ) ), $result['sha256'] );
		$this->assertStringContainsString( 'content-find', $result['message'] );
		$this->assertStringContainsString( 'filter', $result['message'] );
	}

	public function test_replace_does_not_save_when_site_policy_denies_the_write(): void {
		$this->policy = 'deny';

		$result = replace( $this->replace_args( [ 'dry_run' => false ] ) );

		$this->assert_error( 'rest_ability_policy_denied', $result );
		$this->assertSame( [ 'GET' ], $this->methods() );
		$this->assertSame( $this->markup(), $this->page['content']['raw'] );
	}

	public function test_replace_sends_the_new_content_to_the_policy_filter(): void {
		$seen = null;

		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value, ...$args ) use ( &$seen ) {
				if ( 'hm_rest_ability_policy' === $tag && 'POST' === $args[0] ) {
					$seen = $args;
				}

				return $value;
			}
		);

		replace( $this->replace_args( [ 'dry_run' => false ] ) );

		$this->assertSame( self::ROUTE, $seen[1] );
		$this->assertSame( str_replace( 'Alpha', 'Gamma', $this->markup() ), $seen[2]['content'] );
	}

	public function test_replace_sends_the_user_to_the_write_tool_when_the_route_needs_confirmation(): void {
		$this->needs_confirmation = true;

		$error = $this->assert_error( 'hm_content_needs_confirmation', replace( $this->replace_args( [ 'dry_run' => false ] ) ) );

		$this->assertStringContainsString( 'rest-api-write', $error->get_error_message() );
		$this->assertSame( [ 'GET' ], $this->methods() );
	}

	public function test_replace_passes_a_save_error_through(): void {
		Functions\when( 'rest_do_request' )->alias(
			function ( WP_REST_Request $request ) {
				return 'POST' === $request->get_method()
					? new WP_REST_Response( [ 'code' => 'rest_cannot_edit', 'message' => 'Sorry, you are not allowed to edit this post.' ], 403 )
					: $this->dispatch( $request );
			}
		);

		$error = $this->assert_error( 'rest_cannot_edit', replace( $this->replace_args( [ 'dry_run' => false ] ) ) );

		$this->assertSame( 'Sorry, you are not allowed to edit this post.', $error->get_error_message() );
	}

	public function test_block_counts_normalise_names_and_skip_self_closing_blocks(): void {
		$counts = block_counts(
			'<!-- wp:paragraph --><!-- /wp:paragraph --><!-- wp:core/paragraph --><!-- wp:spacer {"height":"1px"} /--><!-- wp:rt-carousel/carousel-slide {"a":1} --><!-- wp:image /-->'
		);

		$this->assertSame(
			[
				'core/paragraph'             => [ 'open' => 2, 'close' => 1 ],
				'rt-carousel/carousel-slide' => [ 'open' => 1, 'close' => 0 ],
			],
			$counts
		);
	}

	public function test_unbalanced_blocks_names_only_the_blocks_that_changed(): void {
		$before = '<!-- wp:group --><!-- /wp:group --><!-- wp:quote -->';
		$after  = '<!-- wp:group --><!-- wp:quote -->';

		$this->assertSame( [ 'core/group' => [ 'open' => 1, 'close' => 0 ] ], unbalanced_blocks( $before, $after ) );
	}

	public function test_filter_mcp_server_config_exposes_the_abilities_as_tools(): void {
		$config = filter_mcp_server_config( [ 'tools' => [ 'mcp-adapter/execute-ability', 'media/upload' ] ] );

		$this->assertSame(
			[ 'mcp-adapter/execute-ability', 'media/upload', 'content/find', 'content/replace' ],
			$config['tools']
		);
	}
}
