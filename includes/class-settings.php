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
			'batch_size'          => 5,
			'rest_page_size'      => 25,
			'default_import_mode' => 'overwrite',
			'ssl_verify'          => true,
			'email_on_complete'   => false,
			'rate_limit_per_min'  => 60,
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
		$out['ssl_verify']        = ! empty( $input['ssl_verify'] );
		$out['email_on_complete'] = ! empty( $input['email_on_complete'] );
		if ( isset( $input['rate_limit_per_min'] ) ) {
			$out['rate_limit_per_min'] = max( 10, min( 120, absint( $input['rate_limit_per_min'] ) ) );
		}

		return $out;
	}

	public static function capability(): string {
		return apply_filters( 'wpresti_capability', 'manage_options' );
	}

	public static function current_user_can(): bool {
		return current_user_can( self::capability() );
	}
}
