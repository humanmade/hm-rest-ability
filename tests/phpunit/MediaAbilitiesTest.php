<?php
/**
 * Unit tests for inc/media-abilities.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function HM\MediaAbilities\check_permission;
use function HM\MediaAbilities\decode_file;
use function HM\MediaAbilities\execute;
use function HM\MediaAbilities\filter_mcp_server_config;
use function HM\MediaAbilities\max_upload_bytes;

class MediaAbilitiesTest extends TestCase {

	/**
	 * A one pixel transparent PNG.
	 */
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

	/**
	 * Stands in for PHP's own upload limits, which aren't available here.
	 */
	private const SITE_UPLOAD_LIMIT = 2097152;

	protected function set_up(): void {
		parent::set_up();
		Functions\when( 'wp_max_upload_size' )->justReturn( self::SITE_UPLOAD_LIMIT );
		$this->load_plugin_file( 'inc/media-abilities.php' );
	}

	/**
	 * Overrides the upload size limit for one test, leaving every other
	 * filtered value passing straight through.
	 */
	private function set_max_upload_bytes( int $bytes ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $hook, $value ) use ( $bytes ) {
				return 'hm_rest_ability_max_upload_bytes' === $hook ? $bytes : $value;
			}
		);
	}

	/**
	 * Stands in for a successful dispatch, and captures the request that was
	 * dispatched so tests can assert on how it was built.
	 *
	 * @param array $data   Response data.
	 * @param int   $status Response status.
	 * @return object Holds the captured request on `$captured->request`.
	 */
	private function stub_dispatch( array $data, int $status = 201 ) {
		$captured = new \stdClass();
		$response = new WP_REST_Response( null, $status );

		$server = new WP_REST_Server();
		$server->set_response_data( $data );

		Functions\when( 'rest_get_server' )->justReturn( $server );
		Functions\when( 'rest_do_request' )->alias(
			static function ( $request ) use ( $captured, $response ) {
				$captured->request = $request;

				return $response;
			}
		);

		return $captured;
	}

	public function test_check_permission_requires_login(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );

		$result = check_permission( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
	}

	public function test_check_permission_requires_the_upload_capability(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( false );

		$result = check_permission( [] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_create', $result->get_error_code() );
	}

	public function test_check_permission_checks_the_parent_post(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			static fn ( string $capability ) => 'upload_files' === $capability
		);

		$result = check_permission( [ 'post' => 42 ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_cannot_edit', $result->get_error_code() );
	}

	public function test_check_permission_passes_for_an_uploader(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->justReturn( true );

		$this->assertTrue( check_permission( [ 'post' => 42 ] ) );
	}

	public function test_decode_file_rejects_empty_contents(): void {
		$result = decode_file( '   ' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hm_media_no_file', $result->get_error_code() );
	}

	public function test_decode_file_rejects_invalid_base64(): void {
		$result = decode_file( 'not base64 !!!' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hm_media_invalid_base64', $result->get_error_code() );
	}

	public function test_decode_file_accepts_a_data_uri(): void {
		$result = decode_file( 'data:image/png;base64,' . self::PNG );

		$this->assertSame( base64_decode( self::PNG ), $result );
	}

	public function test_decode_file_rejects_oversized_files(): void {
		$this->set_max_upload_bytes( 10 );

		$result = decode_file( self::PNG );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'hm_media_too_large', $result->get_error_code() );
	}

	public function test_max_upload_bytes_defaults_to_the_site_limit(): void {
		$this->assertSame( self::SITE_UPLOAD_LIMIT, max_upload_bytes() );
	}

	public function test_max_upload_bytes_is_filterable(): void {
		$this->set_max_upload_bytes( 512 );

		$this->assertSame( 512, max_upload_bytes() );
	}

	public function test_execute_sends_the_bytes_with_upload_headers(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();
		$captured = $this->stub_dispatch( [ 'id' => 7, 'source_url' => 'https://example.com/pixel.png' ] );

		execute( [
			'file'      => self::PNG,
			'filename'  => 'pixel.png',
			'mime_type' => 'image/png',
			'alt_text'  => 'A pixel',
		] );

		/** @var WP_REST_Request $request */
		$request = $captured->request;
		$headers = $request->get_headers();

		$this->assertSame( 'POST', $request->get_method() );
		$this->assertSame( '/wp/v2/media', $request->get_route() );
		$this->assertSame( base64_decode( self::PNG ), $request->get_body() );
		$this->assertSame( 'image/png', $headers['content_type'] );
		$this->assertSame( 'attachment; filename="pixel.png"', $headers['content_disposition'] );
		$this->assertSame( [ 'alt_text' => 'A pixel' ], $request->get_query_params() );
	}

	public function test_execute_returns_the_attachment(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();
		$this->stub_dispatch( [
			'id'         => 7,
			'source_url' => 'https://example.com/pixel.png',
			'mime_type'  => 'image/png',
			'post'       => null,
		] );

		$result = execute( [
			'file'      => self::PNG,
			'filename'  => 'pixel.png',
			'mime_type' => 'image/png',
		] );

		$this->assertSame( 201, $result['status'] );
		$this->assertSame( 7, $result['id'] );
		$this->assertSame( 'https://example.com/pixel.png', $result['source_url'] );
	}

	public function test_execute_surfaces_a_rest_error_response(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();
		$this->stub_dispatch(
			[
				'code'    => 'rest_upload_unknown_error',
				'message' => 'Sorry, the file type is not permitted.',
			],
			400
		);

		$result = execute( [
			'file'      => self::PNG,
			'filename'  => 'pixel.png',
			'mime_type' => 'image/png',
		] );

		$this->assertSame( 400, $result['status'] );
		$this->assertSame( 'rest_upload_unknown_error', $result['code'] );
		$this->assertSame( 'Sorry, the file type is not permitted.', $result['error'] );
	}

	public function test_execute_rejects_a_missing_filename(): void {
		Functions\when( 'sanitize_file_name' )->returnArg();

		$result = execute( [
			'file'      => self::PNG,
			'filename'  => '',
			'mime_type' => 'image/png',
		] );

		$this->assertSame( 'hm_media_no_filename', $result['code'] );
	}

	public function test_filter_mcp_server_config_exposes_the_ability_as_a_tool(): void {
		$config = filter_mcp_server_config( [ 'tools' => [ 'mcp-adapter/execute-ability' ] ] );

		$this->assertSame(
			[ 'mcp-adapter/execute-ability', 'media/upload' ],
			$config['tools']
		);
	}

	public function test_execute_reports_a_decode_failure(): void {
		$result = execute( [
			'file'      => 'not base64 !!!',
			'filename'  => 'pixel.png',
			'mime_type' => 'image/png',
		] );

		$this->assertSame( 'hm_media_invalid_base64', $result['code'] );
	}
}
