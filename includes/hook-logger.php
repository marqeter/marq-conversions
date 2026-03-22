<?php
/**
 * Webhook logger for form and mail events.
 *
 * @package Marq_Conversions
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const MQ_HOOK_LOG_TIMEOUT = 0.5;

/**
 * Sanitize session id from cookie or client.
 */
function marq_conversions_sanitize_session_id( string $id ): string {
	$id = trim( $id );
	if ( $id === '' || strlen( $id ) > 80 ) {
		return '';
	}
	if ( ! preg_match( '/^[a-zA-Z0-9_.:-]+$/', $id ) ) {
		return '';
	}
	return $id;
}

/**
 * Sanitize landing URL from attribution cookie.
 */
function marq_conversions_sanitize_landing_cookie( string $url ): string {
	$url = esc_url_raw( $url );
	if ( strlen( $url ) > 2048 ) {
		$url = substr( $url, 0, 2048 );
	}
	return $url;
}

/**
 * Keep only external traffic-source referrers (not same host as this site, not wp-admin).
 */
function marq_conversions_filter_external_landing_url( string $url ): string {
	$url = marq_conversions_sanitize_landing_cookie( $url );
	if ( $url === '' ) {
		return '';
	}

	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return '';
	}

	$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! is_string( $site_host ) || $site_host === '' ) {
		return $url;
	}

	if ( strtolower( $parts['host'] ) === strtolower( $site_host ) ) {
		return '';
	}

	$path = isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '';
	if ( strpos( $path, 'wp-admin' ) !== false || strpos( $path, 'wp-login.php' ) !== false ) {
		return '';
	}

	return $url;
}

/**
 * Allow document.referrer as http_referer fallback; drop wp-admin / login URLs.
 */
function marq_conversions_sanitize_client_document_referrer( string $url ): string {
	$url = esc_url_raw( $url );
	if ( $url === '' ) {
		return '';
	}
	$parts = wp_parse_url( $url );
	$path  = isset( $parts['path'] ) ? strtolower( $parts['path'] ) : '';
	if ( strpos( $path, 'wp-admin' ) !== false || strpos( $path, 'wp-login.php' ) !== false ) {
		return '';
	}
	return $url;
}

/**
 * Sanitize UTM value from cookie.
 */
function marq_conversions_sanitize_utm_cookie( string $value ): string {
	$value = sanitize_text_field( $value );
	if ( strlen( $value ) > 255 ) {
		$value = substr( $value, 0, 255 );
	}
	return $value;
}

/**
 * Read optional tracking cookie (handles raw or wp_unslash).
 *
 * @param string $name Cookie name.
 */
function marq_conversions_get_cookie_string( string $name ): string {
	if ( empty( $_COOKIE[ $name ] ) || ! is_string( $_COOKIE[ $name ] ) ) {
		return '';
	}
	return wp_unslash( $_COOKIE[ $name ] );
}

/**
 * Tracking fields included on every webhook payload (session, first-touch cookies, HTTP referer).
 * Frontend conversion $extra can override the same keys.
 *
 * @return array{session_id: string, http_referer: string, landing_referrer: string, utm_source: string, utm_medium: string, utm_campaign: string}
 */
function marq_conversions_request_tracking(): array {
	$raw_sid = marq_conversions_get_cookie_string( MARQ_CONVERSIONS_COOKIE_SESSION );
	$sid     = $raw_sid !== '' ? marq_conversions_sanitize_session_id( $raw_sid ) : '';

	$http_ref = '';
	if ( ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$http_ref = esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) );
	}

	$raw_landing = marq_conversions_get_cookie_string( MARQ_CONVERSIONS_COOKIE_LANDING );
	$landing     = $raw_landing !== '' ? marq_conversions_filter_external_landing_url( $raw_landing ) : '';

	$utm_source   = marq_conversions_get_cookie_string( MARQ_CONVERSIONS_COOKIE_UTM_SOURCE );
	$utm_medium   = marq_conversions_get_cookie_string( MARQ_CONVERSIONS_COOKIE_UTM_MEDIUM );
	$utm_campaign = marq_conversions_get_cookie_string( MARQ_CONVERSIONS_COOKIE_UTM_CAMPAIGN );

	return array(
		'session_id'         => $sid,
		'http_referer'       => $http_ref,
		'landing_referrer'   => $landing,
		'utm_source'         => $utm_source !== '' ? marq_conversions_sanitize_utm_cookie( $utm_source ) : '',
		'utm_medium'         => $utm_medium !== '' ? marq_conversions_sanitize_utm_cookie( $utm_medium ) : '',
		'utm_campaign'       => $utm_campaign !== '' ? marq_conversions_sanitize_utm_cookie( $utm_campaign ) : '',
	);
}

