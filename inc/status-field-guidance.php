<?php
/**
 * Adds guidance about a route's `status` field, for the specific shape
 * WordPress core's post-type routes use. Registered on the
 * `hm_rest_ability_route_guidance` filter as a working example of extending
 * it — this is advice, not enforcement, the same as the built-in risk
 * guidance it adds to.
 *
 * @package HM\RestAbility
 */

namespace HM\StatusFieldGuidance;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'hm_rest_ability_route_guidance', __NAMESPACE__ . '\\add_guidance', 10, 3 );

/**
 * Appends guidance about a route's `status` field to the guidance already
 * assembled for it, when the route's schema has one.
 *
 * @param string $guidance Guidance text so far, or ''.
 * @param string $route    REST route path.
 * @param array  $handlers Route handlers, as returned by
 *                         WP_REST_Server::get_routes().
 * @return string
 */
function add_guidance( string $guidance, string $route, array $handlers ): string {
	if ( ! has_publishable_status_field( $handlers ) ) {
		return $guidance;
	}

	$note = 'Creating an item here already defaults to draft when status is omitted. Only set status to publish (or another publicly-visible value) if the user explicitly asked for that.';

	return '' === $guidance ? $note : $guidance . ' ' . $note;
}

/**
 * Checks whether any handler registers a `status` argument shaped like a
 * WordPress post status: a string with an enum that includes `publish`.
 *
 * Core's post-type routes register `status` this way (the enum comes from
 * `get_post_stati()`), and `wp_insert_post()` already defaults a new post to
 * `draft` when it's omitted. Other routes name a field `status` too — the
 * REST comments endpoint, for one — but without this enum shape, so they
 * don't trigger this guidance.
 *
 * @param array $handlers Route handlers.
 * @return bool
 */
function has_publishable_status_field( array $handlers ): bool {
	foreach ( $handlers as $handler ) {
		$status = $handler['args']['status'] ?? null;

		if ( ! is_array( $status ) ) {
			continue;
		}

		if ( 'string' !== ( $status['type'] ?? '' ) ) {
			continue;
		}

		if ( in_array( 'publish', (array) ( $status['enum'] ?? [] ), true ) ) {
			return true;
		}
	}

	return false;
}
