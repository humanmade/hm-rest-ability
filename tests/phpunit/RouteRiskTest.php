<?php
/**
 * Unit tests for inc/route-risk.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;

use function HM\RouteRisk\checked_capabilities;
use function HM\RouteRisk\classify_route;
use function HM\RouteRisk\guidance_for_risk;

class RouteRiskTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/route-risk.php' );
		Functions\when( 'apply_filters' )->alias( fn ( $tag, $value ) => $value );
	}

	public function test_classify_route_is_routine_by_default(): void {
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts', 'GET' ) );
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts', 'POST' ) );
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts/5', 'DELETE' ) );
	}

	/**
	 * @dataProvider site_config_route_provider
	 */
	public function test_classify_route_is_site_config_for_writes_to_config_routes( string $route ): void {
		$this->assertSame( 'site-config', classify_route( $route, 'POST' ) );
		$this->assertSame( 'site-config', classify_route( $route, 'PUT' ) );
		$this->assertSame( 'site-config', classify_route( $route, 'PATCH' ) );
	}

	public function site_config_route_provider(): array {
		return [
			[ '/wp/v2/settings' ],
			[ '/wp/v2/users/5' ],
			[ '/wp/v2/plugins/hello-dolly' ],
			[ '/wp/v2/themes/twentytwentyfive' ],
			[ '/wp/v2/templates/twentytwentyfive//single' ],
			[ '/wp/v2/template-parts/twentytwentyfive//header' ],
			[ '/wp/v2/global-styles/1' ],
			[ '/wp/v2/menus/1' ],
			[ '/wp/v2/menu-items/3' ],
			[ '/wp/v2/navigation/4' ],
			[ '/wp/v2/widgets/block-2' ],
			[ '/wp/v2/sidebars/sidebar-1' ],
			[ '/wp-site-health/v1/tests/background-updates' ],
			[ '/WP/v2/Settings' ],
			[ '/wp/v2/Templates/twentytwentyfive//home' ],
		];
	}

	public function test_classify_route_is_site_config_for_writes_that_check_a_site_config_capability(): void {
		$route = '/elementor-one/v1/plugins/elementor-pro/activate';

		$this->assertSame( 'site-config', classify_route( $route, 'POST', [], [ 'activate_plugin', 'activate_plugins' ] ) );
		$this->assertSame( 'site-config', classify_route( '/acme/v1/settings', 'PATCH', [], [ 'manage_options' ] ) );
		$this->assertSame( 'irreversible', classify_route( '/acme/v1/themes/old', 'DELETE', [], [ 'delete_themes' ] ) );
	}

	public function test_classify_route_ignores_capabilities_that_are_not_site_config(): void {
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts/5', 'POST', [], [ 'edit_post', 'edit_posts', 'edit_others_posts' ] ) );
		$this->assertSame( 'routine', classify_route( '/acme/v1/settings', 'GET', [], [ 'manage_options' ] ) );
	}

	public function test_classify_route_passes_capabilities_to_the_filter(): void {
		$seen = null;
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $risk, $route, $method, $params, $caps ) use ( &$seen ) {
				$seen = $caps;

				return $risk;
			}
		);

		classify_route( '/acme/v1/install', 'POST', [], [ 'install_plugins' ] );

		$this->assertSame( [ 'install_plugins' ], $seen );
	}

	public function test_checked_capabilities_records_what_the_check_asks_for_and_stops_watching(): void {
		$watchers = [];
		Functions\when( 'add_filter' )->alias(
			static function ( string $tag, callable $callback ) use ( &$watchers ) {
				$watchers[ $tag ] = $callback;

				return true;
			}
		);
		Functions\when( 'remove_filter' )->alias(
			static function ( string $tag ) use ( &$watchers ) {
				unset( $watchers[ $tag ] );

				return true;
			}
		);

		$caps = checked_capabilities(
			static function () use ( &$watchers ) {
				$watchers['map_meta_cap']( [ 'activate_plugins' ], 'activate_plugin' );
				$watchers['map_meta_cap']( [ 'edit_posts' ], 'edit_posts' );
			}
		);

		$this->assertSame( [ 'activate_plugin', 'activate_plugins', 'edit_posts' ], $caps );
		$this->assertArrayNotHasKey( 'map_meta_cap', $watchers );
	}

	public function test_classify_route_does_not_flag_reads_of_config_routes(): void {
		$this->assertSame( 'routine', classify_route( '/wp/v2/settings', 'GET' ) );
	}

	public function test_classify_route_is_irreversible_for_deletes_on_config_routes(): void {
		$this->assertSame( 'irreversible', classify_route( '/wp/v2/users/5', 'DELETE' ) );
	}

	public function test_classify_route_is_routine_for_deletes_elsewhere(): void {
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts/5', 'DELETE' ) );
	}

	public function test_classify_route_is_irreversible_for_force_delete(): void {
		$result = classify_route( '/wp/v2/posts/5', 'DELETE', [ 'force' => true ] );

		$this->assertSame( 'irreversible', $result );
	}

	public function test_classify_route_ignores_force_on_methods_other_than_delete(): void {
		// A truthy force param only matters alongside DELETE.
		$this->assertSame( 'routine', classify_route( '/wp/v2/posts/5', 'GET', [ 'force' => true ] ) );
	}

	public function test_classify_route_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $risk, $route, $method, $params ) {
				if ( 'hm_rest_ability_route_risk' !== $tag ) {
					return $risk;
				}

				return 'routine' === $risk && '/wp/v2/posts' === $route && 'GET' === $method && [] === $params
					? 'irreversible'
					: $risk;
			}
		);

		$this->assertSame( 'irreversible', classify_route( '/wp/v2/posts', 'GET' ) );
	}

	public function test_guidance_for_risk_is_empty_for_routine(): void {
		$this->assertSame( '', guidance_for_risk( 'routine' ) );
	}

	public function test_guidance_for_risk_mentions_confirmation_for_site_config(): void {
		$this->assertStringContainsString( 'Confirm with the user', guidance_for_risk( 'site-config' ) );
	}

	public function test_guidance_for_risk_mentions_confirmation_for_irreversible(): void {
		$this->assertStringContainsString( 'Confirm with the user', guidance_for_risk( 'irreversible' ) );
	}
}
