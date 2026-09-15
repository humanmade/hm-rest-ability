<?php
/**
 * Unit tests for inc/route-risk.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;

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
			[ '/wp-site-health/v1/tests/background-updates' ],
		];
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
