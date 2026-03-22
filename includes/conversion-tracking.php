<?php
/**
 * Frontend conversion rules: REST endpoint and public script.
 *
 * @package Marq_Conversions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST namespace and route.
 */
const MARQ_CONVERSIONS_REST_NAMESPACE = 'marq-conversions/v1';
const MARQ_CONVERSIONS_REST_ROUTE     = '/conversion';

/**
 * Allowed preset event slugs (must match admin dropdown values).
 *
 * @return string[]
 */
function marq_conversions_preset_event_slugs(): array {
	return array(
		'phone_click',
		'email_click',
		'contact_click',
		'lead_click',
		'signup_click',
		'download_click',
	);
}

/**
 * Raw rules from the database.
 *
 * @return array<int, array<string, mixed>>
 */
function marq_conversions_get_stored_rules(): array {
	$raw = get_option( MARQ_CONVERSIONS_OPTION_CONVERSION_RULES, array() );
	return is_array( $raw ) ? $raw : array();
}

/**
 * Rules enabled for the public script (match + event only).
 *
 * @return array<int, array{match: string, event: string}>
 */
function marq_conversions_get_public_rules(): array {
	$out = array();
	foreach ( marq_conversions_get_stored_rules() as $row ) {
		if ( empty( $row['enabled'] ) ) {
			continue;
		}
		$match = isset( $row['match'] ) ? (string) $row['match'] : '';
		$event = isset( $row['event'] ) ? sanitize_key( (string) $row['event'] ) : '';
		if ( ! in_array( $match, array( 'tel', 'mailto' ), true ) || $event === '' ) {
			continue;
		}
		$out[] = array(
			'match' => $match,
			'event' => $event,
		);
	}
	return $out;
}

/**
 * Whether a match/event pair is allowed by current settings.
 */
function marq_conversions_is_allowed_public_pair( string $match, string $event ): bool {
	$event = sanitize_key( $event );
	if ( ! in_array( $match, array( 'tel', 'mailto' ), true ) || $event === '' ) {
		return false;
	}
	foreach ( marq_conversions_get_public_rules() as $row ) {
		if ( $row['match'] === $match && $row['event'] === $event ) {
			return true;
		}
	}
	return false;
}

/**
 * Register REST route.
 */
