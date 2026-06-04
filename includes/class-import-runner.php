<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Import_Runner {

	private const LOG_UI_PAGE_SIZE   = 50;
	private const REASSIGN_PAGE_SIZE = 500;
	private const META_SOURCE_ID     = '_wpresti_source_id';
	private const META_ORIGINAL_LOGIN = '_original_author_login';
	private const CREDS_TRANSIENT_PREFIX = 'wpresti_creds_';

	private WPRestI_Queue_Store $store;

	public function __construct() {
		$this->store = new WPRestI_Queue_Store();
	}

	/**
	 * @param array<string,mixed>|null $request_params From AJAX POST; null = background/cron.
	 * @return array<string,mixed>|WP_Error
	 */
	public function run_step( ?array $request_params = null ) {
		if ( get_transient( 'wpresti_step_lock' ) ) {
			return new WP_Error( 'locked', __( 'A step is already processing. Please wait.', 'wp-rest-importer' ) );
		}
		set_transient( 'wpresti_step_lock', 1, 120 );

		$progress = get_option( 'wpresti_progress', [] );

		if ( empty( $progress ) || empty( $progress['session_id'] ) ) {
			delete_transient( 'wpresti_step_lock' );
			return new WP_Error( 'no_session', __( 'Import session expired. Please start again.', 'wp-rest-importer' ) );
		}

		if ( ! empty( $progress['cancelled'] ) ) {
			delete_transient( 'wpresti_step_lock' );
			return new WP_Error( 'cancelled', __( 'Import was cancelled.', 'wp-rest-importer' ) );
		}

		if ( ! $this->check_rate_limit() ) {
			delete_transient( 'wpresti_step_lock' );
			return new WP_Error( 'rate_limit', __( 'Too many requests. Please wait a moment.', 'wp-rest-importer' ) );
		}

		$params = $this->resolve_step_params( $progress, $request_params );

		if ( is_wp_error( $params ) ) {
			delete_transient( 'wpresti_step_lock' );
			return $params;
		}

		$this->migrate_legacy_queue( $progress );

		$session_id = $progress['session_id'];
		$batch_log  = [];
		$message    = '';

		$pending = $this->store->count_pending( $session_id );

		if ( $pending > 0 ) {
			$batch_log = $this->import_batch( $progress, $session_id, $params );
			$pending   = $this->store->count_pending( $session_id );
			$message   = sprintf(
				/* translators: 1: done count, 2: total count */
				__( 'Imported %1$d of %2$d…', 'wp-rest-importer' ),
				(int) $progress['done'],
				(int) $progress['total']
			);
		}

		$fetch_complete = (bool) ( $progress['fetch_complete'] ?? false );
		$slug_import    = ! empty( $progress['slug_import'] );

		if ( ! $slug_import && ! $fetch_complete && 0 === $pending ) {
			$fetch_result = $this->fetch_next_page( $progress, $params );

			if ( is_wp_error( $fetch_result ) ) {
				delete_transient( 'wpresti_step_lock' );
				$this->save_progress( $progress );
				return $fetch_result;
			}

			if ( ! empty( $fetch_result['auth_retry'] ) ) {
				delete_transient( 'wpresti_step_lock' );
				$this->save_progress( $progress );
				return [
					'fetch_complete' => false,
					'complete'       => false,
					'done'           => (int) $progress['done'],
					'total'          => (int) $progress['total'],
					'queued'         => $this->store->count_pending( $session_id ),
					'log'            => [],
					'log_total'      => $this->store->count_logs( $session_id ),
					'message'        => __( 'Authentication failed; continuing with public API…', 'wp-rest-importer' ),
					'auth_retry'     => true,
				];
			}

			$pending        = $this->store->count_pending( $session_id );
			$fetch_complete = (bool) ( $progress['fetch_complete'] ?? false );

			if ( $fetch_complete && 0 === $pending && 0 === (int) $progress['total'] ) {
				$this->cleanup_session( $progress );
				delete_transient( 'wpresti_step_lock' );
				return new WP_Error( 'empty', __( 'No items found matching your filters.', 'wp-rest-importer' ) );
			}

			if ( $pending > 0 && empty( $batch_log ) ) {
				$batch_log = $this->import_batch( $progress, $session_id, $params );
				$pending   = $this->store->count_pending( $session_id );
			}

			$message = $fetch_complete
				? sprintf(
					__( 'Finishing import (%1$d of %2$d)…', 'wp-rest-importer' ),
					(int) $progress['done'],
					(int) $progress['total']
				)
				: sprintf(
					__( 'Loaded %1$d of %2$d from remote site…', 'wp-rest-importer' ),
					(int) $progress['done'],
					(int) $progress['total']
				);
		}

		$pending        = $this->store->count_pending( $session_id );
		$fetch_complete = (bool) ( $progress['fetch_complete'] ?? false );
		$is_complete    = $fetch_complete && 0 === $pending;

		$progress['complete'] = $is_complete;

		if ( $is_complete ) {
			$progress['completed_at'] = time();
			self::delete_session_credentials( $session_id );
			WPRestI_Background::unschedule();
			self::maybe_send_completion_email( $progress );
			$message = sprintf(
				__( 'Import complete! %d items processed.', 'wp-rest-importer' ),
				(int) $progress['done']
			);
		}

		$this->save_progress( $progress );
		delete_transient( 'wpresti_step_lock' );

		return [
			'fetch_complete' => $fetch_complete,
			'complete'       => $is_complete,
			'cancelled'      => false,
			'done'           => (int) $progress['done'],
			'total'          => (int) $progress['total'],
			'queued'         => $pending,
			'skipped'        => (int) ( $progress['skipped'] ?? 0 ),
			'log'            => $batch_log,
			'log_total'      => $this->store->count_logs( $session_id ),
			'message'        => $message,
			'elapsed'        => $this->elapsed_label( $progress ),
		];
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function start_import( array $post_data ) {
		$params = $this->parse_import_request( $post_data );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$this->abort_previous_import();

		$session_id    = $this->new_session_id();
		$parsed_host   = wp_parse_url( $params['site_url'], PHP_URL_HOST );
		$source_domain = $parsed_host ? $parsed_host : '';
		$this->store->clear_session( $session_id );

		if ( $params['has_credentials'] ) {
			self::store_session_credentials( $session_id, $params );
		}

		$slugs = $params['slugs'];

		// Any non-empty slug list forces slug mode (never fall through to a full-site fetch).
		if ( ! empty( $slugs ) ) {
			// Slug import: only query the REST endpoints chosen under Import Type (never a full list, never CPT).
			$slug_fetch_types = $this->slug_endpoints( $params );

			$queue = $this->build_queue_for_slugs(
				$params['site_url'],
				$params,
				$slug_fetch_types,
				$slugs
			);

			if ( is_wp_error( $queue ) ) {
				return $queue;
			}

			if ( empty( $queue ) ) {
				return new WP_Error(
					'not_found',
					$this->slug_not_found_message( $params )
				);
			}

			$queued = $this->store->enqueue_many( $session_id, $queue );

			$progress = $this->new_progress( $params, $source_domain, $session_id, true );
			$progress['slug_import'] = true;
			$progress['slugs']       = $slugs;
			$progress['total']       = $queued;

			if ( ! empty( $params['run_in_background'] ) ) {
				$progress['background'] = true;
				WPRestI_Background::schedule();
			} else {
				WPRestI_Background::unschedule();
			}

			$this->save_progress( $progress );

			return [
				'phase'          => 'import',
				'slug_import'    => true,
				'fetch_complete' => true,
				'total'          => $queued,
				'queued'         => $queued,
				'background'     => ! empty( $progress['background'] ),
				'message'        => sprintf(
					/* translators: %d: number of items */
					__( 'Slug import: found %d item(s). Starting import…', 'wp-rest-importer' ),
					$queued
				),
			];
		}

		$fetch_types = $this->resolve_fetch_types( $params );

		$progress = $this->new_progress( $params, $source_domain, $session_id, false );
		$progress['fetch'] = [
			'types'          => $fetch_types,
			'type_index'     => 0,
			'page'           => 1,
			'auth_mode'      => $params['has_credentials'] ? 'auth' : 'public',
			'expected_total' => 0,
			'query'          => $this->build_rest_query( $params ),
		];

		if ( ! empty( $params['run_in_background'] ) ) {
			$progress['background'] = true;
			WPRestI_Background::schedule();
		} else {
			WPRestI_Background::unschedule();
		}

		$this->save_progress( $progress );

		return [
			'phase'          => 'import',
			'fetch_complete' => false,
			'total'          => 0,
			'queued'         => 0,
			'background'     => ! empty( $progress['background'] ),
			'message'        => __( 'Connecting to remote site…', 'wp-rest-importer' ),
		];
	}

	/**
	 * @return array<string,mixed>|WP_Error
	 */
	public function test_connection( array $post_data ) {
		$params = $this->parse_import_request( $post_data, false );

		if ( is_wp_error( $params ) ) {
			return $params;
		}

		$auth_mode = $params['has_credentials'] ? 'auth' : 'public';
		$client    = $this->make_client( $params['site_url'], $params, $auth_mode );

		$result = $client->test_connection();

		if ( is_wp_error( $result ) ) {
			if ( 'auth_failed' === $result->get_error_code() && 'auth' === $auth_mode && $params['has_credentials'] ) {
				$client = $this->make_client( $params['site_url'], $params, 'public' );
				$result = $client->test_connection();
				if ( ! is_wp_error( $result ) ) {
					$result['auth_warning'] = __( 'Credentials rejected (HTTP 401). Public API is reachable.', 'wp-rest-importer' );
				}
			}
			return $result;
		}

		return $result;
	}

	public function cancel_import(): void {
		$progress = get_option( 'wpresti_progress', [] );

		if ( ! empty( $progress['session_id'] ) ) {
			$progress['cancelled'] = true;
			$progress['complete']  = true;
			$this->store->clear_session( $progress['session_id'] );
			self::delete_session_credentials( $progress['session_id'] );
			$this->save_progress( $progress );
		}

		WPRestI_Background::unschedule();
		delete_transient( 'wpresti_step_lock' );
	}

	public function clear_session(): void {
		$progress = get_option( 'wpresti_progress', [] );

		if ( ! empty( $progress['session_id'] ) ) {
			$this->store->clear_session( $progress['session_id'] );
			self::delete_session_credentials( $progress['session_id'] );
		}

		delete_option( 'wpresti_progress' );
		WPRestI_Background::unschedule();
		delete_transient( 'wpresti_step_lock' );
	}

	/**
	 * @param array<string,mixed> $progress
	 */
	public static function maybe_send_completion_email( array $progress ): void {
		if ( ! WPRestI_Settings::get( 'email_on_complete' ) ) {
			return;
		}

		$admin_email = get_option( 'admin_email' );
		if ( ! $admin_email ) {
			return;
		}

		$subject = sprintf(
			/* translators: %s: site name */
			__( '[%s] WP REST Importer finished', 'wp-rest-importer' ),
			wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES )
		);

		$body = sprintf(
			__( "Import completed.\n\nProcessed: %1\$d\nSkipped: %2\$d\nSource: %3\$s\n", 'wp-rest-importer' ),
			(int) ( $progress['done'] ?? 0 ),
			(int) ( $progress['skipped'] ?? 0 ),
			$progress['site_url'] ?? ''
		);

		wp_mail( $admin_email, $subject, $body );
	}

	/**
	 * @param array<string,mixed> $post_data
	 * @return array<string,mixed>|WP_Error
	 */
	public function parse_import_request( array $post_data, bool $require_url = true ) {
		$site_url            = esc_url_raw( wp_unslash( $post_data['site_url'] ?? '' ) );
		$import_type         = sanitize_key( wp_unslash( $post_data['import_type'] ?? 'both' ) );
		$assign_author_id    = absint( $post_data['assign_author_id'] ?? 0 );
		$source_username     = sanitize_user( wp_unslash( $post_data['source_username'] ?? '' ), true );
		$source_app_password = trim( wp_unslash( $post_data['source_app_password'] ?? '' ) );
		$import_scope        = sanitize_key( wp_unslash( $post_data['import_scope'] ?? '' ) );
		$slug_raw            = sanitize_textarea_field(
			wp_unslash(
				$post_data['slug'] ?? $post_data['pp_slug'] ?? ''
			)
		);
		$import_mode         = sanitize_key( wp_unslash( $post_data['import_mode'] ?? WPRestI_Settings::get( 'default_import_mode' ) ) );
		$date_after          = sanitize_text_field( wp_unslash( $post_data['date_after'] ?? '' ) );
		$date_before         = sanitize_text_field( wp_unslash( $post_data['date_before'] ?? '' ) );
		$category_slug       = sanitize_title( wp_unslash( $post_data['category'] ?? '' ) );
		$status_filter       = sanitize_key( wp_unslash( $post_data['status_filter'] ?? '' ) );
		$cpt_rest_base       = sanitize_key( wp_unslash( $post_data['cpt_rest_base'] ?? '' ) );
		$target_post_type    = sanitize_key( wp_unslash( $post_data['target_post_type'] ?? '' ) );
		$run_in_background   = ! empty( $post_data['run_in_background'] );

		if ( $target_post_type && ! post_type_exists( $target_post_type ) ) {
			return new WP_Error(
				'invalid_post_type',
				sprintf(
					/* translators: %s: post type slug */
					__( 'Post type “%s” is not registered on this site.', 'wp-rest-importer' ),
					$target_post_type
				)
			);
		}

		if ( ! in_array( $import_mode, [ 'overwrite', 'new_only', 'update_only' ], true ) ) {
			$import_mode = 'overwrite';
		}

		$allowed_status = [ '', 'publish', 'future', 'draft', 'pending', 'private' ];
		if ( ! in_array( $status_filter, $allowed_status, true ) ) {
			$status_filter = '';
		}

		$slugs = $this->parse_slug_list( $slug_raw );

		if ( 'slug' === $import_scope && empty( $slugs ) ) {
			return new WP_Error(
				'missing_slug',
				__( 'Enter at least one valid slug for a slug import.', 'wp-rest-importer' )
			);
		}

		if ( $assign_author_id > 0 && ! get_user_by( 'id', $assign_author_id ) ) {
			$assign_author_id = 0;
		}

		if ( $require_url && ! $site_url ) {
			return new WP_Error( 'missing_url', __( 'Site URL is required.', 'wp-rest-importer' ) );
		}

		return [
			'site_url'            => $site_url,
			'import_type'         => $import_type,
			'assign_author_id'    => $assign_author_id,
			'source_username'     => $source_username,
			'source_app_password' => $source_app_password,
			'has_credentials'     => $source_username !== '' && $source_app_password !== '',
			'slugs'               => $slugs,
			'import_mode'         => $import_mode,
			'date_after'          => $date_after,
			'date_before'         => $date_before,
			'category'            => $category_slug,
			'status_filter'       => $status_filter,
			'cpt_rest_base'       => $cpt_rest_base,
			'target_post_type'    => $target_post_type,
			'run_in_background'   => $run_in_background,
			'import_scope'        => $import_scope,
		];
	}

	/**
	 * Step/cron params: always prefer saved session; never let a step request start a full fetch during slug jobs.
	 *
	 * @param array<string,mixed>      $progress
	 * @param array<string,mixed>|null $request_params
	 * @return array<string,mixed>|WP_Error
	 */
	private function resolve_step_params( array $progress, ?array $request_params ) {
		$params = $this->params_from_progress( $progress );

		if ( null === $request_params ) {
			return $params;
		}

		$parsed = $this->parse_import_request( $request_params, false );

		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( ! empty( $progress['slug_import'] ) ) {
			if ( $parsed['has_credentials'] ) {
				$params['source_username']     = $parsed['source_username'];
				$params['source_app_password'] = $parsed['source_app_password'];
				$params['has_credentials']     = true;
			}
			return $params;
		}

		return $parsed;
	}

	/**
	 * @param array<string,mixed> $progress
	 * @return array<string,mixed>|WP_Error
	 */
	private function params_from_progress( array $progress ) {
		$session_id = $progress['session_id'] ?? '';
		$params     = [
			'site_url'            => $progress['site_url'] ?? '',
			'import_type'         => $progress['import_type'] ?? 'both',
			'assign_author_id'    => (int) ( $progress['assign_author_id'] ?? 0 ),
			'source_username'     => '',
			'source_app_password' => '',
			'has_credentials'     => false,
			'slugs'               => is_array( $progress['slugs'] ?? null ) ? $progress['slugs'] : [],
			'import_mode'         => $progress['import_mode'] ?? 'overwrite',
			'date_after'          => $progress['date_after'] ?? '',
			'date_before'         => $progress['date_before'] ?? '',
			'category'            => $progress['category'] ?? '',
			'status_filter'       => $progress['status_filter'] ?? '',
			'cpt_rest_base'       => $progress['cpt_rest_base'] ?? '',
			'target_post_type'    => $progress['target_post_type'] ?? '',
			'run_in_background'   => ! empty( $progress['background'] ),
		];

		$creds = self::get_session_credentials( $session_id );
		if ( $creds ) {
			$params['source_username']     = $creds['username'];
			$params['source_app_password'] = $creds['password'];
			$params['has_credentials']     = true;
		}

		return $params;
	}

	/**
	 * @return string[]
	 */
	/**
	 * Normalize user input into a WordPress post slug (handles /paths/, URLs, etc.).
	 */
	public static function normalize_slug( string $raw ): string {
		$raw = trim( wp_strip_all_tags( $raw ) );
		if ( '' === $raw ) {
			return '';
		}

		if ( preg_match( '#^https?://#i', $raw ) ) {
			$path = wp_parse_url( $raw, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$raw = $path;
			}
		}

		$raw = trim( $raw, '/' );

		if ( str_contains( $raw, '/' ) ) {
			$segments = array_values( array_filter( explode( '/', $raw ) ) );
			if ( ! empty( $segments ) ) {
				$raw = (string) end( $segments );
			}
		}

		return sanitize_title( $raw );
	}

	/**
	 * Whether a REST item matches the requested slug (handles link/path fallbacks).
	 *
	 * @param array<string,mixed> $item
	 */
	public static function item_matches_slug( array $item, string $slug ): bool {
		$slug = sanitize_title( $slug );
		if ( '' === $slug ) {
			return false;
		}

		$item_slug = sanitize_title( $item['slug'] ?? '' );
		if ( $item_slug === $slug ) {
			return true;
		}

		$link = $item['link'] ?? '';
		if ( is_string( $link ) && '' !== $link ) {
			$path = wp_parse_url( $link, PHP_URL_PATH );
			if ( is_string( $path ) && '' !== $path ) {
				$path = trim( $path, '/' );
				if ( $path === $slug ) {
					return true;
				}
				$segments = explode( '/', $path );
				if ( ! empty( $segments ) && (string) end( $segments ) === $slug ) {
					return true;
				}
			}
		}

		return false;
	}

	private function parse_slug_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}

		$raw   = str_replace( [ "\r\n", "\r" ], "\n", $raw );
		$lines = preg_split( '/[\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$slugs = [];

		foreach ( $lines as $part ) {
			$slug = self::normalize_slug( $part );
			if ( '' !== $slug ) {
				$slugs[] = $slug;
			}
		}

		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Stop any in-progress import and clear its queue/log before starting a new one.
	 */
	private function abort_previous_import(): void {
		$old = get_option( 'wpresti_progress', [] );

		if ( ! empty( $old['session_id'] ) ) {
			$this->store->clear_session( $old['session_id'] );
			self::delete_session_credentials( $old['session_id'] );
		}

		delete_option( 'wpresti_progress' );
		delete_transient( 'wpresti_step_lock' );
		WPRestI_Background::unschedule();
	}

	/**
	 * @param array<string,mixed> $params
	 * @return string[]
	 */
	/**
	 * REST endpoints for a full-site (paginated) import.
	 *
	 * @param array<string,mixed> $params
	 * @return string[]
	 */
	private function resolve_fetch_types( array $params ): array {
		$types = $this->resolve_slug_fetch_types( $params );

		if ( ! empty( $params['cpt_rest_base'] ) && empty( $params['slugs'] ) ) {
			$types[] = $params['cpt_rest_base'];
		}

		return array_values( array_unique( $types ) );
	}

	/**
	 * REST endpoints for slug lookup — strictly follows Import Type (posts / pages / both).
	 *
	 * @param array<string,mixed> $params
	 * @return string[]
	 */
	/**
	 * REST endpoints to probe for a slug import. A slug points at a single item,
	 * so we always try both core types (and any CPT base) rather than filtering
	 * by the "Remote content type" selector. Otherwise, importing a page by slug
	 * while that selector is on "Posts" silently finds nothing.
	 *
	 * @param array<string,mixed> $params
	 * @return string[]
	 */
	private function slug_endpoints( array $params ): array {
		$types = [ 'posts', 'pages' ];

		if ( ! empty( $params['cpt_rest_base'] ) ) {
			$types[] = $params['cpt_rest_base'];
		}

		return array_values( array_unique( $types ) );
	}

	private function resolve_slug_fetch_types( array $params ): array {
		$import_type = $params['import_type'] ?? 'both';
		$types       = [];

		if ( 'posts' === $import_type ) {
			$types[] = 'posts';
		} elseif ( 'pages' === $import_type ) {
			$types[] = 'pages';
		} else {
			$types[] = 'posts';
			$types[] = 'pages';
		}

		return $types;
	}

	private function slug_not_found_message( array $params ): string {
		$import_type = $params['import_type'] ?? 'both';

		if ( 'pages' === $import_type ) {
			return __( 'No page found with the given slug(s) on the source site.', 'wp-rest-importer' );
		}
		if ( 'posts' === $import_type ) {
			return __( 'No post found with the given slug(s) on the source site.', 'wp-rest-importer' );
		}

		return __( 'No post or page found with the given slug(s) on the source site.', 'wp-rest-importer' );
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,string>
	 */
	private function build_rest_query( array $params ): array {
		$query = [];

		if ( ! empty( $params['date_after'] ) ) {
			$query['after'] = gmdate( 'c', strtotime( $params['date_after'] . ' 00:00:00 UTC' ) );
		}
		if ( ! empty( $params['date_before'] ) ) {
			$query['before'] = gmdate( 'c', strtotime( $params['date_before'] . ' 23:59:59 UTC' ) );
		}
		if ( ! empty( $params['status_filter'] ) ) {
			$query['status'] = $params['status_filter'];
		}
		return apply_filters( 'wpresti_rest_query_args', $query, $params );
	}

	/**
	 * @param array<string,mixed> $progress
	 * @param array<string,mixed> $params
	 * @return array<int,array<string,mixed>>
	 */
	private function import_batch( array &$progress, string $session_id, array $params ): array {
		$batch    = $this->store->claim_batch( $session_id, WPRestI_Settings::batch_size() );
		$importer = new WPRestI_Importer();
		$importer->set_source_domain( $progress['source_domain'] ?? '' );
		$importer->set_source_url( $progress['site_url'] ?? '' );
		$importer->set_assign_author( (int) ( $progress['assign_author_id'] ?? 0 ) );
		$importer->set_import_mode( $progress['import_mode'] ?? 'overwrite' );

		$batch_log = [];

		foreach ( $batch as $item_wrapper ) {
			$source_type = $item_wrapper['post_type'];
			$local_type  = $this->resolve_local_post_type( $source_type, $progress );
			$item        = apply_filters( 'wpresti_item_data', $item_wrapper['data'], $source_type, $progress );

			$result         = $importer->import_item( $item, $local_type, $source_type );
			$result['time'] = current_time( 'H:i:s' );

			if ( $local_type !== $source_type ) {
				$result['type'] = $source_type . ' → ' . $local_type;
			}

			if ( 'Skipped' === ( $result['action'] ?? '' ) ) {
				$progress['skipped'] = (int) ( $progress['skipped'] ?? 0 ) + 1;
			} else {
				$progress['done']++;
			}

			$batch_log[] = $result;
			$this->store->insert_log( $session_id, $result );

			do_action( 'wpresti_after_import_item', $result, $item, $progress );
		}

		return $batch_log;
	}

	/**
	 * @param array<string,mixed> $progress
	 * @param array<string,mixed> $params
	 * @return true|array{auth_retry:bool}|WP_Error
	 */
	private function fetch_next_page( array &$progress, array $params ) {
		if ( ! empty( $progress['slug_import'] ) ) {
			$progress['fetch_complete'] = true;
			unset( $progress['fetch'] );
			return true;
		}

		$fetch_state = $progress['fetch'] ?? null;

		if ( ! is_array( $fetch_state ) || empty( $fetch_state['types'] ) ) {
			$progress['fetch_complete'] = true;
			return true;
		}

		$type_index = (int) ( $fetch_state['type_index'] ?? 0 );
		$page       = (int) ( $fetch_state['page'] ?? 1 );
		$auth_mode  = $fetch_state['auth_mode'] ?? 'public';

		if ( $type_index >= count( $fetch_state['types'] ) ) {
			$progress['fetch_complete'] = true;
			unset( $progress['fetch'] );
			return true;
		}

		$endpoint  = $fetch_state['types'][ $type_index ];
		$client    = $this->make_client( $params['site_url'], $params, $auth_mode );
		$extra = $fetch_state['query'] ?? $this->build_rest_query( $params );

		if ( ! empty( $params['category'] ) && 'posts' === $endpoint ) {
			if ( empty( $fetch_state['category_id'] ) ) {
				$fetch_state['category_id'] = $client->get_term_id_by_slug( 'categories', $params['category'] );
				$progress['fetch']          = $fetch_state;
			}
			if ( ! empty( $fetch_state['category_id'] ) ) {
				$extra['categories'] = (int) $fetch_state['category_id'];
			}
		}

		$page_data = $client->fetch_page(
			$endpoint,
			$page,
			WPRestI_Settings::rest_page_size(),
			$extra
		);

		if ( is_wp_error( $page_data ) ) {
			if ( 'auth_failed' === $page_data->get_error_code() && 'auth' === $auth_mode ) {
				$fetch_state['auth_mode'] = 'public';
				$progress['fetch']        = $fetch_state;
				return [ 'auth_retry' => true ];
			}
			return $page_data;
		}

		$items = [];

		foreach ( $page_data['items'] as $item ) {
			$items[] = [
				'data'      => $this->trim_queue_item( $item ),
				'post_type' => $this->rest_endpoint_to_post_type( $endpoint ),
			];
		}

		$this->store->enqueue_many( $progress['session_id'], $items );

		if ( 1 === $page ) {
			$fetch_state['expected_total'] = (int) ( $fetch_state['expected_total'] ?? 0 ) + (int) $page_data['total'];
			$progress['total']             = $fetch_state['expected_total'];
		}

		$page++;
		if ( $page > $page_data['total_pages'] ) {
			$type_index++;
			$page = 1;
		}

		$fetch_state['type_index'] = $type_index;
		$fetch_state['page']       = $page;
		$progress['fetch']         = $fetch_state;

		if ( $type_index >= count( $fetch_state['types'] ) ) {
			$progress['fetch_complete'] = true;
			unset( $progress['fetch'] );
		}

		return true;
	}

	/**
	 * Map source REST item type to the post type used on this site.
	 */
	private function resolve_local_post_type( string $source_type, array $progress ): string {
		$target = sanitize_key( $progress['target_post_type'] ?? '' );

		if ( '' === $target ) {
			return $source_type;
		}

		$target = apply_filters( 'wpresti_target_post_type', $target, $source_type, $progress );

		return post_type_exists( $target ) ? $target : $source_type;
	}

	private function rest_endpoint_to_post_type( string $endpoint ): string {
		$map = [
			'posts' => 'post',
			'pages' => 'page',
		];

		return $map[ $endpoint ] ?? $endpoint;
	}

	/**
	 * @param array<string,mixed> $params
	 * @param string[]            $fetch_types
	 * @param string[]            $slugs
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private function build_queue_for_slugs(
		string $site_url,
		array $params,
		array $fetch_types,
		array $slugs
	) {
		$auth_mode = $params['has_credentials'] ? 'auth' : 'public';
		$client    = $this->make_client( $site_url, $params, $auth_mode );
		$queue     = [];

		foreach ( $slugs as $slug ) {
			foreach ( $fetch_types as $endpoint ) {
				$items = $client->fetch_by_slug( $endpoint, $slug );

				if ( is_wp_error( $items ) ) {
					if ( 'auth_failed' === $items->get_error_code() && 'auth' === $auth_mode ) {
						$auth_mode = 'public';
						$client    = $this->make_client( $site_url, $params, $auth_mode );
						$items     = $client->fetch_by_slug( $endpoint, $slug );
					}
					if ( is_wp_error( $items ) ) {
						return $items;
					}
				}

				if ( ! is_array( $items ) ) {
					$items = [];
				}

				foreach ( $items as $item ) {
					if ( ! is_array( $item ) || ! self::item_matches_slug( $item, $slug ) ) {
						continue;
					}

					$queue[] = [
						'data'      => $this->trim_queue_item( $item ),
						'post_type' => $this->rest_endpoint_to_post_type( $endpoint ),
					];
				}
			}
		}

		usort(
			$queue,
			function ( $a, $b ) {
				return (int) ( $a['data']['parent'] ?? 0 ) <=> (int) ( $b['data']['parent'] ?? 0 );
			}
		);

		return $queue;
	}

	/**
	 * @param array<string,mixed> $params
	 */
	private function make_client( string $site_url, array $params, string $auth_mode ): WPRestI_API_Client {
		$client = new WPRestI_API_Client( $site_url );
		$client->set_ssl_verify( (bool) WPRestI_Settings::get( 'ssl_verify' ) );

		if ( 'auth' === $auth_mode && ! empty( $params['has_credentials'] ) ) {
			$client->set_credentials(
				$params['source_username'],
				$params['source_app_password']
			);
		}

		return $client;
	}

	/**
	 * @param array<string,mixed> $params
	 * @return array<string,mixed>
	 */
	private function new_progress(
		array $params,
		string $source_domain,
		string $session_id,
		bool $fetch_complete
	): array {
		return [
			'session_id'       => $session_id,
			'site_url'         => $params['site_url'],
			'source_domain'    => $source_domain,
			'assign_author_id' => $params['assign_author_id'],
			'import_type'      => $params['import_type'],
			'import_mode'      => $params['import_mode'],
			'date_after'       => $params['date_after'],
			'date_before'      => $params['date_before'],
			'category'         => $params['category'],
			'status_filter'    => $params['status_filter'],
			'cpt_rest_base'    => $params['cpt_rest_base'],
			'target_post_type' => $params['target_post_type'],
			'total'            => 0,
			'done'             => 0,
			'skipped'          => 0,
			'complete'         => false,
			'cancelled'        => false,
			'fetch_complete'   => $fetch_complete,
			'started_at'       => time(),
			'background'       => ! empty( $params['run_in_background'] ),
		];
	}

	private function migrate_legacy_queue( array &$progress ): void {
		if ( empty( $progress['queue'] ) || empty( $progress['session_id'] ) ) {
			unset( $progress['queue'] );
			return;
		}

		$this->store->enqueue_many( $progress['session_id'], $progress['queue'] );
		unset( $progress['queue'] );
		$this->save_progress( $progress );
	}

	/**
	 * @param array<string,mixed> $progress
	 */
	private function save_progress( array $progress ): void {
		unset( $progress['queue'] );
		update_option( 'wpresti_progress', $progress, false );
	}

	/**
	 * @param array<string,mixed> $progress
	 */
	private function cleanup_session( array $progress ): void {
		if ( ! empty( $progress['session_id'] ) ) {
			$this->store->clear_session( $progress['session_id'] );
			self::delete_session_credentials( $progress['session_id'] );
		}
		delete_option( 'wpresti_progress' );
		WPRestI_Background::unschedule();
	}

	private function new_session_id(): string {
		return wp_generate_password( 16, false );
	}

	private function check_rate_limit(): bool {
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			return true;
		}

		$limit = (int) WPRestI_Settings::get( 'rate_limit_per_min' );
		$key   = 'wpresti_rate_' . $user_id;
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return false;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );
		return true;
	}

	/**
	 * @param array<string,mixed> $progress
	 */
	private function elapsed_label( array $progress ): string {
		$started = (int) ( $progress['started_at'] ?? 0 );
		if ( $started <= 0 ) {
			return '';
		}
		$secs = time() - $started;
		if ( $secs < 60 ) {
			return sprintf( __( '%ds elapsed', 'wp-rest-importer' ), $secs );
		}
		return sprintf( __( '%dm %ds elapsed', 'wp-rest-importer' ), (int) floor( $secs / 60 ), $secs % 60 );
	}

	/**
	 * @param array<string,mixed> $session_id
	 */
	private static function store_session_credentials( string $session_id, array $params ): void {
		set_transient(
			self::CREDS_TRANSIENT_PREFIX . $session_id,
			[
				'username' => $params['source_username'],
				'password' => $params['source_app_password'],
			],
			DAY_IN_SECONDS
		);
	}

	/**
	 * @return array{username:string,password:string}|null
	 */
	private static function get_session_credentials( string $session_id ): ?array {
		if ( '' === $session_id ) {
			return null;
		}
		$creds = get_transient( self::CREDS_TRANSIENT_PREFIX . $session_id );
		return is_array( $creds ) ? $creds : null;
	}

	private static function delete_session_credentials( string $session_id ): void {
		delete_transient( self::CREDS_TRANSIENT_PREFIX . $session_id );
	}

	/**
	 * @param array<string,mixed> $item
	 * @return array<string,mixed>
	 */
	public function trim_queue_item( array $item ): array {
		$out = [
			'id'             => (int) ( $item['id'] ?? 0 ),
			'parent'         => (int) ( $item['parent'] ?? 0 ),
			'slug'           => $item['slug'] ?? '',
			'title'          => [ 'rendered' => $item['title']['rendered'] ?? '' ],
			'status'         => $item['status'] ?? 'publish',
			'date_gmt'       => $item['date_gmt'] ?? '',
			'menu_order'     => (int) ( $item['menu_order'] ?? 0 ),
			'comment_status' => $item['comment_status'] ?? 'open',
			'ping_status'    => $item['ping_status'] ?? 'open',
			'sticky'         => ! empty( $item['sticky'] ),
			'format'         => sanitize_key( $item['format'] ?? 'standard' ),
			'content'        => [],
			'excerpt'        => [ 'rendered' => $item['excerpt']['rendered'] ?? '' ],
			'_embedded'      => [],
			'meta'           => is_array( $item['meta'] ?? null ) ? $item['meta'] : [],
			'link'           => esc_url_raw( $item['link'] ?? '' ),
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

		if ( isset( $item['yoast_head_json'] ) && is_array( $item['yoast_head_json'] ) ) {
			$y = $item['yoast_head_json'];
			$out['yoast_head_json'] = [
				'title'       => $y['title'] ?? '',
				'description' => $y['description'] ?? '',
				'og_title'    => $y['og_title'] ?? '',
				'og_image'    => is_array( $y['og_image'] ?? null ) ? ( $y['og_image'][0]['url'] ?? '' ) : ( $y['og_image'] ?? '' ),
			];
		}

		return apply_filters( 'wpresti_trim_queue_item', $out, $item );
	}
}
