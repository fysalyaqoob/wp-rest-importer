<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPRestI_Background {

	public const CRON_HOOK = 'wpresti_background_step';
	private const MAX_CONSECUTIVE_ERRORS = 5;

	public static function init(): void {
		add_action( self::CRON_HOOK, [ __CLASS__, 'run_scheduled_step' ] );

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			add_action( 'wpresti_schedule_background', [ __CLASS__, 'schedule_action_scheduler' ] );
		}
	}

	public static function schedule(): void {
		if ( function_exists( 'as_schedule_recurring_action' ) && ! as_next_scheduled_action( self::CRON_HOOK ) ) {
			as_schedule_recurring_action( time() + 10, 30, self::CRON_HOOK, [], 'wpresti' );
			return;
		}

		if ( wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_event( time() + 10, 'wpresti_import_interval', self::CRON_HOOK );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::CRON_HOOK, [], 'wpresti' );
		}

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

		if ( (int) ( $progress['consecutive_errors'] ?? 0 ) >= self::MAX_CONSECUTIVE_ERRORS ) {
			$progress['last_error'] = __( 'Background import paused after repeated errors.', 'wp-rest-importer' );
			update_option( 'wpresti_progress', $progress, false );
			self::unschedule();
			return;
		}

		$runner = new WPRestI_Import_Runner();
		$result = $runner->run_step( null );

		if ( is_wp_error( $result ) ) {
			$progress['last_error']         = $result->get_error_message();
			$progress['consecutive_errors'] = (int) ( $progress['consecutive_errors'] ?? 0 ) + 1;
			update_option( 'wpresti_progress', $progress, false );

			if ( $progress['consecutive_errors'] >= self::MAX_CONSECUTIVE_ERRORS ) {
				self::unschedule();
			}
			return;
		}

		if ( ! empty( $result['complete'] ) ) {
			self::unschedule();
		}
	}
}

add_filter( 'cron_schedules', [ 'WPRestI_Background', 'register_interval' ] );
