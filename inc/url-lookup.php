<?php
/**
 * Adds a REST endpoint that finds the post, page or attachment at a URL.
 *
 * @package HM\RestAbility
 */

namespace HM\UrlLookup;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', __NAMESPACE__ . '\\register_route' );

/**
 * Registers GET /hm-rest-ability/v1/url-lookup.
 */
function register_route(): void {
	register_rest_route(
		'hm-rest-ability/v1',
		'/url-lookup',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\lookup',
			'permission_callback' => '__return_true',
			'args'                => [
				'url' => [
					'description' => 'A full URL or a path on this site, e.g. https://example.com/about/ or /about/.',
					'type'        => 'string',
					'required'    => true,
				],
			],
		]
	);
}

/**
 * Returns the ID and post type of the item at a URL, with a `self` link to
 * its own REST resource.
 *
 * Only items the current user can read are found, so a draft's URL answers
 * the same as a URL with nothing behind it.
 *
 * @param WP_REST_Request $request Request.
 * @return WP_REST_Response|WP_Error
 */
function lookup( WP_REST_Request $request ) {
	$url = local_url( (string) $request->get_param( 'url' ) );
	$id  = (int) url_to_postid( $url );

	if ( $id <= 0 ) {
		$id = (int) attachment_url_to_postid( $url );
	}

	if ( $id <= 0 || ! ( is_post_publicly_viewable( $id ) || current_user_can( 'read_post', $id ) ) ) {
		return new WP_Error( 'rest_url_not_found', 'No post, page or file on this site lives at that URL.', [ 'status' => 404 ] );
	}

	$response = new WP_REST_Response(
		[
			'id'   => $id,
			'type' => get_post_type( $id ),
		]
	);

	$route = rest_get_route_for_post( $id );

	if ( '' !== $route ) {
		$response->add_link( 'self', rest_url( $route ), [ 'embeddable' => true ] );
	}

	return $response;
}

/**
 * Rebuilds a URL on this site's home URL, keeping its path and query string.
 *
 * `url_to_postid()` returns nothing for a URL on another host, such as a
 * production address looked up on a staging site.
 *
 * @param string $url URL or path.
 * @return string
 */
function local_url( string $url ): string {
	$parts     = wp_parse_url( trim( $url ) );
	$path      = $parts['path'] ?? '/';
	$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );

	// On a subdirectory install, a full URL already carries the home path.
	if ( strlen( $home_path ) > 1 && str_starts_with( $path, $home_path ) ) {
		$path = substr( $path, strlen( $home_path ) - 1 );
	}

	$local = home_url( $path );

	if ( ! empty( $parts['query'] ) ) {
		$local .= '?' . $parts['query'];
	}

	return $local;
}
