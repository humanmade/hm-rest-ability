<?php
/**
 * Minimal runtime stand-ins for the WordPress classes this plugin type-hints
 * against. Not exhaustive — only the methods the plugin and its tests use.
 */

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {

		private string $code;
		private string $message;
		private $data;

		public function __construct( string $code = '', string $message = '', $data = null ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_data() {
			return $this->data;
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
		private array $default_params = [];
		private array $attributes = [];
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

		public function get_param( string $key ) {
			return $this->query_params[ $key ]
				?? $this->body_params[ $key ]
				?? $this->url_params[ $key ]
				?? $this->default_params[ $key ]
				?? null;
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

		public function set_default_params( array $params ): void {
			$this->default_params = $params;
		}

		public function set_attributes( array $attributes ): void {
			$this->attributes = $attributes;
		}

		public function get_attributes(): array {
			return $this->attributes;
		}

		/**
		 * Checks required args are present and runs each arg's
		 * validate_callback, like core.
		 */
		public function has_valid_params() {
			foreach ( $this->attributes['args'] ?? [] as $key => $arg ) {
				$value = $this->get_param( $key );

				if ( null === $value ) {
					if ( ! empty( $arg['required'] ) ) {
						return new WP_Error( 'rest_missing_callback_param', "Missing parameter(s): $key", [ 'status' => 400 ] );
					}
					continue;
				}

				if ( isset( $arg['validate_callback'] ) ) {
					$valid = call_user_func( $arg['validate_callback'], $value, $this, $key );
					if ( false === $valid || is_wp_error( $valid ) ) {
						return new WP_Error( 'rest_invalid_param', "Invalid parameter(s): $key", [ 'status' => 400 ] );
					}
				}
			}

			return true;
		}

		/**
		 * Runs each arg's sanitize_callback over the params it applies to,
		 * like core.
		 */
		public function sanitize_params() {
			foreach ( [ 'query_params', 'body_params', 'url_params', 'default_params' ] as $type ) {
				foreach ( $this->{$type} as $key => $value ) {
					$callback = $this->attributes['args'][ $key ]['sanitize_callback'] ?? null;
					if ( ! $callback ) {
						continue;
					}

					$sanitized = call_user_func( $callback, $value, $this, $key );
					if ( is_wp_error( $sanitized ) ) {
						return new WP_Error( 'rest_invalid_param', "Invalid parameter(s): $key", [ 'status' => 400 ] );
					}

					$this->{$type}[ $key ] = $sanitized;
				}
			}

			return true;
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
		private $data;
		private array $links = [];

		public function __construct( $data = null, int $status = 200 ) {
			$this->data   = $data;
			$this->status = $status;
		}

		public function get_data() {
			return $this->data;
		}

		public function add_link( string $rel, string $href, array $attributes = [] ): void {
			$this->links[ $rel ][] = [ 'href' => $href ] + $attributes;
		}

		public function get_links(): array {
			return $this->links;
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
