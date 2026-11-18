<?php
/**
 * Lets a `rest-api-write` call change part of a post's content without
 * sending all of it. The client sends `_content_edits` and `_content_sha256`
 * instead of `content`, and this turns them into a normal `content` param
 * before the permission checks, the policy filter and the request see it.
 *
 * The checks run here, on the server, because the client may be a model that
 * skips steps: the content must not have changed since the client read it,
 * each edit must match the expected number of times, and the edit must not
 * break the nesting of block comments.
 *
 * @package HM\RestAbility
 */

namespace HM\ContentEdits;

use stdClass;
use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Param holding the list of edits.
 */
const EDITS_PARAM = '_content_edits';

/**
 * Param holding the SHA-256 of the content the edits were written against.
 */
const HASH_PARAM = '_content_sha256';

/**
 * Replaces `_content_edits` and `_content_sha256` in a write's params with the
 * edited `content`.
 *
 * @param array $input Tool input, keyed by `method`, `route`, `params`.
 * @return array|WP_Error The input, with `content` in place of the edit params.
 *                        Input without edit params comes back unchanged.
 */
function apply_to_input( array $input ): array|WP_Error {
	$params = $input['params'] ?? [];

	if ( ! is_array( $params ) || ( ! array_key_exists( EDITS_PARAM, $params ) && ! array_key_exists( HASH_PARAM, $params ) ) ) {
		return $input;
	}

	if ( ! in_array( strtoupper( (string) ( $input['method'] ?? '' ) ), [ 'POST', 'PUT', 'PATCH' ], true ) ) {
		return error( 'hm_content_edits_method', '_content_edits only works on a write. Use rest-api-write with POST.', 400 );
	}

	if ( array_key_exists( 'content', $params ) ) {
		return error( 'hm_content_edits_with_content', 'Send either content or _content_edits, not both.', 400 );
	}

	$edits = validate_edits( $params[ EDITS_PARAM ] ?? null );

	if ( is_wp_error( $edits ) ) {
		return $edits;
	}

	$content_hash = $params[ HASH_PARAM ] ?? null;

	if ( ! is_string( $content_hash ) || '' === $content_hash ) {
		return error( 'hm_content_edits_no_hash', '_content_sha256 is missing. Read the item with context=edit and pass the content_sha256 it returns.', 400 );
	}

	$route = (string) ( $input['route'] ?? '' );
	$raw   = read_content( $route );

	if ( is_wp_error( $raw ) ) {
		return $raw;
	}

	if ( ! hash_equals( hash( 'sha256', $raw ), $content_hash ) ) {
		return error( 'hm_content_changed', 'The content changed since you read it. Nothing was saved. Read the item again with context=edit and use its content_sha256.', 409 );
	}

	$content = apply_edits( $raw, $edits );

	if ( is_wp_error( $content ) ) {
		return $content;
	}

	$new_errors = new_nesting_errors( $raw, $content );

	if ( null === $new_errors ) {
		return error( 'hm_content_edits_scan_failed', 'The block comments could not be checked, so nothing was saved. Send the whole content instead.', 422 );
	}

	if ( $new_errors ) {
		return error(
			'hm_content_edits_break_blocks',
			sprintf(
				'These edits would break the nesting of block comments: %s. Nothing was saved. Include the matching opening and closing comment of each block you move or remove.',
				implode( '; ', $new_errors )
			),
			422
		);
	}

	unset( $params[ EDITS_PARAM ], $params[ HASH_PARAM ] );
	$params['content'] = $content;
	$input['params']   = $params;

	return $input;
}

/**
 * Checks the shape of `_content_edits`.
 *
 * @param mixed $edits Value of the `_content_edits` param.
 * @return array<int, array{old_string: string, new_string: string, expected_matches: int}>|WP_Error
 */
function validate_edits( $edits ): array|WP_Error {
	$shape = '_content_edits must be a list of objects, each with old_string, new_string and an optional expected_matches.';

	if ( ! is_array( $edits ) || ! $edits || ! array_is_list( $edits ) ) {
		return error( 'hm_content_edits_invalid', $shape, 400 );
	}

	$valid = [];

	foreach ( $edits as $index => $edit ) {
		$number   = $index + 1;
		$old      = is_array( $edit ) ? ( $edit['old_string'] ?? null ) : null;
		$new      = is_array( $edit ) ? ( $edit['new_string'] ?? null ) : null;
		$expected = is_array( $edit ) ? ( $edit['expected_matches'] ?? 1 ) : null;

		if ( ! is_string( $old ) || '' === $old || ! is_string( $new ) ) {
			return error( 'hm_content_edits_invalid', sprintf( 'Edit %d needs a non-empty old_string and a new_string. %s', $number, $shape ), 400 );
		}

		if ( ! is_int( $expected ) || $expected < 1 ) {
			return error( 'hm_content_edits_invalid', sprintf( 'Edit %d: expected_matches must be a whole number of 1 or more.', $number ), 400 );
		}

		$valid[] = [
			'old_string'       => $old,
			'new_string'       => $new,
			'expected_matches' => $expected,
		];
	}

	return $valid;
}

