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

		public function response_to_data( WP_REST_Response $response, bool $embed ): array {
			return [ 'status' => $response->get_status() ];
		}
	}
}
