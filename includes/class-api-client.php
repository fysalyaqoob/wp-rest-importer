<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_API_Client {

	private string $site_url;
	private string $auth_header      = '';
	private bool   $use_edit_context = false;

	public function __construct( string $site_url ) {
		$this->site_url = trailingslashit( esc_url_raw( $site_url ) );
	}

	public function set_credentials( string $username, string $app_password ): void {
		$this->auth_header      = 'Basic ' . base64_encode( $username . ':' . $app_password );
		$this->use_edit_context = true;
	}

	/**
	 * Fetch all items of a given post type across all pages.
	 *
	 * @param string $post_type 'posts' or 'pages'
	 * @return array|WP_Error  WP_Error with code 'auth_failed' on HTTP 401.
	 */
	public function fetch_all( string $post_type ) {
		if ( ! in_array( $post_type, [ 'posts', 'pages' ], true ) ) {
			return new WP_Error( 'invalid_post_type', 'Invalid post type: ' . $post_type );
		}

		$endpoint  = $this->site_url . 'wp-json/wp/v2/' . $post_type;
		$page      = 1;
		$all_items = [];

		// SSRF check runs once — same host for every paginated request.
		if ( ! $this->is_safe_url( $endpoint ) ) {
			return new WP_Error( 'blocked_url', 'Request blocked: URL resolves to a private or reserved address.' );
		}

		do {
			$query_args = [
				'per_page' => 100,
				'page'     => $page,
				'_embed'   => 1,
			];

			if ( $this->use_edit_context ) {
				$query_args['context'] = 'edit';
			}

			$url = add_query_arg( $query_args, $endpoint );

			$response = wp_remote_get( $url, $this->build_request_args( 60 ) );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 401 === $code ) {
				return new WP_Error( 'auth_failed', 'Authentication failed (HTTP 401). Falling back to unauthenticated fetch.' );
			}

			if ( 200 !== $code ) {
				return new WP_Error(
					'bad_response',
					sprintf( 'Received HTTP %d from %s', $code, $url )
				);
			}

			$headers     = wp_remote_retrieve_headers( $response );
			$total_pages = min( (int) ( $headers['x-wp-totalpages'] ?? 1 ), 500 );

			$body  = wp_remote_retrieve_body( $response );
			$items = json_decode( $body, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error( 'json_error', 'Invalid JSON in API response' );
			}

			if ( ! is_array( $items ) ) {
				break;
			}

			$all_items = array_merge( $all_items, $items );
			$page++;

		} while ( $page <= $total_pages );

		return $all_items;
	}

	private function is_safe_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$ip = gethostbyname( $host );
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private function build_request_args( int $timeout ): array {
		$args = [
			'timeout'   => $timeout,
			'sslverify' => true,
			'headers'   => [ 'Accept' => 'application/json' ],
		];

		if ( $this->auth_header ) {
			$args['headers']['Authorization'] = $this->auth_header;
		}

		return $args;
	}
}
