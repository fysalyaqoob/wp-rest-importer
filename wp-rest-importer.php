<?php
/**
 * Plugin Name: WP REST Importer
 * Plugin URI:  https://github.com/fysalyaqoob/wp-rest-importer
 * Description: Import posts, pages, media, categories, tags and authors from any public WordPress site via REST API.
 * Version:     1.0.0
 * Author:      Faisal Yaqoob
 * Author URI:  https://fysalyaqoob.com
 * License:     GPL2
 * Text Domain: wp-rest-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent WordPress auto-updating this plugin
add_filter( 'auto_update_plugin', function( $update, $item ) {
	if ( isset( $item->slug ) && $item->slug === 'wp-rest-importer' ) {
		return false;
	}
	return $update;
}, 10, 2 );

// Remove from wordpress.org update check payload
add_filter( 'http_request_args', function( $args, $url ) {
	if ( strpos( $url, 'api.wordpress.org/plugins/update-check' ) !== false
		&& isset( $args['body'] )
		&& is_array( $args['body'] )
		&& isset( $args['body']['plugins'] )
	) {
		$plugins = json_decode( $args['body']['plugins'], true );
		if ( is_array( $plugins ) && isset( $plugins['plugins']['wp-rest-importer/wp-rest-importer.php'] ) ) {
			unset( $plugins['plugins']['wp-rest-importer/wp-rest-importer.php'] );
			$args['body']['plugins'] = wp_json_encode( $plugins );
		}
	}
	return $args;
}, 10, 2 );

define( 'WPRESTI_VERSION', '1.0.0' );
define( 'WPRESTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRESTI_URL', plugin_dir_url( __FILE__ ) );

require_once WPRESTI_DIR . 'includes/class-api-client.php';
require_once WPRESTI_DIR . 'includes/class-importer.php';
require_once WPRESTI_DIR . 'includes/class-ajax-handler.php';
require_once WPRESTI_DIR . 'includes/class-admin-page.php';

add_action( 'plugins_loaded', function () {
	new WPRestI_Admin_Page();
	new WPRestI_Ajax_Handler();
} );
