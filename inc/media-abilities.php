<?php
/**
 * Exposes an "upload media" ability, so MCP clients can put a file into the
 * media library. The generic REST API ability can't do this: it sends JSON
 * params, and the media endpoint needs a request body with upload headers.
 *
 * @package HM\RestAbility
 */

namespace HM\MediaAbilities;

use WP_Error;
use WP_REST_Request;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\\register_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\\register_ability' );
add_filter( 'mcp_adapter_default_server_config', __NAMESPACE__ . '\\filter_mcp_server_config' );

/**
 * Registers the "Media" ability category.
 */
function register_category(): void {
	wp_register_ability_category(
		'media',
		[
			'label'       => 'Media',
			'description' => 'WordPress media library',
		]
	);
}

/**
 * Registers the "upload media" ability.
 */
function register_ability(): void {
	wp_register_ability(
		'media/upload',
		[
			'label'               => 'Upload Media',
			'description'         => sprintf(
				'Upload a file to the WordPress media library and return its attachment ID and URL. Send the file contents as base64, up to %d bytes once decoded, which is this site\'s upload limit. To use the attachment, call the REST API ability afterwards, for example by setting featured_media on a post.',
				max_upload_bytes()
			),
			'category'            => 'media',
			'input_schema'        => [
				'type'       => 'object',
				'properties' => [
					'file'      => [
						'type'        => 'string',
						'description' => 'File contents, base64-encoded. A data: URI is also accepted.',
					],
					'filename'  => [
						'type'        => 'string',
						'description' => 'File name including its extension, e.g. chart.png',
					],
					'mime_type' => [
						'type'        => 'string',
						'description' => 'Media type of the file, e.g. image/png',
					],
					'title'     => [
						'type'        => 'string',
						'description' => 'Title for the attachment',
					],
					'alt_text'  => [
						'type'        => 'string',
						'description' => 'Alternative text, for images',
					],
					'caption'   => [
						'type'        => 'string',
						'description' => 'Caption for the attachment',
					],
					'post'      => [
						'type'        => 'integer',
						'description' => 'ID of the post to attach the file to',
					],
				],
				'required'   => [ 'file', 'filename', 'mime_type' ],
			],
			'permission_callback' => __NAMESPACE__ . '\\check_permission',
			'execute_callback'    => __NAMESPACE__ . '\\execute',
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
 * Checks whether the current user can upload the requested file.
 *
 * @param array $input Ability input.
 * @return bool|WP_Error
 */
function check_permission( array $input ) {
	if ( ! is_user_logged_in() ) {
		return new WP_Error( 'rest_not_logged_in', 'Authentication required.' );
	}

	if ( ! current_user_can( 'upload_files' ) ) {
		return new WP_Error( 'rest_cannot_create', 'Sorry, you are not allowed to upload files.' );
	}

	$post = isset( $input['post'] ) ? (int) $input['post'] : 0;

	if ( $post > 0 && ! current_user_can( 'edit_post', $post ) ) {
		return new WP_Error( 'rest_cannot_edit', 'Sorry, you are not allowed to attach files to that post.' );
	}

	return true;
}

/**
 * Uploads the file and returns the resulting attachment.
 *
 * Dispatches the media endpoint internally with the decoded bytes as the
 * request body. Core's upload_from_data() writes those bytes to a temporary
 * file and sideloads it, so no real HTTP upload is involved.
 *
 * @param array $input Ability input.
 * @return array
 */
function execute( array $input ): array {
	$decoded = decode_file( $input['file'] ?? '' );

	if ( is_wp_error( $decoded ) ) {
		return [
			'code'  => $decoded->get_error_code(),
			'error' => $decoded->get_error_message(),
		];
	}

	$filename = sanitize_file_name( $input['filename'] ?? '' );

	if ( $filename === '' ) {
		return [
			'code'  => 'hm_media_no_filename',
			'error' => 'A filename is required.',
		];
	}

	$request = new WP_REST_Request( 'POST', '/wp/v2/media' );
	$request->set_body( $decoded );
	$request->set_header( 'Content-Type', (string) ( $input['mime_type'] ?? '' ) );
	$request->set_header( 'Content-Disposition', sprintf( 'attachment; filename="%s"', $filename ) );

	$attachment_fields = [];
	foreach ( [ 'title', 'alt_text', 'caption', 'post' ] as $field ) {
		if ( isset( $input[ $field ] ) ) {
			$attachment_fields[ $field ] = $input[ $field ];
		}
	}
	$request->set_query_params( $attachment_fields );

	$response = rest_do_request( $request );

	if ( is_wp_error( $response ) ) {
		return [
			'code'  => $response->get_error_code(),
			'error' => $response->get_error_message(),
		];
	}

	$data = rest_get_server()->response_to_data( $response, false );

	if ( $response->is_error() ) {
		return [
			'status' => $response->get_status(),
			'code'   => $data['code'] ?? 'hm_media_upload_failed',
			'error'  => $data['message'] ?? 'The upload failed.',
		];
	}

	return [
		'status'     => $response->get_status(),
		'id'         => $data['id'] ?? null,
		'source_url' => $data['source_url'] ?? null,
		'mime_type'  => $data['mime_type'] ?? null,
		'post'       => $data['post'] ?? null,
	];
}

/**
 * Exposes this ability as an MCP tool in its own right, rather than leaving it
 * reachable only through the adapter's generic `execute-ability` tool.
 *
 * @param array $config Default server config.
 * @return array
 */
function filter_mcp_server_config( array $config ): array {
	$tools           = $config['tools'] ?? [];
	$tools[]         = 'media/upload';
	$config['tools'] = array_values( array_unique( $tools ) );

	return $config;
}

/**
 * Returns the maximum size, in bytes, of a decoded upload.
 *
 * Defaults to the site's own upload limit, which is the smaller of PHP's
 * upload_max_filesize and post_max_size. The second of those is the real
 * ceiling here: the file arrives base64-encoded inside the JSON-RPC body,
 * which costs about a third more than the file itself.
 *
 * @return int
 */
function max_upload_bytes(): int {
	/**
	 * Filters the maximum size, in bytes, of a file uploaded through the
	 * `media/upload` ability. Defaults to wp_max_upload_size(). Zero or less
	 * removes the limit.
	 *
	 * @param int $max_bytes Maximum decoded file size in bytes.
	 */
	return (int) apply_filters( 'hm_rest_ability_max_upload_bytes', wp_max_upload_size() );
}

/**
 * Decodes base64 file contents, rejecting anything unusable or oversized.
 *
 * @param string $encoded Base64 string, optionally as a data: URI.
 * @return string|WP_Error
 */
function decode_file( string $encoded ) {
	$encoded = trim( $encoded );
	$comma   = strpos( $encoded, ',' );

	if ( strpos( $encoded, 'data:' ) === 0 && $comma !== false ) {
		$encoded = substr( $encoded, $comma + 1 );
	}

	if ( $encoded === '' ) {
		return new WP_Error( 'hm_media_no_file', 'No file contents were supplied.' );
	}

	$decoded = base64_decode( $encoded, true );

	if ( $decoded === false || $decoded === '' ) {
		return new WP_Error( 'hm_media_invalid_base64', 'The file contents are not valid base64.' );
	}

	$max_bytes = max_upload_bytes();

	if ( $max_bytes > 0 && strlen( $decoded ) > $max_bytes ) {
		return new WP_Error(
			'hm_media_too_large',
			sprintf( 'The file is larger than this site\'s %d byte upload limit.', $max_bytes )
		);
	}

	return $decoded;
}
