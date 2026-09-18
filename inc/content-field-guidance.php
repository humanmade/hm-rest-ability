<?php
/**
 * Adds guidance about a route's `content` field, for the specific shape
 * WordPress core's editor-backed post-type routes use. Registered on the
 * `hm_rest_ability_route_guidance` filter, alongside the `status` field
 * example — this is advice, not enforcement, the same as the built-in risk
 * guidance it adds to.
 *
 * @package HM\RestAbility
 */

namespace HM\ContentFieldGuidance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'hm_rest_ability_route_guidance', __NAMESPACE__ . '\\add_guidance', 10, 3 );

/**
 * Appends guidance about a route's `content` field to the guidance already
 * assembled for it, when the route stores block markup there.
 *
 * @param string $guidance Guidance text so far, or ''.
 * @param string $route    REST route path.
 * @param array  $handlers Route handlers, as returned by
 *                         WP_REST_Server::get_routes().
 * @return string
 */
function add_guidance( string $guidance, string $route, array $handlers ): string {
	if ( ! has_block_content_field( $handlers ) ) {
		return $guidance;
	}

	$note = 'The content field holds WordPress block markup. GET /wp/v2/block-types lists the blocks this site has registered, with the attributes each one accepts.';

	return '' === $guidance ? $note : $guidance . ' ' . $note;
}

/**
 * Checks whether any handler registers a `content` argument shaped like
 * editor-backed post content: an object with a `block_version` property.
 *
 * Core adds `block_version` to the content schema only for post types that
 * support the editor, so this covers custom post types without a hardcoded
 * list, and skips routes that merely name a field `content` — the REST
 * comments endpoint, for one, whose content object has no `block_version`.
 *
 * @param array $handlers Route handlers.
 * @return bool
 */
function has_block_content_field( array $handlers ): bool {
	foreach ( $handlers as $handler ) {
		$content = $handler['args']['content'] ?? null;

		if ( ! is_array( $content ) ) {
			continue;
		}

		if ( 'object' !== ( $content['type'] ?? '' ) ) {
			continue;
		}

		if ( array_key_exists( 'block_version', (array) ( $content['properties'] ?? [] ) ) ) {
			return true;
		}
	}

	return false;
}
