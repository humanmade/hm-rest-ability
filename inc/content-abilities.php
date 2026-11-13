<?php
/**
 * Exposes "find in content" and "replace in content" abilities, so MCP clients
 * can edit part of a large post without sending the whole content back.
 *
 * The checks that keep a weak client safe run here, on the server: the content
 * must not have changed since the client read it, the match count must be what
 * the client expected, block markup must stay balanced, and a save is read back
 * to confirm it.
 *
 * @package HM\RestAbility
 */

namespace HM\ContentAbilities;

use WP_Error;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lines of context around a match in the `changes` a replace returns.
 */
const CHANGE_CONTEXT_LINES = 2;

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_abilities' );
add_filter( 'mcp_adapter_default_server_config', __NAMESPACE__ . '\\filter_mcp_server_config' );

/**
 * Registers the "Content" ability category.
 */
function register_category(): void {
	wp_register_ability_category(
		'content',
		[
			'label'       => 'Content',
			'description' => 'Post content editing',
		]
	);
}

/**
 * Registers the find and replace abilities.
 */
function register_abilities(): void {
	$route_schema = [
		'type'        => 'string',
		'description' => 'REST route of one item, e.g. /wp/v2/pages/123. It must return content.raw when read with context=edit.',
	];

	wp_register_ability(
		'content/find',
		[
			'label'               => 'Find in Content',
			'description'         => 'Find exact text in a post\'s content and return each match with its line numbers and the lines around it. Use this before content-replace: copy old_string from an excerpt, and pass back the modified_gmt it returns. Nothing is changed.',
			'category'            => 'content',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'route'         => $route_schema,
					'search'        => [
						'type'        => 'string',
						'description' => 'Text to find. An exact, case-sensitive match that may span several lines.',
					],
					'context_lines' => [
						'type'        => 'integer',
						'minimum'     => 0,
						'default'     => 2,
						'description' => 'Lines to include before and after each match.',
					],
				],
				'required'   => [ 'route', 'search' ],
			],
			'permission_callback' => __NAMESPACE__ . '\\check_permission',
			'execute_callback'    => __NAMESPACE__ . '\\find',
			'meta'                => [
				'mcp'         => [
					'public' => true,
					'type'   => 'tool',
				],
				'annotations' => [
					'readonly'    => true,
					'destructive' => false,
					'idempotent'  => true,
				],
			],
		]
	);

	wp_register_ability(
		'content/replace',
		[
			'label'               => 'Replace in Content',
			'description'         => 'Replace exact text in a post\'s content without sending the whole content. Call content-find first, copy old_string from an excerpt, and pass its modified_gmt. The call refuses if the content changed since you read it, if old_string does not match expected_matches times, or if it would unbalance block comments. By default it is a dry run that shows the changed lines and saves nothing. Set dry_run to false to save.',
			'category'            => 'content',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'route'            => $route_schema,
					'old_string'       => [
						'type'        => 'string',
						'minLength'   => 1,
						'description' => 'Exact text to replace, copied from a content-find excerpt. Include enough surrounding text to match once.',
					],
					'new_string'       => [
						'type'        => 'string',
						'description' => 'Text to put in its place. May be empty to delete old_string.',
					],
					'expected_matches' => [
						'type'        => 'integer',
						'minimum'     => 1,
						'default'     => 1,
						'description' => 'How many times old_string must match. All matches are replaced.',
					],
					'modified_gmt'     => [
						'type'        => 'string',
						'description' => 'The modified_gmt that content-find returned.',
					],
					'dry_run'          => [
						'type'        => 'boolean',
						'default'     => true,
						'description' => 'When true, show the changes and save nothing.',
					],
				],
				'required'   => [ 'route', 'old_string', 'new_string', 'modified_gmt' ],
			],
			'permission_callback' => __NAMESPACE__ . '\\check_permission',
			'execute_callback'    => __NAMESPACE__ . '\\replace',
			'meta'                => [
				'mcp'         => [
					'public' => true,
					'type'   => 'tool',
				],
				'annotations' => [
					'readonly'    => false,
					'destructive' => false,
					'idempotent'  => false,
				],
			],
		]
	);
}

