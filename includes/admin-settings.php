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

	register_setting(
		MARQ_CONVERSIONS_SETTINGS_GROUP,
		MARQ_CONVERSIONS_OPTION_CONVERSION_RULES,
		array(
			'type'              => 'array',
			'sanitize_callback' => 'marq_conversions_sanitize_conversion_rules',
			'default'           => array(),
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

	add_settings_section(
		'marq_conversions_frontend',
		__( 'Frontend conversions', 'marq-conversions' ),
		'marq_conversions_section_frontend_description',
		MARQ_CONVERSIONS_PAGE_SLUG
	);

	add_settings_field(
		'marq_conversions_rules_table',
		__( 'Track link clicks', 'marq-conversions' ),
		'marq_conversions_field_conversion_rules',
		MARQ_CONVERSIONS_PAGE_SLUG,
		'marq_conversions_frontend'
	);
}
add_action( 'admin_init', 'marq_conversions_register_settings' );

/**
 * Section blurb under Frontend conversions.
 */
function marq_conversions_section_frontend_description(): void {
	echo '<p>' . esc_html__( 'Enable rows to send an event to your webhook when visitors click matching links. Only one event per link type (tel / mailto) is used—the last enabled row wins if duplicates exist.', 'marq-conversions' ) . '</p>';
}

/**
 * Preset labels for event dropdown.
 *
 * @return array<string, string>
 */
function marq_conversions_event_preset_labels(): array {
	return array(
		'phone_click'   => __( 'Phone click', 'marq-conversions' ),
		'email_click'   => __( 'Email click', 'marq-conversions' ),
		'contact_click' => __( 'Contact click', 'marq-conversions' ),
		'lead_click'    => __( 'Lead click', 'marq-conversions' ),
		'signup_click'  => __( 'Signup click', 'marq-conversions' ),
		'download_click' => __( 'Download click', 'marq-conversions' ),
	);
}

/**
 * Sanitize conversion rules from POST.
 *
 * @param mixed $value Submitted option value.
 * @return array<int, array<string, mixed>>
 */
function marq_conversions_sanitize_conversion_rules( $value ): array {
	if ( ! is_array( $value ) ) {
		return array();
	}

	$allowed_match = array( 'tel', 'mailto' );
	$presets       = marq_conversions_preset_event_slugs();
	$out           = array();

	foreach ( $value as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$enabled = ! empty( $row['enabled'] );
		$match   = isset( $row['match'] ) ? sanitize_text_field( (string) $row['match'] ) : '';

		if ( $match === '' || ! in_array( $match, $allowed_match, true ) ) {
			continue;
		}

		$event_select = isset( $row['event'] ) ? sanitize_text_field( (string) $row['event'] ) : '';
		$event_custom = isset( $row['event_custom'] ) ? sanitize_text_field( (string) $row['event_custom'] ) : '';

		if ( $event_select === '__custom__' ) {
			$slug = sanitize_key( $event_custom );
			if ( $slug === '' || strlen( $slug ) > 64 || ! preg_match( '/^[a-z0-9_-]+$/', $slug ) ) {
				continue;
			}
			$final_event = $slug;
		} else {
			$candidate = sanitize_key( $event_select );
			if ( ! in_array( $candidate, $presets, true ) ) {
				continue;
			}
			$final_event = $candidate;
		}

		$out[] = array(
			'enabled' => $enabled,
			'match'   => $match,
			'event'   => $final_event,
		);
	}

	$by_match = array();
	foreach ( $out as $row ) {
		$by_match[ $row['match'] ] = $row;
	}

	return array_values( $by_match );
}

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
 * Render conversion rules table.
 */
function marq_conversions_field_conversion_rules(): void {
	$stored = marq_conversions_get_stored_rules();
	$labels = marq_conversions_event_preset_labels();
	$presets = marq_conversions_preset_event_slugs();

	$min_rows = 3;
	$row_count = max( $min_rows, count( $stored ) );

	echo '<table class="widefat striped" id="marq-conversions-rules" style="max-width:920px;">';
	echo '<thead><tr>';
	echo '<th style="width:48px;">' . esc_html__( 'On', 'marq-conversions' ) . '</th>';
	echo '<th>' . esc_html__( 'Track', 'marq-conversions' ) . '</th>';
	echo '<th>' . esc_html__( 'Event name', 'marq-conversions' ) . '</th>';
	echo '</tr></thead><tbody';

	printf(
		' data-next-index="%d"',
		(int) $row_count
	);
	echo '>';

	for ( $i = 0; $i < $row_count; $i++ ) {
		$row = isset( $stored[ $i ] ) ? $stored[ $i ] : array();
		marq_conversions_render_rule_row( $i, $row, $labels, $presets );
	}

	echo '</tbody></table>';

	printf(
		'<p style="margin-top:10px;"><button type="button" class="button" id="marq-conversions-add-row">%s</button></p>',
		esc_html__( 'Add row', 'marq-conversions' )
	);

	echo '<p class="description">' . esc_html__( 'Tel and mailto targets are not included in the payload (privacy). The public script loads only when at least one row is enabled with a valid track type and event.', 'marq-conversions' ) . '</p>';

	// Hidden template table for cloning new rows.
	echo '<table class="widefat" style="display:none;" aria-hidden="true"><tbody id="marq-conversions-rule-template">';
	marq_conversions_render_rule_row( '__INDEX__', array(), $labels, $presets );
	echo '</tbody></table>';
}

/**
 * Output one settings row.
 *
 * @param int|string       $index   Row index or __INDEX__ for template.
 * @param array            $row     Stored row.
 * @param array<string,string> $labels  Preset labels.
 * @param string[]         $presets Preset slugs.
 */
function marq_conversions_render_rule_row( $index, array $row, array $labels, array $presets ): void {
	$name_base = MARQ_CONVERSIONS_OPTION_CONVERSION_RULES . '[' . $index . ']';

	$enabled = ! empty( $row['enabled'] );
	$match   = isset( $row['match'] ) ? (string) $row['match'] : '';
	$event   = isset( $row['event'] ) ? sanitize_key( (string) $row['event'] ) : '';

	$is_custom = $event !== '' && ! in_array( $event, $presets, true );
	$select_value = $is_custom ? '__custom__' : ( $event !== '' ? $event : 'phone_click' );
	$custom_value = $is_custom ? $event : '';

	echo '<tr class="marq-conversions-rule-row">';

	echo '<td>';
	printf(
		'<input type="checkbox" name="%1$s[enabled]" value="1" %2$s />',
		esc_attr( $name_base ),
		checked( $enabled, true, false )
	);
	echo '</td>';

	echo '<td>';
	printf( '<select name="%s[match]">', esc_attr( $name_base ) );
	printf( '<option value="">%s</option>', esc_html__( '— None —', 'marq-conversions' ) );
	printf(
		'<option value="tel" %s>%s</option>',
		selected( $match, 'tel', false ),
		esc_html__( 'Tel link (tel:)', 'marq-conversions' )
	);
	printf(
		'<option value="mailto" %s>%s</option>',
		selected( $match, 'mailto', false ),
		esc_html__( 'Email link (mailto:)', 'marq-conversions' )
	);
	echo '</select></td>';

	echo '<td>';
	printf( '<select class="marq-event-select" name="%s[event]">', esc_attr( $name_base ) );
	foreach ( $labels as $slug => $text ) {
		if ( ! in_array( $slug, $presets, true ) ) {
			continue;
		}
		printf(
			'<option value="%s" %s>%s</option>',
			esc_attr( $slug ),
			selected( $select_value, $slug, false ),
			esc_html( $text )
		);
	}
	printf(
		'<option value="__custom__" %s>%s</option>',
		selected( $select_value, '__custom__', false ),
		esc_html__( 'Custom…', 'marq-conversions' )
	);
	echo '</select> ';

	printf(
		'<input type="text" class="marq-event-custom regular-text" name="%s[event_custom]" value="%s" placeholder="%s" autocomplete="off" />',
		esc_attr( $name_base ),
		esc_attr( $custom_value ),
		esc_attr__( 'event_slug', 'marq-conversions' )
	);

	echo '</td>';
	echo '</tr>';
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
 * Enqueue admin JS on settings page.
 *
 * @param string $hook_suffix Current admin page.
 */
function marq_conversions_admin_scripts( string $hook_suffix ): void {
	if ( $hook_suffix !== 'settings_page_' . MARQ_CONVERSIONS_PAGE_SLUG ) {
		return;
	}

	wp_enqueue_script(
		'marq-conversions-admin',
		MARQ_CONVERSIONS_PLUGIN_URL . 'assets/js/admin-conversions.js',
		array( 'jquery' ),
		MARQ_CONVERSIONS_VERSION,
		true
	);
}
add_action( 'admin_enqueue_scripts', 'marq_conversions_admin_scripts' );

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
