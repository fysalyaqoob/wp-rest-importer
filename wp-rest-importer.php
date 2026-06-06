<?php
/**
 * Plugin Name: WP REST Importer
 * Plugin URI:  https://github.com/fysalyaqoob/wp-rest-importer
 * Description: Import posts, pages, media, categories, tags and authors from any public WordPress site via REST API.
 * Version:     1.1.0
 * Author:      Faisal Yaqoob
 * Author URI:  https://fysalyaqoob.com
 * License:     GPL2
 * Text Domain: wp-rest-importer
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter( 'auto_update_plugin', function( $update, $item ) {
	if ( isset( $item->slug ) && $item->slug === 'wp-rest-importer' ) {
		return false;
	}
	return $update;
}, 10, 2 );

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

define( 'WPRESTI_VERSION', '1.1.0' );
define( 'WPRESTI_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPRESTI_URL', plugin_dir_url( __FILE__ ) );

require_once WPRESTI_DIR . 'includes/class-settings.php';
require_once WPRESTI_DIR . 'includes/class-api-client.php';
require_once WPRESTI_DIR . 'includes/class-queue-store.php';
require_once WPRESTI_DIR . 'includes/class-importer-links.php';
require_once WPRESTI_DIR . 'includes/class-importer-media.php';
require_once WPRESTI_DIR . 'includes/class-importer-meta.php';
require_once WPRESTI_DIR . 'includes/class-importer-terms.php';
require_once WPRESTI_DIR . 'includes/class-importer-gutenberg.php';
require_once WPRESTI_DIR . 'includes/class-importer.php';
require_once WPRESTI_DIR . 'includes/class-import-runner.php';
require_once WPRESTI_DIR . 'includes/class-background.php';
require_once WPRESTI_DIR . 'includes/class-ajax-handler.php';
require_once WPRESTI_DIR . 'includes/class-admin-page.php';

register_activation_hook( __FILE__, function () {
	WPRestI_Queue_Store::install_tables();
} );

add_action( 'plugins_loaded', function () {
	WPRestI_Queue_Store::ensure_tables();
	WPRestI_Background::init();
	new WPRestI_Admin_Page();
	new WPRestI_Ajax_Handler();
} );
