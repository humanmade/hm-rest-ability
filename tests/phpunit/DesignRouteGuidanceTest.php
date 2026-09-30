<?php
/**
 * Unit tests for inc/design-route-guidance.php.
 */

namespace HM\RestAbility\Tests;

use function HM\DesignRouteGuidance\add_guidance;
use function HM\DesignRouteGuidance\note_for_route;

class DesignRouteGuidanceTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/design-route-guidance.php' );
	}

	public function test_explains_where_the_global_styles_id_comes_from(): void {
		$note = note_for_route( '/wp/v2/global-styles/5' );

		$this->assertStringContainsString( 'GET /wp/v2/themes?status=active', $note );
		$this->assertStringContainsString( 'wp:user-global-styles', $note );
		$this->assertSame( $note, note_for_route( '/wp/v2/global-styles/5/revisions' ) );
	}

	public function test_explains_the_stylesheet_for_theme_global_styles(): void {
		$note = note_for_route( '/wp/v2/global-styles/themes/twentytwentyfive' );

		$this->assertStringContainsString( 'theme directory slug', $note );
		$this->assertStringContainsString( '/wp/v2/global-styles/{id}', $note );
		$this->assertSame( $note, note_for_route( '/wp/v2/global-styles/themes/twentytwentyfive/variations' ) );
	}

	public function test_distinguishes_registered_patterns_from_synced_patterns(): void {
		$this->assertStringContainsString( 'GET /wp/v2/blocks', note_for_route( '/wp/v2/block-patterns/patterns' ) );
		$this->assertStringContainsString( 'GET /wp/v2/block-patterns/patterns', note_for_route( '/wp/v2/blocks/12' ) );
	}

	public function test_leaves_other_routes_alone(): void {
		$this->assertSame( '', note_for_route( '/wp/v2/block-types' ) );
		$this->assertSame( '', note_for_route( '/wp/v2/posts' ) );
		$this->assertSame( 'Existing.', add_guidance( 'Existing.', '/wp/v2/posts', [] ) );
	}

	public function test_appends_to_existing_guidance(): void {
		$guidance = add_guidance( 'This changes site settings.', '/wp/v2/global-styles/5', [] );

		$this->assertStringStartsWith( 'This changes site settings. The id is', $guidance );
	}
}
