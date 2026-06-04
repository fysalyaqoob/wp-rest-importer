<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists import queue items and log rows in custom tables (not wp_options).
 *
 * wp_options is only suitable for small metadata; post payloads for thousands of
 * items exceed option size limits and slow every read/write.
 */
class WPRestI_Queue_Store {

	public const DB_VERSION     = 1;
	public const OPTION_DB_VER  = 'wpresti_db_version';
	public const REST_PAGE_SIZE = 25;

	/**
	 * Create or upgrade custom tables.
	 */
	public static function install_tables(): void {
		global $wpdb;

		$charset = $wpdb->get_charset_collate();
		$queue   = $wpdb->prefix . 'wpresti_queue';
		$log     = $wpdb->prefix . 'wpresti_log';

		$sql = "CREATE TABLE {$queue} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(32) NOT NULL,
			post_type varchar(20) NOT NULL,
			source_parent bigint(20) unsigned NOT NULL DEFAULT 0,
			source_id bigint(20) unsigned NOT NULL DEFAULT 0,
			payload longtext NOT NULL,
			PRIMARY KEY  (id),
			KEY session_sort (session_id, source_parent, source_id)
		) {$charset};
		CREATE TABLE {$log} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id varchar(32) NOT NULL,
			title varchar(500) NOT NULL DEFAULT '',
			item_type varchar(20) NOT NULL DEFAULT '',
			format varchar(50) NOT NULL DEFAULT '',
			status varchar(255) NOT NULL DEFAULT '',
			action varchar(20) NOT NULL DEFAULT '',
			logged_at varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY session_log (session_id, id)
		) {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::OPTION_DB_VER, self::DB_VERSION, false );
	}

	public static function ensure_tables(): void {
		if ( (int) get_option( self::OPTION_DB_VER, 0 ) < self::DB_VERSION ) {
			self::install_tables();
		}
	}

	public function queue_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpresti_queue';
	}

	public function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpresti_log';
	}

	public function clear_session( string $session_id ): void {
		global $wpdb;

		if ( '' === $session_id ) {
			return;
		}

		$wpdb->delete( $this->queue_table(), [ 'session_id' => $session_id ], [ '%s' ] );
		$wpdb->delete( $this->log_table(), [ 'session_id' => $session_id ], [ '%s' ] );
	}

	/**
	 * @param string                         $session_id Session key.
	 * @param array<int,array<string,mixed>> $items      Each: data, post_type.
	 */
	public function enqueue_many( string $session_id, array $items ): int {
		global $wpdb;

		if ( '' === $session_id || empty( $items ) ) {
			return 0;
		}

		$table  = $this->queue_table();
		$insert = 0;

		foreach ( $items as $wrapper ) {
			$data      = $wrapper['data'] ?? [];
			$post_type = $wrapper['post_type'] ?? 'post';
			$payload   = wp_json_encode( $data );

			if ( ! $payload ) {
				continue;
			}

			$wpdb->insert(
				$table,
				[
					'session_id'    => $session_id,
					'post_type'     => $post_type,
					'source_parent' => (int) ( $data['parent'] ?? 0 ),
					'source_id'     => (int) ( $data['id'] ?? 0 ),
					'payload'       => $payload,
				],
				[ '%s', '%s', '%d', '%d', '%s' ]
			);

			if ( false !== $wpdb->insert_id ) {
				$insert++;
			}
		}

		return $insert;
	}

	public function count_pending( string $session_id ): int {
		global $wpdb;

		if ( '' === $session_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->queue_table() . ' WHERE session_id = %s',
				$session_id
			)
		);
	}

	/**
	 * @return array<int,array{row_id:int, data:array<string,mixed>, post_type:string}>
	 */
	public function claim_batch( string $session_id, int $limit ): array {
		global $wpdb;

		if ( '' === $session_id || $limit < 1 ) {
			return [];
		}

		$table = $this->queue_table();
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, post_type, payload FROM {$table}
				WHERE session_id = %s
				ORDER BY source_parent ASC, source_id ASC
				LIMIT %d",
				$session_id,
				$limit
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return [];
		}

		$batch = [];
		$ids   = [];

		foreach ( $rows as $row ) {
			$data = json_decode( $row['payload'], true );
			if ( ! is_array( $data ) ) {
				$ids[] = (int) $row['id'];
				continue;
			}

			$batch[] = [
				'row_id'    => (int) $row['id'],
				'data'      => $data,
				'post_type' => $row['post_type'],
			];
			$ids[]   = (int) $row['id'];
		}

		if ( ! empty( $ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE id IN ({$placeholders})",
					...$ids
				)
			);
		}

		return $batch;
	}

	/**
	 * @param array<string,mixed> $entry Importer result row.
	 */
	public function insert_log( string $session_id, array $entry ): void {
		global $wpdb;

		if ( '' === $session_id ) {
			return;
		}

		$wpdb->insert(
			$this->log_table(),
			[
				'session_id' => $session_id,
				'title'      => mb_substr( (string) ( $entry['title'] ?? '' ), 0, 500 ),
				'item_type'  => (string) ( $entry['type'] ?? '' ),
				'format'     => (string) ( $entry['format'] ?? '' ),
				'status'     => mb_substr( (string) ( $entry['status'] ?? '' ), 0, 255 ),
				'action'     => (string) ( $entry['action'] ?? '' ),
				'logged_at'  => (string) ( $entry['time'] ?? current_time( 'H:i:s' ) ),
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
		);
	}

	public function count_logs( string $session_id ): int {
		global $wpdb;

		if ( '' === $session_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->log_table() . ' WHERE session_id = %s',
				$session_id
			)
		);
	}

	/**
	 * Newest entries first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_logs( string $session_id, int $offset, int $limit ): array {
		global $wpdb;

		if ( '' === $session_id || $limit < 1 ) {
			return [];
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT title, item_type AS type, format, status, action, logged_at AS time
				FROM ' . $this->log_table() . '
				WHERE session_id = %s
				ORDER BY id DESC
				LIMIT %d OFFSET %d',
				$session_id,
				$limit,
				max( 0, $offset )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : [];
	}

	/**
	 * Export all log rows for a session as CSV string.
	 */
	public function export_log_csv( string $session_id ): string {
		global $wpdb;

		if ( '' === $session_id ) {
			return '';
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT title, item_type, format, status, action, logged_at
				FROM ' . $this->log_table() . '
				WHERE session_id = %s
				ORDER BY id ASC',
				$session_id
			),
			ARRAY_A
		);

		$out = fopen( 'php://temp', 'r+' );
		fputcsv( $out, [ 'Title', 'Type', 'Format', 'Status', 'Action', 'Time' ] );

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$out,
				[
					$row['title'] ?? '',
					$row['item_type'] ?? '',
					$row['format'] ?? '',
					$row['status'] ?? '',
					$row['action'] ?? '',
					$row['logged_at'] ?? '',
				]
			);
		}

		rewind( $out );
		$csv = stream_get_contents( $out );
		fclose( $out );

		return $csv ?: '';
	}
}
