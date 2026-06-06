<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Settings {

	public const OPTION_KEY = 'wpresti_settings';

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return [
			'batch_size'           => 5,
			'rest_page_size'       => 25,
			'default_import_mode'  => 'overwrite',
			'ssl_verify'           => true,
			'email_on_complete'    => false,
			'rate_limit_per_min'   => 60,
			'max_rest_pages'       => 0,
			'max_queue_attempts'   => 3,
			'meta_allowlist'       => "_thumbnail_id\n_wp_page_template\n_yoast_\nrank_math_\n_acf_",
			'import_private_meta'  => false,
			'import_serialized_meta' => true,
			'import_acf_meta'      => true,
			'import_page_template' => true,
			'import_og_image'      => true,
			'import_media_files'   => true,
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function get_all(): array {
		$stored = get_option( self::OPTION_KEY, [] );
		return array_merge( self::defaults(), is_array( $stored ) ? $stored : [] );
	}

	public static function get( string $key ) {
		$all = self::get_all();
		return $all[ $key ] ?? self::defaults()[ $key ] ?? null;
	}

	public static function batch_size(): int {
		return max( 1, min( 20, (int) self::get( 'batch_size' ) ) );
	}

	public static function rest_page_size(): int {
		return max( 1, min( 100, (int) self::get( 'rest_page_size' ) ) );
	}

	/**
	 * @return string[]
	 */
	public static function meta_allowlist(): array {
		$raw = (string) self::get( 'meta_allowlist' );
		$lines = preg_split( '/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out = [];

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}

		return $out;
	}

	/**
	 * @param array<string,mixed> $input Raw POST/settings input.
	 * @return array<string,mixed>
	 */
	public static function sanitize( array $input ): array {
		$out = self::defaults();

		if ( isset( $input['batch_size'] ) ) {
			$out['batch_size'] = max( 1, min( 20, absint( $input['batch_size'] ) ) );
		}
		if ( isset( $input['rest_page_size'] ) ) {
			$out['rest_page_size'] = max( 1, min( 100, absint( $input['rest_page_size'] ) ) );
		}
		if ( isset( $input['default_import_mode'] ) ) {
			$mode = sanitize_key( $input['default_import_mode'] );
			if ( in_array( $mode, [ 'overwrite', 'new_only', 'update_only' ], true ) ) {
				$out['default_import_mode'] = $mode;
			}
		}
		if ( isset( $input['max_rest_pages'] ) ) {
			$out['max_rest_pages'] = max( 0, min( 10000, absint( $input['max_rest_pages'] ) ) );
		}
		if ( isset( $input['max_queue_attempts'] ) ) {
			$out['max_queue_attempts'] = max( 1, min( 10, absint( $input['max_queue_attempts'] ) ) );
		}
		if ( isset( $input['meta_allowlist'] ) ) {
			$out['meta_allowlist'] = sanitize_textarea_field( $input['meta_allowlist'] );
		}
		if ( isset( $input['rate_limit_per_min'] ) ) {
			$out['rate_limit_per_min'] = max( 10, min( 120, absint( $input['rate_limit_per_min'] ) ) );
		}

		$out['ssl_verify']             = ! empty( $input['ssl_verify'] );
		$out['email_on_complete']      = ! empty( $input['email_on_complete'] );
		$out['import_private_meta']  = ! empty( $input['import_private_meta'] );
		$out['import_serialized_meta'] = ! empty( $input['import_serialized_meta'] );
		$out['import_acf_meta']        = ! empty( $input['import_acf_meta'] );
		$out['import_page_template']   = ! empty( $input['import_page_template'] );
		$out['import_og_image']        = ! empty( $input['import_og_image'] );
		$out['import_media_files']     = ! empty( $input['import_media_files'] );

		return $out;
	}

	public static function capability(): string {
		return apply_filters( 'wpresti_capability', 'manage_options' );
	}

	public static function current_user_can(): bool {
		return current_user_can( self::capability() );
	}
}
