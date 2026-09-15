<?php
/**
 * Classifies REST API routes by how much confirmation a change to them
 * deserves. This is advice, not enforcement — it never blocks a call, it only
 * feeds guidance text so a client knows when to check with the user first.
 *
 * @package HM\RestAbility
 */

namespace HM\RouteRisk;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Route path prefixes that change what visitors see or who has access.
 *
 * Plugin and theme updates go through `/wp/v2/plugins` and `/wp/v2/themes`
 * (both already listed here) — core has no separate REST route for
 * triggering a WordPress core update, so there is nothing extra to add for
 * that.
 */
const SITE_CONFIG_ROUTE_PREFIXES = [
	'/wp/v2/settings',
	'/wp/v2/users',
	'/wp/v2/plugins',
	'/wp/v2/themes',
	'/wp/v2/templates',
	'/wp/v2/template-parts',
	'/wp/v2/global-styles',
	'/wp/v2/menus',
	'/wp-site-health',
];

/**
 * Classifies a REST API call by how much confirmation it deserves.
 *
 * @param string $route  REST route path, e.g. /wp/v2/posts/123.
 * @param string $method HTTP method.
 * @param array  $params Query or body params, when known. Pass an empty array
 *                        when classifying a route in the abstract, such as
 *                        for an OPTIONS description — this only narrows the
 *                        `force=true` case, it never widens a result.
 * @return string One of `routine`, `site-config`, `irreversible`.
 */
function classify_route( string $route, string $method, array $params = [] ): string {
	$method     = strtoupper( $method );
	$is_delete  = 'DELETE' === $method;
	$is_config  = is_site_config_route( $route );
	$has_force  = $is_delete && ! empty( $params['force'] );

	if ( $has_force || ( $is_delete && $is_config ) ) {
		$risk = 'irreversible';
	} elseif ( $is_config && in_array( $method, [ 'POST', 'PUT', 'PATCH', 'DELETE' ], true ) ) {
		$risk = 'site-config';
	} else {
		$risk = 'routine';
	}

	/**
	 * Filters a route's risk classification.
	 *
	 * @param string $risk   One of `routine`, `site-config`, `irreversible`.
	 * @param string $route  REST route path.
	 * @param string $method HTTP method.
	 * @param array  $params Query or body params, when known.
	 */
	return apply_filters( 'hm_rest_ability_route_risk', $risk, $route, $method, $params );
}

/**
 * Checks whether a route path falls under a site-config prefix.
 *
 * @param string $route REST route path.
 * @return bool
 */
function is_site_config_route( string $route ): bool {
	foreach ( SITE_CONFIG_ROUTE_PREFIXES as $prefix ) {
		if ( 0 === strpos( $route, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Returns one sentence of guidance for a risk tier, or an empty string for
 * `routine`.
 *
 * @param string $risk One of `routine`, `site-config`, `irreversible`.
 * @return string
 */
function guidance_for_risk( string $risk ): string {
	switch ( $risk ) {
		case 'irreversible':
			return 'This cannot be undone. Confirm with the user before calling, and say exactly what will be removed.';
		case 'site-config':
			return 'This changes site settings or access. Confirm with the user before calling, and say what will change.';
		default:
			return '';
	}
}
