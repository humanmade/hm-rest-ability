<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Loads only the Composer autoloader and lightweight WP class stubs — no
 * WordPress. Functions are mocked per-test via Brain Monkey.
 */

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/wp-stubs.php';
require_once __DIR__ . '/TestCase.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
