<?php
/**
 * Plugin Name:       HM REST Ability
 * Plugin URI:        https://github.com/humanmade/hm-rest-ability
 * Description:       OAuth2 discovery endpoints and a REST API ability, for exposing WordPress to MCP clients via the MCP Adapter.
 * Version:           __VERSION__
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mcp-adapter
 * Author:            Human Made
 * Author URI:        https://humanmade.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hm-rest-ability
 *
 * @package HM\RestAbility
 */

namespace HM\RestAbility;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'HM_REST_ABILITY_VERSION', '__VERSION__' );
define( 'HM_REST_ABILITY_PATH', plugin_dir_path( __FILE__ ) );
define( 'HM_REST_ABILITY_URL', plugin_dir_url( __FILE__ ) );

require_once HM_REST_ABILITY_PATH . 'inc/oauth2-discovery.php';
require_once HM_REST_ABILITY_PATH . 'inc/route-risk.php';
require_once HM_REST_ABILITY_PATH . 'inc/rest-api-abilities.php';
require_once HM_REST_ABILITY_PATH . 'inc/media-abilities.php';
