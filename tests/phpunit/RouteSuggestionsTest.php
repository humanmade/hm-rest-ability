<?php
/**
 * Unit tests for inc/route-suggestions.php.
 */

namespace HM\RestAbility\Tests;

use function HM\RouteSuggestions\no_route_message;
use function HM\RouteSuggestions\readable_route;
use function HM\RouteSuggestions\route_words;
use function HM\RouteSuggestions\suggest_routes;

class RouteSuggestionsTest extends TestCase {

	/**
	 * Route patterns as core registers them, in registration order, plus a
	 * few plugin namespaces.
	 */
	private const PATTERNS = [
		'/',
		'/oembed/1.0',
		'/oembed/1.0/embed',
		'/jetpack/v4',
		'/jetpack/v4/module/all',
		'/jetpack/v4/module/(?P<slug>[a-z\-]+)',
		'/wp/v2',
		'/wp/v2/posts',
		'/wp/v2/posts/(?P<id>[\d]+)',
		'/wp/v2/posts/(?P<parent>[\d]+)/revisions',
		'/wp/v2/blocks',
		'/wp/v2/blocks/(?P<id>[\d]+)',
		'/wp/v2/themes',
		'/wp/v2/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)',
		'/wp/v2/block-types',
		'/wp/v2/wp_pattern_category',
		'/wp/v2/wp_pattern_category/(?P<id>[\d]+)',
		'/wp/v2/pattern-directory/patterns',
		'/wp/v2/block-patterns/patterns',
		'/wp/v2/block-patterns/categories',
		'/wp/v2/global-styles/(?P<parent>[\d]+)/revisions',
		'/wp/v2/global-styles/(?P<parent>[\d]+)/revisions/(?P<id>[\d]+)',
		'/wp/v2/global-styles/themes/(?P<stylesheet>[\/\s%\w\.\(\)\[\]\@_\-]+)/variations',
		'/wp/v2/global-styles/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)',
		'/wp/v2/global-styles/(?P<id>[\/\d+]+)',
	];

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-suggestions.php' );
	}

	public function test_suggests_pattern_routes_for_the_patterns_guess(): void {
		$this->assertSame(
			[
				'/wp/v2/block-patterns/patterns',
				'/wp/v2/pattern-directory/patterns',
				'/wp/v2/wp_pattern_category',
				'/wp/v2/wp_pattern_category/{id}',
				'/wp/v2/block-patterns/categories',
			],
			suggest_routes( '/wp/v2/patterns', self::PATTERNS )
		);
	}

	public function test_suggests_every_global_styles_route_for_the_bare_guess(): void {
		$this->assertSame(
			[
				'/wp/v2/global-styles/{id}',
				'/wp/v2/global-styles/themes/{stylesheet}',
				'/wp/v2/global-styles/{parent}/revisions',
				'/wp/v2/global-styles/{parent}/revisions/{id}',
				'/wp/v2/global-styles/themes/{stylesheet}/variations',
			],
			suggest_routes( '/wp/v2/global-styles', self::PATTERNS )
		);
	}

	public function test_tolerates_case_underscores_and_trailing_slashes(): void {
		$this->assertSame(
			suggest_routes( '/wp/v2/global-styles', self::PATTERNS ),
			suggest_routes( '/WP/v2/Global_Styles/', self::PATTERNS )
		);
	}

	public function test_tolerates_a_typo_and_a_singular(): void {
		$this->assertContains( '/wp/v2/block-patterns/patterns', suggest_routes( '/wp/v2/pattrens', self::PATTERNS ) );
		$this->assertSame( '/wp/v2/block-patterns/patterns', suggest_routes( '/wp/v2/pattern', self::PATTERNS )[0] );
	}

	public function test_ignores_words_shared_with_the_namespace(): void {
		// Every jetpack route shares "jetpack", which must not make them all matches.
		$this->assertSame( [], suggest_routes( '/jetpack/v4/nothing-here', self::PATTERNS ) );
	}

	public function test_limits_and_orders_by_score_then_length(): void {
		$this->assertSame(
			[ '/wp/v2/block-patterns/patterns', '/wp/v2/pattern-directory/patterns' ],
			suggest_routes( '/wp/v2/patterns', self::PATTERNS, 2 )
		);
	}

	public function test_never_suggests_the_index_or_the_route_itself(): void {
		$this->assertSame( [], suggest_routes( '/', self::PATTERNS ) );
		$this->assertNotContains( '/wp/v2/posts', suggest_routes( '/wp/v2/posts', self::PATTERNS ) );
	}

	public function test_readable_route_replaces_named_groups_with_placeholders(): void {
		$this->assertSame( '/wp/v2/posts/{id}', readable_route( '/wp/v2/posts/(?P<id>[\d]+)' ) );
		$this->assertSame(
			'/wp/v2/global-styles/themes/{stylesheet}/variations',
			readable_route( '/wp/v2/global-styles/themes/(?P<stylesheet>[\/\s%\w\.\(\)\[\]\@_\-]+)/variations' )
		);
		$this->assertSame(
			'/wp/v2/themes/{stylesheet}',
			readable_route( '/wp/v2/themes/(?P<stylesheet>[^\/:<>\*\?"\|]+(?:\/[^\/:<>\*\?"\|]+)?)' )
		);
		$this->assertSame( '/plugin/v1/{param}/x', readable_route( '/plugin/v1/(\d+)/x' ) );
		$this->assertSame( '/plugin/v1/{id}', readable_route( '/plugin/v1/(?P<id>[\d]+' ) );
	}

	public function test_route_words_singularises_and_drops_short_fragments(): void {
		$this->assertSame( [ 'block', 'pattern' ], route_words( '/wp/v2/block-patterns/patterns' ) );
		$this->assertSame( [ 'pattern', 'category' ], route_words( '/wp/v2/wp_pattern_category/{id}' ) );
	}

	public function test_no_route_message_names_suggestions_and_placeholders(): void {
		$message = no_route_message( '/wp/v2/global-styles', [ '/wp/v2/global-styles/{id}' ] );

		$this->assertStringStartsWith( 'No route matches /wp/v2/global-styles. Did you mean: /wp/v2/global-styles/{id}?', $message );
		$this->assertStringContainsString( 'Replace each {name} placeholder', $message );
		$this->assertStringContainsString( 'GET /wp/v2', $message );

		$message = no_route_message( '/wp/v2/patterns', [ '/wp/v2/block-patterns/patterns' ] );

		$this->assertStringNotContainsString( 'placeholder', $message );

		$message = no_route_message( '/nope', [] );

		$this->assertSame( 'No route matches /nope. GET / lists every route, and GET /<namespace> (for example GET /wp/v2) lists one namespace.', $message );
	}
}