/**
 * Checks whether the current user can read the route with context=edit.
 *
 * Both abilities start with that read. replace() checks the save separately,
 * once it knows what it will send.
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function check_permission( array $input ): bool|WP_Error {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'Authentication required.' );
	}

	return \HM\RestApiAbilities\check_permission( [
		'method' => 'GET',
		'route'  => (string) ( $input['route'] ?? '' ),
		'params' => [ 'context' => 'edit' ],
	] );
}

/**
 * Finds text in a post's content.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function find( array $input ): array|WP_Error {
	$route   = (string) ( $input['route'] ?? '' );
	$search  = (string) ( $input['search'] ?? '' );
	$context = max( 0, (int) ( $input['context_lines'] ?? 2 ) );

	if ( '' === $search ) {
		return new WP_Error( 'hm_content_empty_search', 'search is empty. Give the exact text to look for.' );
	}

	$post = read_content( $route );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	$raw     = $post['raw'];
	$lines   = explode( "\n", $raw );
	$matches = [];

	foreach ( match_offsets( $raw, $search ) as $offset ) {
		$matches[] = describe_match( $raw, $lines, $offset, strlen( $search ), $context );
	}

	return finish(
		[
			'route'         => $route,
			'modified_gmt'  => $post['modified_gmt'],
			'content_bytes' => strlen( $raw ),
			'line_count'    => '' === $raw ? 0 : count( $lines ),
			'match_count'   => count( $matches ),
			'matches'       => $matches,
		],
		'There are too many matches to list. Search for a longer piece of text, or set context_lines to 0.'
	);
}

/**
 * Replaces text in a post's content, or shows what would change.
 *
 * @param array $input Ability input.
 * @return array|WP_Error
 */
function replace( array $input ): array|WP_Error {
	$route        = (string) ( $input['route'] ?? '' );
	$old          = (string) ( $input['old_string'] ?? '' );
	$new_string   = (string) ( $input['new_string'] ?? '' );
	$expected     = max( 1, (int) ( $input['expected_matches'] ?? 1 ) );
	$modified_gmt = (string) ( $input['modified_gmt'] ?? '' );
	$dry_run      = (bool) ( $input['dry_run'] ?? true );

	if ( '' === $old ) {
		return new WP_Error( 'hm_content_empty_old_string', 'old_string is empty. Copy the exact text to replace from a content-find excerpt.' );
	}

	$post = read_content( $route );

	if ( is_wp_error( $post ) ) {
		return $post;
	}

	if ( null === $post['modified_gmt'] ) {
		return new WP_Error(
			'hm_content_no_version',
			'This route does not report modified_gmt, so the edit cannot be checked against other changes. Use rest-api-write instead.',
			[ 'status' => 400 ]
		);
	}

	if ( $post['modified_gmt'] !== $modified_gmt ) {
		return new WP_Error(
			'hm_content_changed',
			sprintf(
				'The content changed at %s since you read it at %s. Call content-find again and use its modified_gmt.',
				$post['modified_gmt'],
				$modified_gmt
			),
			[ 'status' => 409 ]
		);
	}

	$raw   = $post['raw'];
	$count = substr_count( $raw, $old );

	if ( $count !== $expected ) {
		$message = sprintf( 'Found %d matches for old_string, expected %d.', $count, $expected );

		if ( 0 === $count ) {
			$message .= ' Copy old_string exactly from a content-find excerpt, including whitespace.';
		} elseif ( $count > $expected ) {
			$message .= sprintf( ' Include more surrounding text so it matches once, or set expected_matches to %d to replace them all.', $count );
		}

		return new WP_Error( 'hm_content_match_count', $message, [ 'status' => 409 ] );
	}

	$new = str_replace( $old, $new_string, $raw );

	$unbalanced = unbalanced_blocks( $raw, $new );

	if ( $unbalanced ) {
		$names = [];
		foreach ( $unbalanced as $name => $counts ) {
			$names[] = sprintf( '%s (%d opening, %d closing)', $name, $counts['open'], $counts['close'] );
		}

		return new WP_Error(
			'hm_content_unbalanced_blocks',
			sprintf(
				'This edit would leave block comments unbalanced for %s. Nothing was saved. Include the matching opening and closing comment of each block in old_string and new_string, so both are replaced together.',
				implode( ', ', $names )
			),
			[ 'status' => 422 ]
		);
	}

	$write = [
		'method' => 'POST',
		'route'  => $route,
		'params' => [ 'content' => $new ],
	];

	if ( function_exists( 'HM\\Confirmation\\needs_confirmation' ) && \HM\Confirmation\needs_confirmation( $write ) ) {
		return new WP_Error(
			'hm_content_needs_confirmation',
			'Writes to this route need the user to confirm them. Use rest-api-write instead.',
			[ 'status' => 403 ]
		);
	}

	$delta   = strlen( $new_string ) - strlen( $old );
	$changes = [];
	$lines   = explode( "\n", $new );

	foreach ( match_offsets( $raw, $old ) as $index => $offset ) {
		$changes[] = describe_match( $new, $lines, $offset + $index * $delta, strlen( $new_string ), CHANGE_CONTEXT_LINES );
	}

	if ( $dry_run ) {
		return finish(
			[
				'dry_run'              => true,
				'match_count'          => $count,
				'content_bytes_before' => strlen( $raw ),
				'content_bytes_after'  => strlen( $new ),
				'changes'              => $changes,
				'next'                 => 'Nothing was saved. Call again with dry_run false to save.',
			],
			'The changes are too long to list. Nothing was saved.'
		);
	}

	$allowed = \HM\RestApiAbilities\check_permission( $write );

	if ( true !== $allowed ) {
		return is_wp_error( $allowed )
			? $allowed
			: new WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to save changes to this content.', [ 'status' => 403 ] );
	}

	$response = rest_do_request(
		\HM\RestApiAbilities\build_request(
			'POST',
			$route,
			[
				'content' => $new,
				'context' => 'edit',
			]
		)
	);
	$saved    = response_data( $response );

	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	$saved_raw = $saved['content']['raw'] ?? null;
	$verified  = is_string( $saved_raw ) && $saved_raw === $new;

	$result = [
		'dry_run'              => false,
		'status'               => $response->get_status(),
		'modified_gmt'         => is_string( $saved['modified_gmt'] ?? null ) ? $saved['modified_gmt'] : null,
		'match_count'          => $count,
		'content_bytes_before' => strlen( $raw ),
		'content_bytes_after'  => strlen( $new ),
		'sha256'               => is_string( $saved_raw ) ? hash( 'sha256', $saved_raw ) : null,
		'verified'             => $verified,
		'changes'              => $changes,
	];

	if ( ! $verified ) {
		$result['message'] = 'The saved content differs from what was sent, usually because a filter changed it. Check it with content-find.';
	}

	return finish( $result, 'The changes are too long to list. The content was saved. Check it with content-find.' );
}

