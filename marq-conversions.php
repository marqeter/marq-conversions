<?php
/**
 * Marq Conversions
 *
 * @package Marq_Conversions
 */

/*
Plugin Name:       Marq Conversions
Description:       Webhook logging for form POSTs and mail events (n8n / Marqeter pipeline).
Version:           0.3.0
Requires at least: 6.0
Requires PHP:       7.4
Author:             Marq
License:            GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html
Text Domain:        marq-conversions
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MARQ_CONVERSIONS_VERSION', '0.3.0' );
define( 'MARQ_CONVERSIONS_PLUGIN_FILE', __FILE__ );
define( 'MARQ_CONVERSIONS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARQ_CONVERSIONS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MARQ_CONVERSIONS_OPTION_WEBHOOK_URL', 'marq_conversions_webhook_url' );

require_once MARQ_CONVERSIONS_PLUGIN_DIR . 'includes/hook-logger.php';

if ( is_admin() ) {
	require_once MARQ_CONVERSIONS_PLUGIN_DIR . 'includes/admin-settings.php';
}

/**
 * Load plugin text domain.
 */
function marq_conversions_load_textdomain() {
	load_plugin_textdomain(
		'marq-conversions',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages'
	);
}
add_action( 'plugins_loaded', 'marq_conversions_load_textdomain' );

/**
 * Bootstrap plugin logic after WordPress is ready.
 */
function marq_conversions_init() {
	// Hook logger loads from includes/hook-logger.php. Add other features here.
}
add_action( 'init', 'marq_conversions_init' );
