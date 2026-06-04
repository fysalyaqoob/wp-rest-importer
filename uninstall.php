<?php
/**
 * Fired when the plugin is uninstalled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_option( 'wpresti_progress' );
delete_option( 'wpresti_settings' );
delete_option( 'wpresti_db_version' );

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpresti_queue" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpresti_log" );

delete_transient( 'wpresti_step_lock' );
delete_transient( 'wpresti_batch_lock' );

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_wpresti_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_wpresti_' ) . '%'
	)
);

wp_clear_scheduled_hook( 'wpresti_background_step' );