/**
 * Applies each edit in turn, so a later edit sees the result of an earlier one.
 *
 * @param string $content Content to edit.
 * @param array  $edits   Edits from validate_edits().
 * @return string|WP_Error
 */
function apply_edits( string $content, array $edits ): string|WP_Error {
	foreach ( $edits as $index => $edit ) {
		$count = substr_count( $content, $edit['old_string'] );

		if ( $count !== $edit['expected_matches'] ) {
			$message = sprintf( 'Edit %d: found %d matches for old_string, expected %d. Nothing was saved.', $index + 1, $count, $edit['expected_matches'] );

			if ( 0 === $count ) {
				$message .= ' Copy old_string exactly from the content, including whitespace.';
			} elseif ( $count > $edit['expected_matches'] ) {
				$message .= sprintf( ' Include more surrounding text so it matches once, or set expected_matches to %d to replace them all.', $count );
			}

			return error( 'hm_content_edits_match_count', $message, 409 );
		}

		$content = str_replace( $edit['old_string'], $edit['new_string'], $content );
	}

	return $content;
}

/**
 * Reads a route with context=edit and returns its raw content.
 *
 * @param string $route REST route of one item.
 * @return string|WP_Error
 */
function read_content( string $route ): string|WP_Error {
	$response = rest_do_request( \HM\RestApiAbilities\build_request( 'GET', $route, [ 'context' => 'edit' ] ) );

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = rest_get_server()->response_to_data( $response, false );

	if ( $response->is_error() ) {
		return error(
			(string) ( $data['code'] ?? 'hm_content_edits_read_failed' ),
			(string) ( $data['message'] ?? 'Reading the content failed.' ),
			$response->get_status()
		);
	}

	$raw = content_raw( $data );

	if ( null === $raw ) {
		return error( 'hm_content_edits_no_content', 'This route has no editable content. Use _content_edits on a single post route such as /wp/v2/pages/123.', 400 );
	}

	return $raw;
}

/**
 * Returns the raw content of a response body, or null if it has none.
 *
 * The body and its `content` can each be an array or a stdClass object.
 *
 * @param mixed $data Response data.
 * @return string|null
 */
function content_raw( mixed $data ): ?string {
	foreach ( [ 'content', 'raw' ] as $key ) {
		if ( $data instanceof stdClass ) {
			$data = (array) $data;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		$data = $data[ $key ] ?? null;
	}

	return is_string( $data ) ? $data : null;
}

/**
 * Returns the nesting errors in the edited content that the original did not
 * have.
 *
 * @param string $before Content before the edits.
 * @param string $after  Content after the edits.
 * @return string[]|null Null if either content could not be scanned.
 */
function new_nesting_errors( string $before, string $after ): ?array {
	$before_errors = nesting_errors( $before );
	$after_errors  = nesting_errors( $after );

	if ( null === $before_errors || null === $after_errors ) {
		return null;
	}

	$existing = array_count_values( $before_errors );
	$new      = [];

	foreach ( $after_errors as $problem ) {
		if ( ! empty( $existing[ $problem ] ) ) {
			--$existing[ $problem ];
			continue;
		}

		$new[] = $problem;
	}

	return $new;
}

/**
 * Walks the block comments in order and describes each one that doesn't nest.
 *
 * Self-closing blocks are skipped. A name without a namespace is a core block.
 *
 * @param string $content Block markup.
 * @return string[]|null Null if the content could not be scanned.
 */
function nesting_errors( string $content ): ?array {
	$scanned = preg_match_all(
		'#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)((?:\s(?:[^-]++|-(?!->))*+)?)-->#s',
		$content,
		$comments,
		PREG_SET_ORDER
	);

	if ( false === $scanned ) {
		return null;
	}

	$open   = [];
	$errors = [];

	foreach ( $comments as $comment ) {
		$is_closer = '/' === $comment[1];

		if ( ! $is_closer && str_ends_with( rtrim( $comment[3] ), '/' ) ) {
			continue;
		}

		$name = false === strpos( $comment[2], '/' ) ? 'core/' . $comment[2] : $comment[2];

		if ( ! $is_closer ) {
			$open[] = $name;
			continue;
		}

		$depth = array_search( $name, array_reverse( $open ), true );

		if ( false === $depth ) {
			$errors[] = sprintf( 'a %s closing comment has no opening comment before it', $name );
			continue;
		}

		for ( $i = 0; $i < $depth; $i++ ) {
			$errors[] = sprintf( '%s is still open when %s closes', array_pop( $open ), $name );
		}

		array_pop( $open );
	}

	foreach ( $open as $name ) {
		$errors[] = sprintf( '%s is never closed', $name );
	}

	return $errors;
}

/**
 * Builds an error the MCP client can act on.
 *
 * @param string $code    Error code.
 * @param string $message What went wrong and what to do.
 * @param int    $status  HTTP status.
 * @return WP_Error
 */
function error( string $code, string $message, int $status ): WP_Error {
	return new WP_Error( $code, $message, [ 'status' => $status ] );
}
