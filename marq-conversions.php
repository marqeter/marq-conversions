/**
 * Plugin Name: Marqeter Hook Logger
 * Description: Lightweight webhook logger for high-signal WordPress form and mail events.
 * Version: 0.2.0
 */

defined('ABSPATH') || exit;

const MQ_HOOK_LOG_WEBHOOK = 'https://marqeter.app.n8n.cloud/webhook-test/form-submission';
const MQ_HOOK_LOG_TIMEOUT = 0.5;

/**
 * Return webhook URL, filterable for env-based overrides.
 */
function mq_hook_logger_webhook(): string {
	$url = apply_filters('mq_hook_logger_webhook', MQ_HOOK_LOG_WEBHOOK);
	return is_string($url) ? trim($url) : '';
}

/**
 * Fast pattern check for fields we should never forward raw.
 */
function mq_hook_logger_is_sensitive_key(string $key): bool {
	return (bool) preg_match('/pass|password|pwd|token|nonce|secret|authorization|cookie|key|credit|card|cvv|cvc|recaptcha/i', $key);
}

/**
 * Recursively sanitize payload data while stripping sensitive fields.
 */
function mq_hook_logger_sanitize($value, string $key = '') {
	if ($key !== '' && mq_hook_logger_is_sensitive_key($key)) {
		return null;
	}

	if (is_array($value)) {
		$clean = [];

		foreach ($value as $sub_key => $sub_value) {
			$normalized_key = is_string($sub_key) ? sanitize_key($sub_key) : (string) $sub_key;
			$sanitized      = mq_hook_logger_sanitize($sub_value, $normalized_key);

			if ($sanitized !== null) {
				$clean[$normalized_key] = $sanitized;
			}
		}

		return $clean;
	}

	if (is_bool($value) || is_numeric($value)) {
		return $value;
	}

	if (is_string($value)) {
		return sanitize_text_field(wp_unslash($value));
	}

	if ($value === null) {
		return null;
	}

	return '[unsupported]';
}

/**
 * Build a normalized event envelope.
 */
function mq_hook_logger_base_event(string $event, array $extra = []): array {
	$method = isset($_SERVER['REQUEST_METHOD']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])) : '';
	$uri    = isset($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '';
	$action = isset($_POST['action']) ? sanitize_text_field(wp_unslash($_POST['action'])) : '';

	$payload = [
		'ts'          => gmdate('c'),
		'event'       => $event,
		'hook'        => current_filter(),
		'site_url'    => home_url(),
		'request_uri' => $uri,
		'method'      => $method,
		'action'      => $action,
		'is_admin'    => is_admin(),
		'is_ajax'     => function_exists('wp_doing_ajax') && wp_doing_ajax(),
	];

	if (!empty($extra)) {
		$payload = array_merge($payload, $extra);
	}

	return $payload;
}

/**
 * Send payload to webhook.
 */
function mq_hook_logger_send(array $payload): void {
	$webhook = mq_hook_logger_webhook();

	if ($webhook === '') {
		return;
	}

	$response = wp_safe_remote_post($webhook, [
		'timeout'   => MQ_HOOK_LOG_TIMEOUT,
		'blocking'  => false,
		'headers'   => ['Content-Type' => 'application/json'],
		'body'      => wp_json_encode($payload),
		'data_format' => 'body',
	]);

	if (is_wp_error($response)) {
		error_log('Marqeter Hook Logger: ' . $response->get_error_message());
	}
}

/**
 * Capture POST requests at strategic lifecycle points.
 */
function mq_hook_logger_capture_request(string $event): void {
	if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_POST) || !is_array($_POST)) {
		return;
	}

	$post_data = mq_hook_logger_sanitize($_POST);

	if (!is_array($post_data)) {
		$post_data = [];
	}

	mq_hook_logger_send(
		mq_hook_logger_base_event($event, [
			'post_keys' => array_keys($post_data),
			'post_data' => $post_data,
		])
	);
}

/**
 * Early POST visibility. Good for admin-ajax/admin-post/custom handlers.
 */
add_action('init', static function (): void {
	mq_hook_logger_capture_request('request.post.init');
}, 1);

/**
 * Frontend request visibility. Useful for non-AJAX frontend processing.
 */
add_action('template_redirect', static function (): void {
	mq_hook_logger_capture_request('request.post.template_redirect');
}, 1);

/**
 * Mail attempt visibility.
 */
add_filter('pre_wp_mail', static function ($return, $atts) {
	$to      = $atts['to'] ?? null;
	$subject = isset($atts['subject']) ? sanitize_text_field((string) $atts['subject']) : null;

	mq_hook_logger_send(
		mq_hook_logger_base_event('mail.pre_send', [
			'mail_to' => is_array($to) ? array_map('sanitize_text_field', $to) : (is_string($to) ? sanitize_text_field($to) : null),
			'subject' => $subject,
		])
	);

	return $return;
}, 10, 2);

/**
 * Mail failure visibility.
 */
add_action('wp_mail_failed', static function ($wp_error): void {
	mq_hook_logger_send(
		mq_hook_logger_base_event('mail.failed', [
			'error' => is_object($wp_error) && method_exists($wp_error, 'get_error_message')
				? $wp_error->get_error_message()
				: 'unknown',
		])
	);
}, 10, 1);