/**
 * Whether a POST key is our hidden attribution field (omit from logged post_data).
 */
function marq_conversions_is_internal_post_tracking_key( string $key ): bool {
	static $known = null;
	if ( null === $known ) {
		$known = array(
			MARQ_CV_POST_SID,
			MARQ_CV_POST_LANDING,
			MARQ_CV_POST_UTM_SOURCE,
			MARQ_CV_POST_UTM_MEDIUM,
			MARQ_CV_POST_UTM_CAMPAIGN,
			MARQ_CV_POST_DOC_REF,
		);
	}
	if ( in_array( $key, $known, true ) ) {
		return true;
	}
	return strpos( $key, 'marq_cv_post_' ) === 0;
}

/**
 * Overlay tracking from hidden fields submitted with the request (e.g. CF7 fetch without cookies).
 *
 * Non-empty POST values win per field; http_referer is filled from document referrer only when the server sent none.
 *
 * @param array<string, string> $base From marq_conversions_request_tracking().
 * @param array<string, mixed>|null $post Source fields (defaults to $_POST).
 * @return array<string, string>
 */
function marq_conversions_merge_post_tracking_into( array $base, ?array $post = null ): array {
	if ( null === $post ) {
		$post = $_POST;
	}
	if ( empty( $post ) || ! is_array( $post ) ) {
		return $base;
	}

	$raw = static function ( string $post_key ) use ( $post ): string {
		if ( ! isset( $post[ $post_key ] ) || ! is_string( $post[ $post_key ] ) ) {
			return '';
		}
		return wp_unslash( $post[ $post_key ] );
	};

	$sid = marq_conversions_sanitize_session_id( $raw( MARQ_CV_POST_SID ) );
	if ( $sid !== '' ) {
		$base['session_id'] = $sid;
	}

	$land_in = $raw( MARQ_CV_POST_LANDING );
	if ( $land_in !== '' ) {
		$landing = marq_conversions_filter_external_landing_url( marq_conversions_sanitize_landing_cookie( $land_in ) );
		if ( $landing !== '' ) {
			$base['landing_referrer'] = $landing;
		}
	}

	$utm_map = array(
		MARQ_CV_POST_UTM_SOURCE   => 'utm_source',
		MARQ_CV_POST_UTM_MEDIUM   => 'utm_medium',
		MARQ_CV_POST_UTM_CAMPAIGN => 'utm_campaign',
	);
	foreach ( $utm_map as $post_key => $field ) {
		$v = $raw( $post_key );
		if ( $v !== '' ) {
			$base[ $field ] = marq_conversions_sanitize_utm_cookie( $v );
		}
	}

	$doc = marq_conversions_sanitize_client_document_referrer( esc_url_raw( $raw( MARQ_CV_POST_DOC_REF ) ) );
	if ( $doc !== '' && ( $base['http_referer'] ?? '' ) === '' ) {
		$base['http_referer'] = $doc;
	}

	return $base;
}

/**
 * Return webhook URL from settings, filterable for overrides.
 */
function mq_hook_logger_webhook(): string {
	$saved = get_option( MARQ_CONVERSIONS_OPTION_WEBHOOK_URL, '' );
	$url   = is_string( $saved ) ? trim( $saved ) : '';
	$url   = apply_filters( 'mq_hook_logger_webhook', $url );
	return is_string( $url ) ? trim( $url ) : '';
}

/**
 * Fast pattern check for fields we should never forward raw.
 */
function mq_hook_logger_is_sensitive_key( string $key ): bool {
	return (bool) preg_match( '/pass|password|pwd|token|nonce|secret|authorization|cookie|key|credit|card|cvv|cvc|recaptcha/i', $key );
}

/**
 * Recursively sanitize payload data while stripping sensitive fields.
 *
 * @param mixed $value Value to sanitize.
 */
function mq_hook_logger_sanitize( $value, string $key = '' ) {
	if ( $key !== '' && mq_hook_logger_is_sensitive_key( $key ) ) {
		return null;
	}

	if ( is_array( $value ) ) {
		$clean = array();

		foreach ( $value as $sub_key => $sub_value ) {
			$normalized_key = is_string( $sub_key ) ? sanitize_key( $sub_key ) : (string) $sub_key;
			$sanitized      = mq_hook_logger_sanitize( $sub_value, $normalized_key );

			if ( $sanitized !== null ) {
				$clean[ $normalized_key ] = $sanitized;
			}
		}

		return $clean;
	}

	if ( is_bool( $value ) || is_numeric( $value ) ) {
		return $value;
	}

	if ( is_string( $value ) ) {
		return sanitize_text_field( wp_unslash( $value ) );
	}

	if ( $value === null ) {
		return null;
	}

	return '[unsupported]';
}

