<?php
/**
 * Unit tests for inc/url-lookup.php.
 */

namespace HM\RestAbility\Tests;

use Brain\Monkey\Functions;
use Mockery;
use WP_Error;
use WP_REST_Request;

use function HM\UrlLookup\local_url;
use function HM\UrlLookup\lookup;
use function HM\UrlLookup\register_route;

class UrlLookupTest extends TestCase {

	protected function set_up(): void {
		parent::set_up();
		$this->load_plugin_file( 'inc/url-lookup.php' );

		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'rest_url' )->alias( static fn ( string $path ) => 'https://example.org/wp-json' . $path );
		Functions\when( 'attachment_url_to_postid' )->justReturn( 0 );
		Functions\when( 'is_post_publicly_viewable' )->justReturn( true );
		$this->set_home( 'https://example.org' );
	}

	private function set_home( string $home ): void {
		Functions\when( 'home_url' )->alias( static fn ( string $path = '' ) => $home . $path );
	}

	/**
	 * Stubs the post that a lookup finds.
	 */
	private function stub_post( string $type, string $route ): void {
		Functions\when( 'get_post_type' )->justReturn( $type );
		Functions\when( 'rest_get_route_for_post' )->justReturn( $route );
	}

	/**
	 * Calls lookup() with the given url param.
	 *
	 * @return \WP_REST_Response|WP_Error
	 */
	private function lookup( string $url ) {
		$request = new WP_REST_Request( 'GET', '/hm-rest-ability/v1/url-lookup' );
		$request->set_query_params( [ 'url' => $url ] );

		return lookup( $request );
	}

	public function test_register_route_adds_a_get_route_with_a_required_url_arg(): void {
		Functions\expect( 'register_rest_route' )
			->once()
			->with(
				'hm-rest-ability/v1',
				'/url-lookup',
				Mockery::on(
					static fn ( array $args ) => 'GET' === $args['methods'] && true === $args['args']['url']['required']
				)
			);

		register_route();
	}

	public function test_lookup_returns_the_id_type_and_a_self_link(): void {
		Functions\expect( 'url_to_postid' )->once()->with( 'https://example.org/about-us/?utm_source=x' )->andReturn( 12 );
		$this->stub_post( 'page', '/wp/v2/pages/12' );

		$response = $this->lookup( 'https://example.org/about-us/?utm_source=x#team' );

		$this->assertSame(
			[
				'id'   => 12,
				'type' => 'page',
			],
			$response->get_data()
		);
		$this->assertSame(
			[
				'self' => [
					[
						'href'       => 'https://example.org/wp-json/wp/v2/pages/12',
						'embeddable' => true,
					],
				],
			],
			$response->get_links()
		);
	}

	public function test_lookup_accepts_a_path(): void {
		Functions\expect( 'url_to_postid' )->once()->with( 'https://example.org/about-us/' )->andReturn( 12 );
		$this->stub_post( 'page', '/wp/v2/pages/12' );

		$this->assertSame( 12, $this->lookup( '/about-us/' )->get_data()['id'] );
	}

	public function test_lookup_maps_a_url_on_another_host_onto_this_site(): void {
		Functions\expect( 'url_to_postid' )->once()->with( 'https://example.org/about-us/' )->andReturn( 12 );
		$this->stub_post( 'page', '/wp/v2/pages/12' );

		$this->assertSame( 12, $this->lookup( 'https://www.example.com/about-us/' )->get_data()['id'] );
	}

	public function test_lookup_falls_back_to_an_attachment(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );
		Functions\when( 'attachment_url_to_postid' )->alias(
			static fn ( string $url ) => 'https://example.org/wp-content/uploads/2026/09/hero.jpg' === $url ? 44 : 0
		);
		$this->stub_post( 'attachment', '/wp/v2/media/44' );

		$response = $this->lookup( 'https://example.org/wp-content/uploads/2026/09/hero.jpg' );

		$this->assertSame(
			[
				'id'   => 44,
				'type' => 'attachment',
			],
			$response->get_data()
		);
	}

	public function test_lookup_returns_a_404_when_nothing_lives_at_the_url(): void {
		Functions\when( 'url_to_postid' )->justReturn( 0 );

		$result = $this->lookup( 'https://example.org/nothing-here/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_url_not_found', $result->get_error_code() );
	}

	public function test_lookup_hides_a_post_the_user_cannot_read(): void {
		Functions\when( 'url_to_postid' )->justReturn( 99 );
		Functions\when( 'is_post_publicly_viewable' )->justReturn( false );
		Functions\expect( 'current_user_can' )->once()->with( 'read_post', 99 )->andReturn( false );

		$result = $this->lookup( 'https://example.org/?p=99' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_url_not_found', $result->get_error_code() );
	}

	public function test_lookup_finds_a_private_post_the_user_can_read(): void {
		Functions\when( 'url_to_postid' )->justReturn( 99 );
		Functions\when( 'is_post_publicly_viewable' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		$this->stub_post( 'post', '/wp/v2/posts/99' );

		$this->assertSame( 99, $this->lookup( 'https://example.org/?p=99' )->get_data()['id'] );
	}

	public function test_lookup_leaves_out_the_link_for_a_type_not_in_rest(): void {
		Functions\when( 'url_to_postid' )->justReturn( 5 );
		$this->stub_post( 'hidden_type', '' );

		$this->assertSame( [], $this->lookup( 'https://example.org/hidden/thing/' )->get_links() );
	}

	public function test_local_url_keeps_only_the_path_and_query(): void {
		$this->assertSame( 'https://example.org/about-us/?p=1', local_url( 'http://www.example.com/about-us/?p=1#x' ) );
		$this->assertSame( 'https://example.org/about-us', local_url( '/about-us' ) );
		$this->assertSame( 'https://example.org/', local_url( 'https://example.org' ) );
	}

	public function test_local_url_strips_the_home_path_on_a_subdirectory_site(): void {
		$this->set_home( 'https://example.org/blog' );

		$this->assertSame( 'https://example.org/blog/hello-world/', local_url( 'https://example.org/blog/hello-world/' ) );
		$this->assertSame( 'https://example.org/blog/hello-world/', local_url( '/hello-world/' ) );
	}
}
