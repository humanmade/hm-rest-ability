<?php
/**
 * Adds guidance for core's global styles and block pattern routes, whose
 * paths don't say where their parameters come from or which kind of pattern
 * they hold. Registered on the `hm_rest_ability_route_guidance` filter, the
 * same as the `status` and `content` field examples.
 *
 * @package HM\RestAbility
 */

namespace HM\DesignRouteGuidance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'hm_rest_ability_route_guidance', __NAMESPACE__ . '\\add_guidance', 10, 3 );

/**
 * Appends guidance for a global styles or pattern route to the guidance
 * already assembled for it.
 *
 * @param string $guidance Guidance text so far, or ''.
 * @param string $route    REST route path.
 * @param array  $handlers Route handlers, as returned by
 *                         WP_REST_Server::get_routes().
 * @return string
 */
function add_guidance( string $guidance, string $route, array $handlers ): string {
	$note = note_for_route( $route );

	if ( '' === $note ) {
		return $guidance;
	}

	return '' === $guidance ? $note : $guidance . ' ' . $note;
}

/**
 * Returns the note for a route, or '' for a route this module doesn't cover.
 *
 * @param string $route REST route path.
 * @return string
 */
function note_for_route( string $route ): string {
	$route = '/' . strtolower( trim( $route, '/' ) );

	if ( 0 === strpos( $route, '/wp/v2/global-styles/themes/' ) ) {
		return 'The stylesheet is the theme directory slug, returned as `stylesheet` by GET /wp/v2/themes?status=active. This route holds the theme\'s own theme.json styles; user changes made in the site editor live at /wp/v2/global-styles/{id}.';
	}

	if ( 0 === strpos( $route, '/wp/v2/global-styles/' ) ) {
		return 'The id is the site\'s user global styles post, not a theme name. GET /wp/v2/themes?status=active returns the active theme, and its _links["wp:user-global-styles"][0].href ends in that id. The theme\'s own theme.json styles are at /wp/v2/global-styles/themes/{stylesheet}.';
	}

	if ( '/wp/v2/block-patterns/patterns' === $route ) {
		return 'These are the patterns registered in code by the theme and plugins. Synced patterns users create in the editor are wp_block posts, at GET /wp/v2/blocks.';
	}

	if ( 0 === strpos( $route, '/wp/v2/blocks' ) ) {
		return 'These are synced patterns users create in the editor. Patterns registered in code by the theme and plugins are at GET /wp/v2/block-patterns/patterns.';
	}

	return '';
}