function marq_conversions_register_rest_routes(): void {
	register_rest_route(
		MARQ_CONVERSIONS_REST_NAMESPACE,
		MARQ_CONVERSIONS_REST_ROUTE,
		array(
			'methods'             => 'POST',
			'callback'            => 'marq_conversions_rest_conversion_callback',
			'permission_callback' => '__return_true',
			'args'                => array(
				'event' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_key',
				),
				'match' => array(
					'type'              => 'string',
					'required'          => true,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'page_url' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
				'session_id' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'marq_conversions_rest_sanitize_session_id',
				),
				'landing_referrer' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
				'utm_source' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'utm_medium' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'utm_campaign' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'document_referrer' => array(
					'type'              => 'string',
					'required'          => false,
					'sanitize_callback' => 'esc_url_raw',
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'marq_conversions_register_rest_routes' );

/**
 * REST sanitize session id param.
 *
 * @param mixed $value Raw value.
 */
function marq_conversions_rest_sanitize_session_id( $value ): string {
	if ( ! is_string( $value ) ) {
		return '';
	}
	return marq_conversions_sanitize_session_id( $value );
}

/**
 * REST handler: validate nonce and configured pair, send webhook.
 *
 * @param WP_REST_Request $request Request.
 */
function marq_conversions_rest_conversion_callback( WP_REST_Request $request ) {
	// Anonymous: rest_cookie_check_errors validates ?_wpnonce= against wp_rest (not available in JSON for core).
	// Logged-in: that filter often returns early; event/match are still restricted to configured rules below.

	$match = $request->get_param( 'match' );
	$event = $request->get_param( 'event' );
	$match = is_string( $match ) ? $match : '';
	$event = is_string( $event ) ? sanitize_key( $event ) : '';

	if ( ! marq_conversions_is_allowed_public_pair( $match, $event ) ) {
		return new WP_Error(
			'marq_conversions_forbidden',
			__( 'This conversion is not configured.', 'marq-conversions' ),
			array( 'status' => 403 )
		);
	}

	$page_url = $request->get_param( 'page_url' );
	$page_url = is_string( $page_url ) ? esc_url_raw( $page_url ) : '';

	$session_id = $request->get_param( 'session_id' );
	$session_id = is_string( $session_id ) ? marq_conversions_sanitize_session_id( $session_id ) : '';

	$landing_ref = $request->get_param( 'landing_referrer' );
	$landing_ref = is_string( $landing_ref ) ? marq_conversions_filter_external_landing_url( $landing_ref ) : '';

	$server_http_ref = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$server_http_ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}
	$doc_ref = $request->get_param( 'document_referrer' );
	$doc_ref = is_string( $doc_ref ) ? marq_conversions_sanitize_client_document_referrer( $doc_ref ) : '';
	$http_referer_payload = $server_http_ref !== '' ? $server_http_ref : $doc_ref;

	$utm_source   = $request->get_param( 'utm_source' );
	$utm_medium   = $request->get_param( 'utm_medium' );
	$utm_campaign = $request->get_param( 'utm_campaign' );
	$utm_source   = is_string( $utm_source ) ? sanitize_text_field( $utm_source ) : '';
	$utm_medium   = is_string( $utm_medium ) ? sanitize_text_field( $utm_medium ) : '';
	$utm_campaign = is_string( $utm_campaign ) ? sanitize_text_field( $utm_campaign ) : '';

	$payload = mq_hook_logger_base_event(
		$event,
		array(
			'source'             => 'frontend_conversion',
			'link_match'         => $match,
			'page_url'           => $page_url,
			'session_id'         => $session_id,
			'http_referer'       => $http_referer_payload,
			'landing_referrer'   => $landing_ref,
			'utm_source'         => $utm_source,
			'utm_medium'         => $utm_medium,
			'utm_campaign'       => $utm_campaign,
		)
	);

	mq_hook_logger_send( $payload );

	return new WP_REST_Response(
		array( 'success' => true ),
		200
	);
}

/**
 * Enqueue tracking bootstrap on every public page (session + first-touch cookies for server-side webhooks).
 */
function marq_conversions_enqueue_tracking_bootstrap(): void {
	if ( is_admin() ) {
		return;
	}

	$handle = 'marq-conversions-bootstrap';
	$src    = MARQ_CONVERSIONS_PLUGIN_URL . 'assets/js/tracking-bootstrap.js';
	$ver    = MARQ_CONVERSIONS_VERSION;

	wp_enqueue_script(
		$handle,
		$src,
		array(),
		$ver,
		true
	);

	wp_localize_script(
		$handle,
		'marqTracking',
		array(
			'cookies' => array(
				'session'      => MARQ_CONVERSIONS_COOKIE_SESSION,
				'firstAttr'    => MARQ_CONVERSIONS_COOKIE_FIRST_ATTR,
				'landing'      => MARQ_CONVERSIONS_COOKIE_LANDING,
				'utmSource'    => MARQ_CONVERSIONS_COOKIE_UTM_SOURCE,
				'utmMedium'    => MARQ_CONVERSIONS_COOKIE_UTM_MEDIUM,
				'utmCampaign'  => MARQ_CONVERSIONS_COOKIE_UTM_CAMPAIGN,
			),
			'postFields' => array(
				'sessionId'   => MARQ_CV_POST_SID,
				'landing'     => MARQ_CV_POST_LANDING,
				'utmSource'   => MARQ_CV_POST_UTM_SOURCE,
				'utmMedium'   => MARQ_CV_POST_UTM_MEDIUM,
				'utmCampaign' => MARQ_CV_POST_UTM_CAMPAIGN,
				'docRef'      => MARQ_CV_POST_DOC_REF,
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'marq_conversions_enqueue_tracking_bootstrap', 5 );

/**
 * Enqueue tel/mailto conversion script when rules exist (depends on bootstrap).
 */
function marq_conversions_enqueue_frontend_script(): void {
	if ( is_admin() ) {
		return;
	}

	$rules = marq_conversions_get_public_rules();
	if ( $rules === array() ) {
		return;
	}

	$handle = 'marq-conversions-frontend';
	$src    = MARQ_CONVERSIONS_PLUGIN_URL . 'assets/js/frontend-conversions.js';
	$ver    = MARQ_CONVERSIONS_VERSION;

	wp_enqueue_script(
		$handle,
		$src,
		array( 'marq-conversions-bootstrap' ),
		$ver,
		true
	);

	$rest_path = MARQ_CONVERSIONS_REST_NAMESPACE . MARQ_CONVERSIONS_REST_ROUTE;
	$rest_url  = add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), rest_url( $rest_path ) );

	wp_localize_script(
		$handle,
		'marqConversions',
		array(
			'restUrl' => esc_url_raw( $rest_url ),
			'rules'   => $rules,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'marq_conversions_enqueue_frontend_script', 20 );