/**
 * Reads a route with context=edit and returns its raw content.
 *
 * @param string $route REST route of one item.
 * @return array{raw: string, modified_gmt: ?string}|WP_Error
 */
function read_content( string $route ): array|WP_Error {
	$data = response_data(
		rest_do_request( \HM\RestApiAbilities\build_request( 'GET', $route, [ 'context' => 'edit' ] ) )
	);

	if ( is_wp_error( $data ) ) {
		return $data;
	}

	$raw = $data['content']['raw'] ?? null;

	if ( ! is_string( $raw ) ) {
		return new WP_Error(
			'hm_content_no_content',
			'This route has no editable content. Use a single post route such as /wp/v2/pages/123.',
			[ 'status' => 400 ]
		);
	}

	return [
		'raw'          => $raw,
		'modified_gmt' => is_string( $data['modified_gmt'] ?? null ) ? $data['modified_gmt'] : null,
	];
}

/**
 * Turns a REST response into its data, or into a WP_Error when it failed.
 *
 * @param mixed $response Result of rest_do_request().
 * @return array|WP_Error
 */
function response_data( $response ): array|WP_Error {
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$data = rest_get_server()->response_to_data( $response, false );

	if ( $response->is_error() ) {
		return new WP_Error(
			(string) ( $data['code'] ?? 'hm_content_request_failed' ),
			(string) ( $data['message'] ?? 'The request failed.' ),
			[ 'status' => $response->get_status() ]
		);
	}

	return is_array( $data ) ? $data : [];
}

/**
 * Returns the byte offset of every non-overlapping match, as str_replace()
 * would find them.
 *
 * @param string $haystack Text to search.
 * @param string $needle   Non-empty text to find.
 * @return int[]
 */
function match_offsets( string $haystack, string $needle ): array {
	$offsets  = [];
	$position = strpos( $haystack, $needle );

	while ( false !== $position ) {
		$offsets[] = $position;
		$position  = strpos( $haystack, $needle, $position + strlen( $needle ) );
	}

	return $offsets;
}

