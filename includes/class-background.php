<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Background {

	public const CRON_HOOK = 'wpresti_background_step';

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_step' ] );
	}

	public static function schedule(): void {
		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 10, 'wpresti_import_interval', self::CRON_HOOK );
	}

	public static function unschedule(): void {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public static function register_interval( array $schedules ): array {
		$schedules['wpresti_import_interval'] = [
			'interval' => 30,
			'display'  => __( 'Every 30 seconds (WP REST Importer)', 'wp-rest-importer' ),
		];
		return $schedules;
	}

	public static function run_scheduled_step(): void {
		$progress = get_option( 'wpresti_progress', [] );

		if ( empty( $progress['background'] ) || ! empty( $progress['cancelled'] ) ) {
			self::unschedule();
			return;
		}

		if ( ! empty( $progress['complete'] ) ) {
			self::unschedule();
			return;
		}

		$runner = new WPRestI_Import_Runner();
		$result = $runner->run_step( null );

		if ( is_wp_error( $result ) ) {
			$progress['last_error'] = $result->get_error_message();
			update_option( 'wpresti_progress', $progress, false );
			return;
		}

		if ( ! empty( $result['complete'] ) ) {
			self::unschedule();
			WPRestI_Import_Runner::maybe_send_completion_email( $progress );
		}
	}
}

add_filter( 'cron_schedules', [ 'WPRestI_Background', 'register_interval' ] );
