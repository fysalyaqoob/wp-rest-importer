<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_API_Client {

	private string $site_url;
	private string $auth_header      = '';
	private bool   $use_edit_context = false;
	private bool   $ssl_verify       = true;

	public function __construct( string $site_url ) {
		$this->site_url = trailingslashit( esc_url_raw( $site_url ) );
	}

	public function set_credentials( string $username, string $app_password ): void {
		$this->auth_header      = 'Basic ' . base64_encode( $username . ':' . $app_password );
		$this->use_edit_context = true;
	}

	public function clear_credentials(): void {
		$this->auth_header      = '';
		$this->use_edit_context = false;
	}

	public function set_ssl_verify( bool $verify ): void {
		$this->ssl_verify = $verify;
	}

	/**
	 * Probe REST API availability and return summary counts.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function test_connection() {
		$root = $this->site_url . 'wp-json/';

		if ( ! $this->is_safe_url( $root ) ) {
			return new WP_Error( 'blocked_url', __( 'Request blocked: URL resolves to a private or reserved address.', 'wp-rest-importer' ) );
		}

		$response = $this->request_with_retry( $root, $this->build_request_args( 30 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'bad_response',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'REST API unreachable (HTTP %d).', 'wp-rest-importer' ),
					$code
				)
			);
		}

		$has_raw = false;
		$posts   = $this->fetch_page( 'posts', 1, 1 );
		if ( ! is_wp_error( $posts ) && ! empty( $posts['items'][0] ) ) {
			$sample  = $posts['items'][0];
			$has_raw = isset( $sample['content']['raw'] ) && '' !== $sample['content']['raw'];
		}

		$post_total = is_wp_error( $posts ) ? 0 : (int) $posts['total'];
		$page_total = 0;

		$pages = $this->fetch_page( 'pages', 1, 1 );
		if ( ! is_wp_error( $pages ) ) {
			$page_total = (int) $pages['total'];
		}

		$types = $this->fetch_post_types();

		return [
			'ok'            => true,
			'post_total'    => $post_total,
			'page_total'    => $page_total,
			'has_raw'       => $has_raw,
			'authenticated' => $this->use_edit_context,
			'post_types'    => is_wp_error( $types ) ? [] : $types,
			'message'       => sprintf(
				/* translators: 1: post count, 2: page count */
				__( 'Connected. Found %1$d posts and %2$d pages.', 'wp-rest-importer' ),
				$post_total,
				$page_total
			),
		];
	}

	/**
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	public function fetch_post_types() {
		$url = $this->site_url . 'wp-json/wp/v2/types';

		if ( ! $this->is_safe_url( $url ) ) {
			return new WP_Error( 'blocked_url', __( 'Request blocked: URL resolves to a private or reserved address.', 'wp-rest-importer' ) );
		}

		$response = $this->request_with_retry( $url, $this->build_request_args( 30 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return new WP_Error( 'bad_response', __( 'Could not fetch post types.', 'wp-rest-importer' ) );
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$types = [];

		if ( is_array( $body ) ) {
			foreach ( $body as $slug => $type ) {
				if ( ! is_array( $type ) ) {
					continue;
				}
				$types[] = [
					'slug'      => $slug,
					'name'      => $type['name'] ?? $slug,
					'rest_base' => $type['rest_base'] ?? $slug,
				];
			}
		}

		return $types;
	}

	/**
	 * @param string              $post_type REST collection base (posts, pages, or CPT rest_base).
	 * @param int                 $page      Page number (1-based).
	 * @param int                 $per_page  Items per page (max 100).
	 * @param array<string,mixed> $extra_args Additional query args.
	 * @return array|WP_Error
	 */
	public function fetch_page( string $post_type, int $page = 1, int $per_page = 100, array $extra_args = [] ) {
		$per_page = max( 1, min( 100, $per_page ) );

		return $this->request_collection(
			$post_type,
			array_merge(
				$extra_args,
				[
					'page'     => max( 1, $page ),
					'per_page' => $per_page,
				]
			)
		);
	}

	/**
	 * Fetch a page from the media library endpoint.
	 *
	 * @param array<string,mixed> $extra_args
	 * @return array|WP_Error
	 */
	public function fetch_media_page( int $page = 1, int $per_page = 100, array $extra_args = [] ) {
		return $this->fetch_page( 'media', $page, $per_page, $extra_args );
	}

	/**
	 * Resolve a taxonomy term ID on the remote site by slug.
	 */
	public function get_term_id_by_slug( string $taxonomy_rest_base, string $slug ): int {
		if ( '' === $slug ) {
			return 0;
		}

		$result = $this->request_collection( $taxonomy_rest_base, [ 'slug' => [ $slug ], 'per_page' => 1 ] );

		if ( is_wp_error( $result ) || empty( $result['items'][0]['id'] ) ) {
			return 0;
		}

		return (int) $result['items'][0]['id'];
	}

	public function fetch_by_slug( string $post_type, string $slug ) {
		$slug = WPRestI_Import_Runner::normalize_slug( $slug );

		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Slug is required.', 'wp-rest-importer' ) );
		}

		$result = $this->request_collection(
			$post_type,
			[
				'slug'     => [ $slug ],
				'per_page' => 100,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$items = $this->filter_rest_items_by_slug( $result['items'], $slug );

		if ( ! empty( $items ) ) {
			return $items;
		}

		$retry = $this->request_collection(
			$post_type,
			[
				'slug'     => $slug,
				'per_page' => 100,
			]
		);

		if ( is_wp_error( $retry ) ) {
			return $retry;
		}

		$items = $this->filter_rest_items_by_slug( $retry['items'], $slug );

		if ( ! empty( $items ) ) {
			return $items;
		}

		return $this->fetch_by_slug_search_fallback( $post_type, $slug );
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_rest_items_by_slug( array $items, string $slug ): array {
		$matched = [];

		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( WPRestI_Import_Runner::item_matches_slug( $item, $slug ) ) {
				$matched[] = $item;
			}
		}

		return $matched;
	}

	/**
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function fetch_by_slug_search_fallback( string $post_type, string $slug ) {
		$search_term = str_replace( '-', ' ', $slug );
		$result      = $this->request_collection(
			$post_type,
			[
				'search'   => $search_term,
				'per_page' => 50,
			]
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->filter_rest_items_by_slug( $result['items'], $slug );
	}

	public function fetch_all( string $post_type ) {
		$page      = 1;
		$all_items = [];

		do {
			$page_result = $this->fetch_page( $post_type, $page );

			if ( is_wp_error( $page_result ) ) {
				return $page_result;
			}

			$all_items = array_merge( $all_items, $page_result['items'] );
			$page++;

		} while ( $page <= $page_result['total_pages'] );

		return $all_items;
	}

	/**
	 * @param string              $post_type REST collection base.
	 * @param array<string,mixed> $extra_args Query args.
	 * @return array|WP_Error
	 */
	private function request_collection( string $post_type, array $extra_args = [] ) {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type ) {
			return new WP_Error( 'invalid_post_type', __( 'Invalid post type.', 'wp-rest-importer' ) );
		}

		$endpoint = $this->site_url . 'wp-json/wp/v2/' . rawurlencode( $post_type );

		if ( ! $this->is_safe_url( $endpoint ) ) {
			return new WP_Error( 'blocked_url', __( 'Request blocked: URL resolves to a private or reserved address.', 'wp-rest-importer' ) );
		}

		$query_args = array_merge(
			[
				'per_page' => 100,
				'_embed'   => 1,
			],
			$extra_args
		);

		if ( isset( $query_args['per_page'] ) ) {
			$query_args['per_page'] = max( 1, min( 100, (int) $query_args['per_page'] ) );
		}

		if ( ! isset( $query_args['page'] ) ) {
			$query_args['page'] = 1;
		}

		if ( $this->use_edit_context ) {
			$query_args['context'] = 'edit';
		}

		$url = add_query_arg( $query_args, $endpoint );

		if ( ! $this->is_safe_url( $url ) ) {
			return new WP_Error( 'blocked_url', __( 'Request blocked: URL resolves to a private or reserved address.', 'wp-rest-importer' ) );
		}

		$response = $this->request_with_retry( $url, $this->build_request_args( 60 ) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $code ) {
			return new WP_Error( 'auth_failed', __( 'Authentication failed (HTTP 401).', 'wp-rest-importer' ) );
		}

		if ( 200 !== $code ) {
			return new WP_Error(
				'bad_response',
				sprintf(
					/* translators: 1: HTTP code, 2: URL */
					__( 'Received HTTP %1$d from %2$s', 'wp-rest-importer' ),
					$code,
					$url
				)
			);
		}

		$headers          = wp_remote_retrieve_headers( $response );
		$total            = (int) ( $headers['x-wp-total'] ?? 0 );
		$reported_pages   = (int) ( $headers['x-wp-totalpages'] ?? 1 );
		$max_pages        = (int) WPRestI_Settings::get( 'max_rest_pages' );
		$total_pages      = $max_pages > 0 ? min( $reported_pages, $max_pages ) : $reported_pages;
		$page_num         = (int) ( $query_args['page'] ?? 1 );
		$truncated        = $max_pages > 0 && $reported_pages > $max_pages;

		$body  = wp_remote_retrieve_body( $response );
		$items = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_error', __( 'Invalid JSON in API response.', 'wp-rest-importer' ) );
		}

		if ( ! is_array( $items ) ) {
			$items = [];
		}

		return [
			'items'          => $items,
			'total'          => $total,
			'total_pages'    => $total_pages,
			'reported_pages' => $reported_pages,
			'truncated'      => $truncated,
			'page'           => $page_num,
		];
	}

	/**
	 * @param array<string,mixed> $args
	 * @return array|WP_Error
	 */
	private function request_with_retry( string $url, array $args ) {
		$max_attempts = 4;
		$delay        = 1;

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) ) {
				if ( $attempt >= $max_attempts ) {
					return $response;
				}
			} else {
				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 === $code || 401 === $code || ! in_array( $code, [ 429, 500, 502, 503, 504 ], true ) ) {
					return $response;
				}
				if ( $attempt >= $max_attempts ) {
					return new WP_Error(
						'bad_response',
						sprintf(
							/* translators: %d: HTTP status code */
							__( 'Remote server returned HTTP %d after retries.', 'wp-rest-importer' ),
							$code
						)
					);
				}
			}

			sleep( $delay );
			$delay = min( 8, $delay * 2 );
		}

		return new WP_Error( 'request_failed', __( 'Remote request failed after retries.', 'wp-rest-importer' ) );
	}

	private function is_safe_url( string $url ): bool {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $host ) {
			return false;
		}
		$ip = gethostbyname( $host );
		if ( $ip === $host ) {
			return false;
		}
		return (bool) filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		);
	}

	private function build_request_args( int $timeout ): array {
		$args = [
			'timeout'   => $timeout,
			'sslverify' => $this->ssl_verify,
			'headers'   => [ 'Accept' => 'application/json' ],
		];

		if ( $this->auth_header ) {
			$args['headers']['Authorization'] = $this->auth_header;
		}

		return $args;
	}
}
