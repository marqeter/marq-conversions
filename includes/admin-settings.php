<?php
/**
 * Admin settings for Marq Conversions.
 *
 * @package Marq_Conversions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings group id (used with settings_fields / register_setting).
 */
const MARQ_CONVERSIONS_SETTINGS_GROUP = 'marq_conversions_settings';

/**
 * Admin page slug (options-general.php?page=…).
 */
const MARQ_CONVERSIONS_PAGE_SLUG = 'marq-conversions';

/**
 * Register settings and fields.
 */
function marq_conversions_register_settings(): void {
	register_setting(
		MARQ_CONVERSIONS_SETTINGS_GROUP,
		MARQ_CONVERSIONS_OPTION_WEBHOOK_URL,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'marq_conversions_sanitize_webhook_url',
			'default'           => '',
		)
	);

	add_settings_section(
		'marq_conversions_webhook',
		__( 'Webhook', 'marq-conversions' ),
		'__return_null',
		MARQ_CONVERSIONS_PAGE_SLUG
	);

	add_settings_field(
		MARQ_CONVERSIONS_OPTION_WEBHOOK_URL,
		__( 'Webhook URL', 'marq-conversions' ),
		'marq_conversions_field_webhook_url',
		MARQ_CONVERSIONS_PAGE_SLUG,
		'marq_conversions_webhook'
	);
}
add_action( 'admin_init', 'marq_conversions_register_settings' );

/**
 * Sanitize webhook URL on save.
 *
 * @param mixed $value Raw submitted value.
 */
function marq_conversions_sanitize_webhook_url( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}

	$value = trim( $value );

	if ( $value === '' ) {
		return '';
	}

	return esc_url_raw( $value );
}

/**
 * Render webhook URL field.
 */
function marq_conversions_field_webhook_url(): void {
	$value = get_option( MARQ_CONVERSIONS_OPTION_WEBHOOK_URL, '' );
	$value = is_string( $value ) ? $value : '';

	printf(
		'<input type="url" class="regular-text code" id="%1$s" name="%1$s" value="%2$s" placeholder="https://" autocomplete="off" />',
		esc_attr( MARQ_CONVERSIONS_OPTION_WEBHOOK_URL ),
		esc_attr( $value )
	);

	echo '<p class="description">' . esc_html__( 'Events are sent as non-blocking JSON POSTs to this URL. Leave empty to disable outbound webhooks.', 'marq-conversions' ) . '</p>';
}

/**
 * Add Settings submenu under Settings.
 */
function marq_conversions_admin_menu(): void {
	add_options_page(
		__( 'Marq Conversions', 'marq-conversions' ),
		__( 'Marq Conversions', 'marq-conversions' ),
		'manage_options',
		MARQ_CONVERSIONS_PAGE_SLUG,
		'marq_conversions_render_settings_page'
	);
}
add_action( 'admin_menu', 'marq_conversions_admin_menu' );

/**
 * Render settings page HTML.
 */
function marq_conversions_render_settings_page(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php
			settings_fields( MARQ_CONVERSIONS_SETTINGS_GROUP );
			do_settings_sections( MARQ_CONVERSIONS_PAGE_SLUG );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

/**
 * Link to settings from the Plugins list.
 *
 * @param array<string, string> $links Existing action links.
 * @return array<string, string>
 */
function marq_conversions_plugin_action_links( array $links ): array {
	$url = admin_url( 'options-general.php?page=' . MARQ_CONVERSIONS_PAGE_SLUG );

	array_unshift(
		$links,
		sprintf(
			'<a href="%s">%s</a>',
			esc_url( $url ),
			esc_html__( 'Settings', 'marq-conversions' )
		)
	);

	return $links;
}
add_filter(
	'plugin_action_links_' . plugin_basename( MARQ_CONVERSIONS_PLUGIN_FILE ),
	'marq_conversions_plugin_action_links'
);
