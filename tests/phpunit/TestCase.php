<?php
/**
 * Shared Brain Monkey test case for the unit test suite.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as Polyfill_TestCase;

abstract class TestCase extends Polyfill_TestCase {

	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
	}

	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Requires a plugin `inc/*.php` file, stubbing the hook-registration
	 * functions it calls at the top level. `require_once` means this only
	 * really matters for whichever test runs first — after that the file's
	 * functions already exist and later tests call them directly.
	 */
	protected function load_plugin_file( string $relative_path ): void {
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/' . $relative_path;
	}
}
