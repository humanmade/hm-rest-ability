<?php
/**
 * Unit tests for the plugin bootstrap in hm-rest-ability.php.
 *
 * Each test runs in its own process: the plugin file defines constants and
 * loads modules with `require_once`, and the AI check stubs a core function.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class BootstrapTest extends TestCase {

	/**
	 * Loads the main plugin file and returns the hooks it and its modules add
	 * once bootstrap() has run, as hook names.
	 *
	 * @return string[]
	 */
	private function run_bootstrap(): array {
		$hooks = [];
		$add   = static function ( string $hook ) use ( &$hooks ): bool {
			$hooks[] = $hook;

			return true;
		};

		Functions\when( 'add_action' )->alias( $add );
		Functions\when( 'add_filter' )->alias( $add );
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'plugin_dir_url' )->justReturn( 'https://example.com/wp-content/plugins/hm-rest-ability/' );

		require dirname( __DIR__, 2 ) . '/hm-rest-ability.php';

		\HM\RestAbility\bootstrap();

		return $hooks;
	}

	public function test_bootstrap_is_hooked_to_plugins_loaded(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( true );

		$this->assertContains( 'plugins_loaded', $this->run_bootstrap() );
	}

	public function test_bootstrap_registers_nothing_when_ai_is_not_supported(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( false );

		$hooks = $this->run_bootstrap();

		$this->assertSame( [ 'plugins_loaded' ], $hooks );
		$this->assertFalse( function_exists( 'HM\\RestApiAbilities\\register_ability' ) );
		$this->assertFalse( function_exists( 'HM\\Confirmation\\filter_mcp_server_config' ) );
		$this->assertFalse( function_exists( 'HM\\MediaAbilities\\register_ability' ) );
	}

	public function test_bootstrap_registers_the_abilities_when_ai_is_supported(): void {
		Functions\when( 'wp_supports_ai' )->justReturn( true );

		$hooks = $this->run_bootstrap();

		$this->assertContains( 'wp_abilities_api_init', $hooks );
		$this->assertContains( 'wp_register_ability_args', $hooks );
		$this->assertContains( 'mcp_adapter_default_server_config', $hooks );
		$this->assertTrue( function_exists( 'HM\\RestApiAbilities\\register_ability' ) );
	}

	public function test_bootstrap_registers_the_abilities_when_core_has_no_ai_switch(): void {
		$this->assertFalse( function_exists( 'wp_supports_ai' ) );

		$hooks = $this->run_bootstrap();

		$this->assertContains( 'wp_abilities_api_init', $hooks );
		$this->assertTrue( function_exists( 'HM\\RestApiAbilities\\register_ability' ) );
	}
}
