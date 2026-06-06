<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Ajax_Handler {

	private WPRestI_Import_Runner $runner;

	public function __construct() {
		$this->runner = new WPRestI_Import_Runner();

		add_action( 'wp_ajax_wpresti_start',          [ $this, 'handle_start' ] );
		add_action( 'wp_ajax_wpresti_step',           [ $this, 'handle_step' ] );
		add_action( 'wp_ajax_wpresti_test_connection', [ $this, 'handle_test_connection' ] );
		add_action( 'wp_ajax_wpresti_cancel',         [ $this, 'handle_cancel' ] );
		add_action( 'wp_ajax_wpresti_clear_session',  [ $this, 'handle_clear_session' ] );
		add_action( 'wp_ajax_wpresti_get_progress',   [ $this, 'handle_get_progress' ] );
		add_action( 'wp_ajax_wpresti_get_log',        [ $this, 'handle_get_log' ] );
		add_action( 'wp_ajax_wpresti_download_log',   [ $this, 'handle_download_log' ] );
		add_action( 'wp_ajax_wpresti_save_settings',    [ $this, 'handle_save_settings' ] );
		add_action( 'wp_ajax_wpresti_reassign_scan',   [ $this, 'handle_reassign_scan' ] );
		add_action( 'wp_ajax_wpresti_reassign_run',     [ $this, 'handle_reassign_run' ] );

		add_action( 'wp_ajax_wpresti_fetch', [ $this, 'handle_step' ] );
		add_action( 'wp_ajax_wpresti_batch', [ $this, 'handle_step' ] );
	}

	private function verify_request(): void {
		check_ajax_referer( 'wpresti_nonce', 'nonce' );

		if ( ! WPRestI_Settings::current_user_can() ) {
			wp_send_json_error( [ 'message' => __( 'Unauthorized', 'wp-rest-importer' ) ], 403 );
		}
	}

	public function handle_start(): void {
		$this->verify_request();

		$result = $this->runner->start_import( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	public function handle_step(): void {
		$this->verify_request();

		$result = $this->runner->run_step( $this->post_params_for_step() );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	public function handle_test_connection(): void {
		$this->verify_request();

		$result = $this->runner->test_connection( wp_unslash( $_POST ) );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		wp_send_json_success( $result );
	}

	public function handle_cancel(): void {
		$this->verify_request();
		$this->runner->cancel_import();
		wp_send_json_success( [ 'message' => __( 'Import cancelled.', 'wp-rest-importer' ) ] );
	}

	public function handle_clear_session(): void {
		$this->verify_request();
		$this->runner->clear_session();
		wp_send_json_success( [ 'message' => __( 'Import session cleared.', 'wp-rest-importer' ) ] );
	}

	public function handle_get_progress(): void {
		$this->verify_request();

		$progress = get_option( 'wpresti_progress', [] );
		$store    = new WPRestI_Queue_Store();

		if ( empty( $progress ) ) {
			wp_send_json_success( [ 'phase' => 'idle' ] );
		}

		if ( ! empty( $progress['cancelled'] ) ) {
			wp_send_json_success( [ 'phase' => 'cancelled' ] );
		}

		$session_id     = $progress['session_id'] ?? '';
		$fetch_complete = (bool) ( $progress['fetch_complete'] ?? false );
		$complete       = (bool) ( $progress['complete'] ?? false );
		$pending        = $session_id ? $store->count_pending( $session_id ) : 0;

		$phase = 'import';
		if ( $complete ) {
			$phase = 'complete';
		}

		wp_send_json_success(
			[
				'phase'          => $phase,
				'fetch_complete' => $fetch_complete,
				'done'           => (int) ( $progress['done'] ?? 0 ),
				'fetched'        => (int) ( $progress['fetched'] ?? 0 ),
				'total'          => (int) ( $progress['total'] ?? 0 ),
				'queued'         => $pending,
				'skipped'        => (int) ( $progress['skipped'] ?? 0 ),
				'complete'       => $complete,
				'background'     => ! empty( $progress['background'] ),
				'slug_import'    => ! empty( $progress['slug_import'] ),
				'dry_run'        => ! empty( $progress['dry_run'] ),
				'auth_warning'   => ! empty( $progress['auth_warning'] ),
				'last_error'     => (string) ( $progress['last_error'] ?? '' ),
				'log'            => $session_id
					? $store->get_logs( $session_id, 0, 50 )
					: [],
				'log_total'      => $session_id ? $store->count_logs( $session_id ) : 0,
				'site_url'       => $progress['site_url'] ?? '',
			]
		);
	}

	public function handle_get_log(): void {
		$this->verify_request();

		$progress = get_option( 'wpresti_progress', [] );
		$offset   = max( 0, absint( $_POST['offset'] ?? 0 ) );
		$session  = $progress['session_id'] ?? '';
		$store    = new WPRestI_Queue_Store();

		if ( '' === $session ) {
			wp_send_json_success( [ 'log' => [], 'log_total' => 0, 'has_more' => false ] );
		}

		$total = $store->count_logs( $session );
		$log   = $store->get_logs( $session, $offset, 50 );

		wp_send_json_success(
			[
				'log'       => $log,
				'log_total' => $total,
				'has_more'  => ( $offset + count( $log ) ) < $total,
			]
		);
	}

	public function handle_download_log(): void {
		$this->verify_request();

		$progress = get_option( 'wpresti_progress', [] );
		$session  = $progress['session_id'] ?? '';

		if ( '' === $session ) {
			wp_send_json_error( [ 'message' => __( 'No import session found.', 'wp-rest-importer' ) ] );
		}

		$store = new WPRestI_Queue_Store();
		$csv   = $store->export_log_csv( $session );

		wp_send_json_success(
			[
				'filename' => 'wpresti-import-log-' . gmdate( 'Y-m-d-His' ) . '.csv',
				'content'  => base64_encode( $csv ),
			]
		);
	}

	public function handle_save_settings(): void {
		$this->verify_request();

		$settings = WPRestI_Settings::sanitize( wp_unslash( $_POST ) );
		update_option( WPRestI_Settings::OPTION_KEY, $settings, false );

		wp_send_json_success(
			[
				'message'  => __( 'Settings saved.', 'wp-rest-importer' ),
				'settings' => $settings,
			]
		);
	}

	public function handle_reassign_scan(): void {
		$this->verify_request();

		$groups     = [];
		$paged      = 1;
		$post_types = array_values(
			array_diff(
				get_post_types( [ 'public' => true ], 'names' ),
				[ 'attachment' ]
			)
		);

		do {
			$posts = get_posts(
				[
					'post_type'      => $post_types,
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'paged'          => $paged,
					'fields'         => 'ids',
					'meta_query'     => [
						[
							'key'     => '_original_author_login',
							'compare' => 'EXISTS',
						],
					],
				]
			);

			if ( empty( $posts ) ) {
				break;
			}

			update_meta_cache( 'post', $posts );

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

			$paged++;
		} while ( count( $posts ) === 500 );

		wp_send_json_success( [ 'groups' => array_values( $groups ) ] );
	}

	public function handle_reassign_run(): void {
		$this->verify_request();

		$reassigned = 0;
		$unmatched  = 0;
		$paged      = 1;

		$post_types = array_values(
			array_diff(
				get_post_types( [ 'public' => true ], 'names' ),
				[ 'attachment' ]
			)
		);

		do {
			$posts = get_posts(
				[
					'post_type'      => $post_types,
					'post_status'    => 'any',
					'posts_per_page' => 500,
					'paged'          => $paged,
					'fields'         => 'ids',
					'meta_query'     => [
						[
							'key'     => '_original_author_login',
							'compare' => 'EXISTS',
						],
					],
				]
			);

			if ( empty( $posts ) ) {
				break;
			}

			update_meta_cache( 'post', $posts );

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

			$paged++;
		} while ( count( $posts ) === 500 );

		wp_send_json_success(
			[
				'reassigned' => $reassigned,
				'unmatched'  => $unmatched,
			]
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function post_params_for_step(): ?array {
		$site_url = isset( $_POST['site_url'] ) ? esc_url_raw( wp_unslash( $_POST['site_url'] ) ) : '';
		if ( '' === $site_url ) {
			return null;
		}
		return wp_unslash( $_POST );
	}
}