/**
 * Describes one match: the lines it spans, and an excerpt around it.
 *
 * @param string   $content Text the match is in.
 * @param string[] $lines   $content split on newlines.
 * @param int      $offset  Byte offset of the match.
 * @param int      $length  Length of the match in bytes. Zero for a deletion.
 * @param int      $context Lines to include before and after.
 * @return array{start_line: int, end_line: int, excerpt_start_line: int, excerpt: string}
 */
function describe_match( string $content, array $lines, int $offset, int $length, int $context ): array {
	$start = 1 + newlines_before( $content, $offset );
	$end   = $length > 0 ? 1 + newlines_before( $content, $offset + $length - 1 ) : $start;
	$from  = max( 1, $start - $context );
	$to    = min( count( $lines ), $end + $context );

	return [
		'start_line'         => $start,
		'end_line'           => $end,
		'excerpt_start_line' => $from,
		'excerpt'            => implode( "\n", array_slice( $lines, $from - 1, $to - $from + 1 ) ),
	];
}

/**
 * Counts the newlines before a byte offset.
 *
 * @param string $content Text to count in.
 * @param int    $offset  Byte offset.
 * @return int
 */
function newlines_before( string $content, int $offset ): int {
	return $offset > 0 ? substr_count( $content, "\n", 0, $offset ) : 0;
}

/**
 * Returns the blocks an edit would leave with unbalanced comments.
 *
 * Counts opening and closing block comments per block name. A block is
 * reported when its opening and closing counts no longer match, unless the
 * edit left the difference as it was.
 *
 * @param string $before Content before the edit.
 * @param string $after  Content after the edit.
 * @return array<string, array{open: int, close: int}> Counts in the new content, by block name.
 */
function unbalanced_blocks( string $before, string $after ): array {
	$before_counts = block_counts( $before );
	$unbalanced    = [];

	foreach ( block_counts( $after ) as $name => $counts ) {
		$difference = $counts['open'] - $counts['close'];
		$was        = isset( $before_counts[ $name ] ) ? $before_counts[ $name ]['open'] - $before_counts[ $name ]['close'] : 0;

		if ( 0 !== $difference && $difference !== $was ) {
			$unbalanced[ $name ] = $counts;
		}
	}

	return $unbalanced;
}

/**
 * Counts opening and closing block comments by block name.
 *
 * Self-closing blocks are not counted. A name without a namespace is a core
 * block.
 *
 * @param string $content Block markup.
 * @return array<string, array{open: int, close: int}>
 */
function block_counts( string $content ): array {
	$counts = [];

	preg_match_all(
		'#<!--\s+(/)?wp:([a-z][a-z0-9_-]*(?:/[a-z][a-z0-9_-]*)?)((?:\s(?:(?!-->).)*?)?)-->#s',
		$content,
		$comments,
		PREG_SET_ORDER
	);

	foreach ( $comments as $comment ) {
		$is_closer = '/' === $comment[1];

		if ( ! $is_closer && str_ends_with( rtrim( $comment[3] ), '/' ) ) {
			continue;
		}

		$name = false === strpos( $comment[2], '/' ) ? 'core/' . $comment[2] : $comment[2];

		$counts[ $name ] ??= [
			'open'  => 0,
			'close' => 0,
		];
		++$counts[ $name ][ $is_closer ? 'close' : 'open' ];
	}

	return $counts;
}

/**
 * Applies the response size cap to a result.
 *
 * @param array  $result Result to return.
 * @param string $hint   What to do when the result had to be trimmed.
 * @return array
 */
function finish( array $result, string $hint ): array {
	$capped = \HM\RestApiAbilities\cap_response_data( $result );
	$output = $capped['data'];

	if ( isset( $capped['truncated'] ) ) {
		$output['truncated']         = $capped['truncated'];
		$output['truncated']['hint'] = $hint;
	}

	return $output;
}

/**
 * Exposes these abilities as MCP tools in their own right, rather than leaving
 * them reachable only through the adapter's generic `execute-ability` tool.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	$tools           = $config['tools'] ?? [];
	$tools[]         = 'content/find';
	$tools[]         = 'content/replace';
	$config['tools'] = array_values( array_unique( $tools ) );

	return $config;
}
