<?php
/**
 * Marq Conversions
 *
 * @package Marq_Conversions
 */

/*
Plugin Name:       Marq Conversions
Description:       Webhook logging, frontend tel/mailto conversions, form POSTs, and mail events.
Version:           0.6.1
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

define( 'MARQ_CONVERSIONS_VERSION', '0.6.1' );
define( 'MARQ_CONVERSIONS_PLUGIN_FILE', __FILE__ );
define( 'MARQ_CONVERSIONS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MARQ_CONVERSIONS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'MARQ_CONVERSIONS_OPTION_WEBHOOK_URL', 'marq_conversions_webhook_url' );
define( 'MARQ_CONVERSIONS_OPTION_CONVERSION_RULES', 'marq_conversions_conversion_rules' );
/** Cookie names set by tracking bootstrap (readable on server for forms/mail). */
define( 'MARQ_CONVERSIONS_COOKIE_SESSION', 'marq_cv_sid' );
define( 'MARQ_CONVERSIONS_COOKIE_FIRST_ATTR', 'marq_cv_first' );
define( 'MARQ_CONVERSIONS_COOKIE_LANDING', 'marq_cv_landing' );
define( 'MARQ_CONVERSIONS_COOKIE_UTM_SOURCE', 'marq_cv_utm_source' );
define( 'MARQ_CONVERSIONS_COOKIE_UTM_MEDIUM', 'marq_cv_utm_medium' );
define( 'MARQ_CONVERSIONS_COOKIE_UTM_CAMPAIGN', 'marq_cv_utm_campaign' );

/** Hidden input names appended to forms (submitted with POST / FormData). */
define( 'MARQ_CV_POST_SID', 'marq_cv_post_sid' );
define( 'MARQ_CV_POST_LANDING', 'marq_cv_post_landing' );
define( 'MARQ_CV_POST_UTM_SOURCE', 'marq_cv_post_utm_source' );
define( 'MARQ_CV_POST_UTM_MEDIUM', 'marq_cv_post_utm_medium' );
define( 'MARQ_CV_POST_UTM_CAMPAIGN', 'marq_cv_post_utm_campaign' );
define( 'MARQ_CV_POST_DOC_REF', 'marq_cv_post_doc_ref' );

require_once MARQ_CONVERSIONS_PLUGIN_DIR . 'includes/hook-logger.php';
require_once MARQ_CONVERSIONS_PLUGIN_DIR . 'includes/conversion-tracking.php';

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