/**
 * Build a normalized event envelope.
 *
 * @param array<string, mixed>|null $post_tracking_source Merge marq_cv_post_* from this array; null uses $_POST.
 */
function mq_hook_logger_base_event( string $event, array $extra = array(), ?array $post_tracking_source = null ): array {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
	$uri    = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

	$post_for_action = $post_tracking_source ?? $_POST;
	$action          = '';
	if ( isset( $post_for_action['action'] ) && is_string( $post_for_action['action'] ) ) {
		$action = sanitize_text_field( wp_unslash( $post_for_action['action'] ) );
	}

	$payload = array(
		'ts'          => gmdate( 'c' ),
		'event'       => $event,
		'hook'        => current_filter(),
		'site_url'    => home_url(),
		'request_uri' => $uri,
		'method'      => $method,
		'action'      => $action,
		'is_admin'    => is_admin(),
		'is_ajax'     => function_exists( 'wp_doing_ajax' ) && wp_doing_ajax(),
	);

	$tracking = marq_conversions_request_tracking();
	$tracking = marq_conversions_merge_post_tracking_into( $tracking, $post_tracking_source );
	$payload  = array_merge( $payload, $tracking );

	if ( ! empty( $extra ) ) {
		$payload = array_merge( $payload, $extra );
	}

	return $payload;
}

/**
 * Send payload to webhook.
 */
function mq_hook_logger_send( array $payload ): void {
	$webhook = mq_hook_logger_webhook();

	if ( $webhook === '' ) {
		return;
	}

	$response = wp_safe_remote_post(
		$webhook,
		array(
			'timeout'     => MQ_HOOK_LOG_TIMEOUT,
			'blocking'    => false,
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'Marq Conversions (hook logger): ' . $response->get_error_message() );
	}
}

/**
 * Capture POST requests at strategic lifecycle points.
 *
 * @param array<string, mixed>|null $post_source Log this body (e.g. REST get_body_params()); null uses $_POST.
 */
function mq_hook_logger_capture_request( string $event, ?array $post_source = null ): void {
	static $sent = false;
	if ( $sent ) {
		return;
	}

	if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'POST' ) {
		return;
	}

	$raw_post = $post_source ?? $_POST;
	if ( empty( $raw_post ) || ! is_array( $raw_post ) ) {
		return;
	}

	$sent = true;

	$post_data = mq_hook_logger_sanitize( $raw_post );

	if ( ! is_array( $post_data ) ) {
		$post_data = array();
	}

	foreach ( array_keys( $post_data ) as $pk ) {
		if ( is_string( $pk ) && marq_conversions_is_internal_post_tracking_key( $pk ) ) {
			unset( $post_data[ $pk ] );
		}
	}

	mq_hook_logger_send(
		mq_hook_logger_base_event(
			$event,
			array(
				'post_keys' => array_keys( $post_data ),
				'post_data' => $post_data,
			),
			$raw_post
		)
	);
}

add_action(
	'init',
	static function (): void {
		mq_hook_logger_capture_request( 'request.post.init' );
	},
	1
);

add_filter(
	'rest_request_after_callbacks',
	static function ( $response, $handler, $request ) {
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		if ( $request->get_method() !== 'POST' ) {
			return $response;
		}
		$route = $request->get_route();
		if ( ! preg_match( '#/contact-form-7/v1/contact-forms/\d+/feedback$#', $route ) ) {
			return $response;
		}
		$params = $request->get_body_params();
		if ( ! is_array( $params ) || $params === array() ) {
			return $response;
		}
		mq_hook_logger_capture_request( 'request.post.cf7_feedback', $params );
		return $response;
	},
	10,
	3
);

add_filter(
	'pre_wp_mail',
	static function ( $return, $atts ) {
		$to      = $atts['to'] ?? null;
		$subject = isset( $atts['subject'] ) ? sanitize_text_field( (string) $atts['subject'] ) : null;

		mq_hook_logger_send(
			mq_hook_logger_base_event(
				'mail.pre_send',
				array(
					'mail_to' => is_array( $to ) ? array_map( 'sanitize_text_field', $to ) : ( is_string( $to ) ? sanitize_text_field( $to ) : null ),
					'subject' => $subject,
				)
			)
		);

		return $return;
	},
	10,
	2
);

add_action(
	'wp_mail_failed',
	static function ( $wp_error ): void {
		mq_hook_logger_send(
			mq_hook_logger_base_event(
				'mail.failed',
				array(
					'error' => is_object( $wp_error ) && method_exists( $wp_error, 'get_error_message' )
						? $wp_error->get_error_message()
						: 'unknown',
				)
			)
		);
	},
	10,
	1
);
