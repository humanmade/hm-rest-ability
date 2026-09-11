<?php
/**
 * Minimal runtime stand-ins for the WordPress classes this plugin type-hints
 * against. Not exhaustive — only the methods the plugin and its tests use.
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		private string $code;
		private string $message;

		public function __construct( string $code = '', string $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		public function get_error_code(): string {
			return $this->code;
		}

		public function get_error_message(): string {
			return $this->message;
		}
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_is_numeric_array' ) ) {
	function wp_is_numeric_array( $data ): bool {
		if ( ! is_array( $data ) ) {
			return false;
		}

		return count( array_filter( array_keys( $data ), 'is_string' ) ) === 0;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}
}

if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {

		private string $method;
		private string $route;
		private array $query_params = [];
		private array $body_params = [];
		private array $url_params = [];
		private array $headers = [];
		private string $body = '';

		public function __construct( string $method = 'GET', string $route = '' ) {
			$this->method = $method;
			$this->route  = $route;
		}

		public function get_method(): string {
			return $this->method;
		}

		public function get_route(): string {
			return $this->route;
		}

		public function set_query_params( array $params ): void {
			$this->query_params = $params;
		}

		public function get_query_params(): array {
			return $this->query_params;
		}

		public function set_body_params( array $params ): void {
			$this->body_params = $params;
		}

		public function get_body_params(): array {
			return $this->body_params;
		}

		public function set_url_params( array $params ): void {
			$this->url_params = $params;
		}

		public function get_url_params(): array {
			return $this->url_params;
		}

		public function set_body( string $body ): void {
			$this->body = $body;
		}

		public function get_body(): string {
			return $this->body;
		}

		public function set_header( string $key, string $value ): void {
			$this->headers[ strtolower( str_replace( '-', '_', $key ) ) ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}
	}
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
	class WP_REST_Response {

		private int $status;
		private array $headers = [];

		public function __construct( $data = null, int $status = 200 ) {
			$this->status = $status;
		}

		public function get_status(): int {
			return $this->status;
		}

		public function header( string $key, string $value ): void {
			$this->headers[ $key ] = $value;
		}

		public function get_headers(): array {
			return $this->headers;
		}

		public function is_error(): bool {
			return $this->status >= 400;
		}
	}
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
	class WP_REST_Server {

		private array $routes = [];

		public function set_routes( array $routes ): void {
			$this->routes = $routes;
		}

		public function get_routes(): array {
			return $this->routes;
		}

		private ?array $response_data = null;

		public function set_response_data( array $data ): void {
			$this->response_data = $data;
		}

		public function response_to_data( WP_REST_Response $response, bool $embed ): array {
			return $this->response_data ?? [ 'status' => $response->get_status() ];
		}

		public function get_data_for_route( string $route, array $handlers, string $context = 'view' ): array {
			return [
				'namespace' => 'wp/v2',
				'methods'   => array_keys( $handlers[0]['methods'] ?? [] ),
				'endpoints' => [ [ 'methods' => array_keys( $handlers[0]['methods'] ?? [] ) ] ],
			];
		}
	}
}
