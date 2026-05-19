<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Ajax_Handler {

	public function __construct() {
		add_action( 'wp_ajax_wpresti_start',           [ $this, 'handle_start' ] );
		add_action( 'wp_ajax_wpresti_batch',           [ $this, 'handle_batch' ] );
		add_action( 'wp_ajax_wpresti_get_progress',    [ $this, 'handle_get_progress' ] );
		add_action( 'wp_ajax_wpresti_reassign_scan',   [ $this, 'handle_reassign_scan' ] );
		add_action( 'wp_ajax_wpresti_reassign_run',    [ $this, 'handle_reassign_run' ] );
	}

	/**
	 * Initialise a new import: count total items, store queue in wp_options.
	 *
	 * If source credentials are provided, fetches with context=edit to obtain
	 * content.raw for accurate Gutenberg detection. Falls back to a standard
	 * unauthenticated fetch on HTTP 401.
	 */
	public function handle_start(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$site_url            = esc_url_raw( wp_unslash( $_POST['site_url'] ?? '' ) );
		$import_type         = sanitize_key( $_POST['import_type'] ?? 'both' );
		$assign_author_id    = absint( $_POST['assign_author_id'] ?? 0 );

		if ( ! in_array( $import_type, [ 'posts', 'pages', 'both' ], true ) ) {
			wp_send_json_error( [ 'message' => 'Invalid import type.' ] );
		}
		$source_username     = sanitize_user( wp_unslash( $_POST['source_username'] ?? '' ), true );
		$source_app_password = trim( wp_unslash( $_POST['source_app_password'] ?? '' ) );

		if ( $assign_author_id > 0 && ! get_user_by( 'id', $assign_author_id ) ) {
			$assign_author_id = 0;
		}

		if ( ! $site_url ) {
			wp_send_json_error( [ 'message' => 'Site URL is required.' ] );
		}

		$has_credentials = $source_username !== '' && $source_app_password !== '';
		$queue           = [];
		$type_map        = [ 'posts' => 'post', 'pages' => 'page' ];

		// Strategy 1: authenticated fetch with context=edit → content.raw available.
		if ( $has_credentials ) {
			$auth_client = new WPRestI_API_Client( $site_url );
			$auth_client->set_credentials( $source_username, $source_app_password );

			$fetch_types = [];
			if ( in_array( $import_type, [ 'posts', 'both' ], true ) ) {
				$fetch_types[] = 'posts';
			}
			if ( in_array( $import_type, [ 'pages', 'both' ], true ) ) {
				$fetch_types[] = 'pages';
			}

			$auth_queue  = [];
			$auth_failed = false;

			foreach ( $fetch_types as $ft ) {
				$items = $auth_client->fetch_all( $ft );

				if ( is_wp_error( $items ) ) {
					if ( 'auth_failed' === $items->get_error_code() ) {
						$auth_failed = true;
						break;
					}
					wp_send_json_error( [ 'message' => $items->get_error_message() ] );
				}

				foreach ( $items as $item ) {
					$auth_queue[] = [ 'data' => $this->trim_queue_item( $item ), 'post_type' => $type_map[ $ft ] ];
				}
			}

			if ( ! $auth_failed ) {
				$queue = $auth_queue;
			}
		}

		// Strategy 2: unauthenticated fetch — rendered content only (fallback or no credentials).
		if ( empty( $queue ) ) {
			$client = new WPRestI_API_Client( $site_url );

			if ( in_array( $import_type, [ 'posts', 'both' ], true ) ) {
				$items = $client->fetch_all( 'posts' );
				if ( is_wp_error( $items ) ) {
					wp_send_json_error( [ 'message' => $items->get_error_message() ] );
				}
				foreach ( $items as $item ) {
					$queue[] = [ 'data' => $this->trim_queue_item( $item ), 'post_type' => 'post' ];
				}
			}

			if ( in_array( $import_type, [ 'pages', 'both' ], true ) ) {
				$items = $client->fetch_all( 'pages' );
				if ( is_wp_error( $items ) ) {
					wp_send_json_error( [ 'message' => $items->get_error_message() ] );
				}
				foreach ( $items as $item ) {
					$queue[] = [ 'data' => $this->trim_queue_item( $item ), 'post_type' => 'page' ];
				}
			}
		}

		if ( empty( $queue ) ) {
			wp_send_json_error( [ 'message' => 'No items found at the provided URL.' ] );
		}

		$parsed_host   = wp_parse_url( $site_url, PHP_URL_HOST );
		$source_domain = $parsed_host ? $parsed_host : '';

		$progress = [
			'site_url'         => $site_url,
			'source_domain'    => $source_domain,
			'assign_author_id' => $assign_author_id,
			'queue'            => $queue,
			'total'            => count( $queue ),
			'done'             => 0,
			'log'              => [],
			'complete'         => false,
		];

		update_option( 'wpresti_progress', $progress, false );

		wp_send_json_success(
			[
				'total'   => $progress['total'],
				'message' => sprintf( 'Found %d items. Starting import…', $progress['total'] ),
			]
		);
	}

	/**
	 * Process a batch of 5 items from the queue.
	 */
	public function handle_batch(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		// Prevent two simultaneous requests from processing the same batch items.
		if ( get_transient( 'wpresti_batch_lock' ) ) {
			wp_send_json_error( [ 'message' => 'A batch is already processing. Please wait.' ] );
			return;
		}
		set_transient( 'wpresti_batch_lock', 1, 30 );

		$progress = get_option( 'wpresti_progress', [] );

		if ( empty( $progress ) || empty( $progress['queue'] ) ) {
			delete_transient( 'wpresti_batch_lock' );
			wp_send_json_success(
				[
					'done'     => (int) ( $progress['done'] ?? 0 ),
					'total'    => (int) ( $progress['total'] ?? 0 ),
					'log'      => [],
					'complete' => true,
				]
			);
			return;
		}

		$batch    = array_splice( $progress['queue'], 0, 5 );
		$importer = new WPRestI_Importer();
		$importer->set_source_domain( $progress['source_domain'] ?? '' );
		$importer->set_source_url( $progress['site_url'] ?? '' );
		$importer->set_assign_author( (int) ( $progress['assign_author_id'] ?? 0 ) );

		$batch_log = [];
		foreach ( $batch as $item_wrapper ) {
			$result         = $importer->import_item( $item_wrapper['data'], $item_wrapper['post_type'] );
			$result['time'] = current_time( 'H:i:s' );
			$batch_log[]    = $result;
			$progress['log'][] = $result;
			$progress['done']++;
		}

		// Cap stored log at 100 entries to prevent unbounded wp_options growth.
		if ( count( $progress['log'] ) > 100 ) {
			$progress['log'] = array_slice( $progress['log'], -100 );
		}

		$is_complete          = empty( $progress['queue'] );
		$progress['complete'] = $is_complete;

		update_option( 'wpresti_progress', $progress, false );
		delete_transient( 'wpresti_batch_lock' );

		wp_send_json_success(
			[
				'done'     => $progress['done'],
				'total'    => $progress['total'],
				'log'      => $batch_log,
				'complete' => $is_complete,
			]
		);
	}

	/**
	 * Return current progress state (used for polling if JS loses state).
	 */
	public function handle_get_progress(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$progress = get_option( 'wpresti_progress', [] );

		wp_send_json_success(
			[
				'done'     => (int) ( $progress['done'] ?? 0 ),
				'total'    => (int) ( $progress['total'] ?? 0 ),
				'complete' => (bool) ( $progress['complete'] ?? false ),
				'log'      => $progress['log'] ?? [],
			]
		);
	}

	/**
	 * Scan all posts/pages with _original_author_login and return a summary
	 * grouped by original login, including whether a matching WP user exists.
	 */
	public function handle_reassign_scan(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$posts = get_posts(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'any',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'     => '_original_author_login',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		// Pre-load all post meta in one query so the loop hits the cache.
		update_meta_cache( 'post', $posts );

		// Group by original login.
		$groups = [];
		foreach ( $posts as $post_id ) {
			$login = get_post_meta( $post_id, '_original_author_login', true );
			$name  = get_post_meta( $post_id, '_original_author_name', true );

			if ( '' === $login && '' === $name ) {
				continue;
			}

			$key = $login ?: '__empty__';
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = [
					'login'      => $login,
					'name'       => $name,
					'post_count' => 0,
					'wp_user_id' => 0,
					'wp_user'    => '',
					'matched'    => false,
				];

				$wp_user = $login ? get_user_by( 'login', $login ) : false;
				if ( $wp_user ) {
					$groups[ $key ]['matched']    = true;
					$groups[ $key ]['wp_user_id'] = (int) $wp_user->ID;
					$groups[ $key ]['wp_user']    = $wp_user->display_name;
				}
			}

			$groups[ $key ]['post_count']++;
		}

		wp_send_json_success( [ 'groups' => array_values( $groups ) ] );
	}

	/**
	 * Reassign post_author for all posts where the original login matches a local WP user.
	 * Removes the three original-author postmeta fields after reassigning.
	 */
	public function handle_reassign_run(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
		}

		$posts = get_posts(
			[
				'post_type'      => [ 'post', 'page' ],
				'post_status'    => 'any',
				'posts_per_page' => 5000,
				'fields'         => 'ids',
				'meta_query'     => [
					[
						'key'     => '_original_author_login',
						'compare' => 'EXISTS',
					],
				],
			]
		);

		// Pre-load all post meta in one query so the loop hits the cache.
		update_meta_cache( 'post', $posts );

		// Collect login for each post in one pass, then resolve unique logins once.
		$post_logins = [];
		foreach ( $posts as $post_id ) {
			$post_logins[ $post_id ] = get_post_meta( $post_id, '_original_author_login', true );
		}

		$user_map = [];
		foreach ( array_unique( array_filter( $post_logins ) ) as $login ) {
			$wp_user = get_user_by( 'login', $login );
			if ( $wp_user ) {
				$user_map[ $login ] = $wp_user;
			}
		}

		$reassigned = 0;
		$unmatched  = 0;

		foreach ( $posts as $post_id ) {
			$login = $post_logins[ $post_id ];

			if ( ! $login || ! isset( $user_map[ $login ] ) ) {
				$unmatched++;
				continue;
			}

			$update_id = wp_update_post(
				[
					'ID'          => $post_id,
					'post_author' => $user_map[ $login ]->ID,
				],
				true
			);

			if ( is_wp_error( $update_id ) ) {
				$unmatched++;
				continue;
			}

			delete_post_meta( $post_id, '_original_author_name' );
			delete_post_meta( $post_id, '_original_author_login' );
			delete_post_meta( $post_id, '_original_author_email' );

			$reassigned++;
		}

		wp_send_json_success(
			[
				'reassigned' => $reassigned,
				'unmatched'  => $unmatched,
			]
		);
	}

	/**
	 * Strip a raw REST API item down to the fields actually needed for import,
	 * dramatically reducing the size of the serialised queue in wp_options.
	 */
	private function trim_queue_item( array $item ): array {
		$out = [
			'slug'      => $item['slug'] ?? '',
			'title'     => [ 'rendered' => $item['title']['rendered'] ?? '' ],
			'status'    => $item['status'] ?? 'publish',
			'date_gmt'  => $item['date_gmt'] ?? '',
			'content'   => [],
			'excerpt'   => [ 'rendered' => $item['excerpt']['rendered'] ?? '' ],
			'_embedded' => [],
		];

		if ( isset( $item['content']['raw'] ) && '' !== $item['content']['raw'] ) {
			$out['content']['raw'] = $item['content']['raw'];
		} else {
			$out['content']['rendered'] = $item['content']['rendered'] ?? '';
		}

		if ( isset( $item['_embedded']['wp:featuredmedia'][0]['source_url'] ) ) {
			$out['_embedded']['wp:featuredmedia'] = [
				[ 'source_url' => $item['_embedded']['wp:featuredmedia'][0]['source_url'] ],
			];
		}

		if ( isset( $item['_embedded']['author'][0] ) ) {
			$a = $item['_embedded']['author'][0];
			$out['_embedded']['author'] = [
				[
					'name'  => $a['name'] ?? '',
					'slug'  => $a['slug'] ?? '',
					'email' => $a['email'] ?? '',
				],
			];
		}

		if ( isset( $item['_embedded']['wp:term'] ) ) {
			$terms = [];
			foreach ( $item['_embedded']['wp:term'] as $group ) {
				$slim = [];
				foreach ( (array) $group as $t ) {
					$slim[] = [
						'taxonomy' => $t['taxonomy'] ?? '',
						'name'     => $t['name'] ?? '',
						'slug'     => $t['slug'] ?? '',
					];
				}
				$terms[] = $slim;
			}
			$out['_embedded']['wp:term'] = $terms;
		}

		if ( isset( $item['yoast_head_json']['description'] ) ) {
			$out['yoast_head_json'] = [ 'description' => $item['yoast_head_json']['description'] ];
		}

		return $out;
	}
}
