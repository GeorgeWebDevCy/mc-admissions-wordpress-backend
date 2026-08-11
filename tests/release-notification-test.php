<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
}

final class WP_REST_Response {
	private $data;
	private $status;

	public function __construct($data, $status = 200) {
		$this->data = $data;
		$this->status = $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

final class WP_REST_Request {
	private $body;
	private $headers;

	public function __construct($body = '', $headers = array()) {
		$this->body = (string) $body;
		$this->headers = array();
		foreach ((array) $headers as $name => $value) {
			$this->headers[strtolower((string) $name)] = (string) $value;
		}
	}

	public function get_body() {
		return $this->body;
	}

	public function get_header($name) {
		return $this->headers[strtolower((string) $name)] ?? '';
	}

	public function get_json_params() {
		return json_decode($this->body, true);
	}
}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Release_Test_Role {
	public $name;

	public function __construct($name) {
		$this->name = $name;
	}

	public function has_cap($capability) {
		return true;
	}

	public function add_cap($capability) {
		return true;
	}
}

final class MC_Release_Test_Wpdb {
	public $settings = array();
	public $locks = array();
	public $force_lock_busy = false;
	public $fail_release_state_writes = false;

	public function prepare($query, ...$args) {
		return array(
			'query' => (string) $query,
			'args' => $args,
		);
	}

	public function get_var($prepared) {
		if (!is_array($prepared)) {
			return null;
		}

		$query = $prepared['query'];
		$args = $prepared['args'];
		if (false !== strpos($query, 'SELECT settingValue')) {
			return $this->settings[(string) ($args[0] ?? '')] ?? null;
		}
		if (false !== strpos($query, 'SELECT GET_LOCK')) {
			$name = (string) ($args[0] ?? '');
			if ($this->force_lock_busy || isset($this->locks[$name])) {
				return 0;
			}
			$this->locks[$name] = true;
			return 1;
		}
		if (false !== strpos($query, 'SELECT RELEASE_LOCK')) {
			unset($this->locks[(string) ($args[0] ?? '')]);
			return 1;
		}

		return null;
	}

	public function query($prepared) {
		if (
			is_array($prepared)
			&& false !== strpos($prepared['query'], 'INSERT INTO')
			&& false !== strpos($prepared['query'], 'mc_admission_settings')
		) {
			$key = (string) ($prepared['args'][0] ?? '');
			if (
				$this->fail_release_state_writes
				&& 0 === strpos($key, 'release_notification_delivery_')
			) {
				return false;
			}
			$this->settings[$key] = (string) ($prepared['args'][1] ?? '');
		}

		return 1;
	}
}

$GLOBALS['wpdb'] = new MC_Release_Test_Wpdb();
$GLOBALS['mc_release_roles'] = array();
$GLOBALS['mc_release_routes'] = array();
$GLOBALS['mc_release_mail_calls'] = array();
$GLOBALS['mc_release_mail_failures'] = array();
$GLOBALS['mc_release_mail_exceptions'] = array();
$GLOBALS['mc_release_network_calls'] = 0;
$GLOBALS['mc_release_users'] = array(
	(object) array(
		'ID' => 1,
		'display_name' => 'Administrator',
		'user_email' => 'admin@example.test',
		'roles' => array('administrator'),
	),
	(object) array(
		'ID' => 2,
		'display_name' => 'Admissions Officer',
		'user_email' => 'admissions@example.test',
		'roles' => array('admissions-officer'),
	),
	(object) array(
		'ID' => 3,
		'display_name' => 'Finance Officer',
		'user_email' => 'finance@example.test',
		'roles' => array('finance-officer'),
	),
	(object) array(
		'ID' => 4,
		'display_name' => 'Migration Officer',
		'user_email' => 'migration@example.test',
		'roles' => array('migration-officer'),
	),
	(object) array(
		'ID' => 5,
		'display_name' => 'Immigration Officer',
		'user_email' => 'immigration@example.test',
		'roles' => array('immigration-officer'),
	),
	(object) array(
		'ID' => 6,
		'display_name' => 'Registrar',
		'user_email' => 'registrar@example.test',
		'roles' => array('registrar'),
	),
	(object) array(
		'ID' => 7,
		'display_name' => 'External Agent',
		'user_email' => 'agent@example.test',
		'roles' => array('mc_agent'),
	),
	(object) array(
		'ID' => 8,
		'display_name' => 'Student',
		'user_email' => 'student@example.test',
		'roles' => array('subscriber'),
	),
);

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_release_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Release_Test_Role($label);
	$GLOBALS['mc_release_roles'][$slug] = $role;
	return $role;
}

function get_option($key, $fallback = false) {
	$versions = array(
		'mc_admissions_notification_activity_schema_version' => '1',
		'mc_admissions_resource_index_version' => '1',
		'mc_admissions_schema_version' => '0.2.14',
		'mc_admissions_offer_detail_schema_version' => '0.2.38',
		'mc_admissions_case_detail_schema_version' => '0.2.45',
		'mc_admissions_document_assessment_schema_version' => '1',
	);
	return array_key_exists($key, $versions) ? $versions[$key] : $fallback;
}

function update_option($key, $value, $autoload = null) {
	return true;
}

function add_filter(...$args) {
	return true;
}

function add_action(...$args) {
	return true;
}

function register_activation_hook(...$args) {
	return true;
}

function register_rest_route($namespace, $route, $args) {
	$GLOBALS['mc_release_routes'][$namespace . $route] = $args;
	return true;
}

function sanitize_email($email) {
	return trim((string) $email);
}

function is_email($email) {
	return false !== filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

function sanitize_key($key) {
	return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key));
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function wp_strip_all_tags($value, $remove_breaks = false) {
	return strip_tags((string) $value);
}

function esc_html($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function esc_url_raw($value) {
	return filter_var((string) $value, FILTER_VALIDATE_URL) ? (string) $value : '';
}

function wp_json_encode($value, $flags = 0, $depth = 512) {
	return json_encode($value, $flags, $depth);
}

function current_time($type, $gmt = false) {
	return '2026-07-30 18:00:00';
}

function get_users($args = array()) {
	$roles = isset($args['role__in']) ? (array) $args['role__in'] : array();
	return array_values(
		array_filter(
			$GLOBALS['mc_release_users'],
			static function ($user) use ($roles) {
				return count(array_intersect($roles, (array) $user->roles)) > 0;
			}
		)
	);
}

function get_userdata($user_id) {
	foreach ($GLOBALS['mc_release_users'] as $user) {
		if ((int) $user->ID === (int) $user_id) {
			return $user;
		}
	}
	return false;
}

function wp_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
	$email = strtolower((string) reset($to));
	$GLOBALS['mc_release_mail_calls'][] = array(
		'email' => $email,
		'subject' => (string) $subject,
		'message' => (string) $message,
		'headers' => (array) $headers,
	);
	if (in_array($email, $GLOBALS['mc_release_mail_exceptions'], true)) {
		throw new RuntimeException('Offline mail transport exception.');
	}
	return !in_array($email, $GLOBALS['mc_release_mail_failures'], true);
}

function wp_remote_get(...$args) {
	$GLOBALS['mc_release_network_calls']++;
	throw new RuntimeException('Offline tests must never contact the network.');
}

function wp_remote_post(...$args) {
	$GLOBALS['mc_release_network_calls']++;
	throw new RuntimeException('Offline tests must never contact the network.');
}

function wp_remote_request(...$args) {
	$GLOBALS['mc_release_network_calls']++;
	throw new RuntimeException('Offline tests must never contact the network.');
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function release_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function release_assert_true($actual, $message) {
	release_assert_same(true, (bool) $actual, $message);
}

function release_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function release_assert_not_contains($needle, $haystack, $message) {
	if (false !== strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function release_payload($tag = 'v0.5.42') {
	$version = substr($tag, 1);
	return array(
		'action' => 'published',
		'release' => array(
			'tag_name' => $tag,
			'name' => 'MC Admissions ' . $tag,
			'html_url' => 'https://github.com/GeorgeWebDevCy/mc-admissions-app/releases/tag/' . $tag,
			'draft' => false,
			'prerelease' => false,
			'published_at' => '2026-07-30T15:00:00Z',
			'assets' => array(
				array(
					'name' => 'mc-admissions-' . $version . '-win-x64.exe',
					'browser_download_url' => 'https://example.test/installer',
				),
				array(
					'name' => 'mc-admissions-' . $version . '-win-x64.exe.blockmap',
					'browser_download_url' => 'https://example.test/blockmap',
				),
				array(
					'name' => 'latest.yml',
					'browser_download_url' => 'https://example.test/latest',
				),
			),
		),
		'repository' => array(
			'full_name' => 'GeorgeWebDevCy/mc-admissions-app',
			'html_url' => 'https://github.com/GeorgeWebDevCy/mc-admissions-app',
		),
		'workflow_run' => array(
			'id' => 42001,
			'html_url' => 'https://github.com/GeorgeWebDevCy/mc-admissions-app/actions/runs/42001',
		),
	);
}

function release_request_from_raw($raw, $secret = 'offline-release-secret', $event = 'release', $delivery = '42001') {
	return new WP_REST_Request(
		$raw,
		array(
			'X-GitHub-Event' => $event,
			'X-GitHub-Delivery' => $delivery,
			'X-Hub-Signature-256' => 'sha256=' . hash_hmac('sha256', $raw, $secret),
		)
	);
}

function release_request($payload, $secret = 'offline-release-secret', $event = 'release', $delivery = '42001') {
	return release_request_from_raw(
		json_encode($payload, JSON_UNESCAPED_SLASHES),
		$secret,
		$event,
		$delivery
	);
}

function release_reset_mail() {
	$GLOBALS['mc_release_mail_calls'] = array();
	$GLOBALS['mc_release_mail_failures'] = array();
	$GLOBALS['mc_release_mail_exceptions'] = array();
}

function release_mail_addresses() {
	return array_column($GLOBALS['mc_release_mail_calls'], 'email');
}

function release_assert_response_privacy($response, $secret) {
	$encoded = json_encode($response->get_data());
	release_assert_not_contains($secret, $encoded, 'Responses must never expose the HMAC secret.');
	foreach ($GLOBALS['mc_release_users'] as $user) {
		release_assert_not_contains(
			(string) $user->user_email,
			$encoded,
			'Responses must never expose recipient email addresses.'
		);
	}
	release_assert_not_contains(
		'president@mesoyios.ac.cy',
		$encoded,
		'Responses must never expose the President email address.'
	);
}

$plugin = mc_admissions_wordpress_backend();
$plugin->register_rest_routes();
$route = $GLOBALS['mc_release_routes']['mc-admissions/v1/release-notification'] ?? null;
release_assert_true(is_array($route), 'The public release notification route must be registered.');
release_assert_same(WP_REST_Server::CREATABLE, $route['methods'], 'The release notification route must be POST-only.');
release_assert_same('__return_true', $route['permission_callback'], 'HMAC must be the route sole authentication mechanism.');
release_assert_same(array($plugin, 'rest_release_notification'), $route['callback'], 'The route must use the release notification handler.');

$GLOBALS['wpdb']->settings['release_notification_secret'] = 'offline-release-secret';
$valid_payload = release_payload();
$valid_response = $plugin->rest_release_notification(release_request($valid_payload));
release_assert_same(200, $valid_response->get_status(), 'A valid release must return HTTP 200.');
release_assert_same(
	array(
		'ok' => true,
		'duplicate' => false,
		'status' => 'sent',
		'tag' => 'v0.5.42',
		'sentCount' => 7,
		'failedCount' => 0,
	),
	$valid_response->get_data(),
	'A valid release must return a count-only delivery summary.'
);
release_assert_same(7, count($GLOBALS['mc_release_mail_calls']), 'Every internal role plus the President must receive one message.');
$valid_addresses = release_mail_addresses();
foreach (array(
	'president@mesoyios.ac.cy',
	'admin@example.test',
	'admissions@example.test',
	'finance@example.test',
	'migration@example.test',
	'immigration@example.test',
	'registrar@example.test',
) as $required_address) {
	release_assert_true(
		in_array($required_address, $valid_addresses, true),
		'Required internal release recipient is missing: ' . $required_address
	);
}
release_assert_same(false, in_array('agent@example.test', $valid_addresses, true), 'Agents must never receive desktop release notifications.');
release_assert_same(false, in_array('student@example.test', $valid_addresses, true), 'Students must never receive desktop release notifications.');
foreach ($GLOBALS['mc_release_mail_calls'] as $mail_call) {
	release_assert_same(
		'MC Admissions desktop update v0.5.42 is available',
		$mail_call['subject'],
		'The subject must carry the exact FluentSMTP audit marker.'
	);
	release_assert_contains(
		'MC Admissions desktop update v0.5.42 is available',
		$mail_call['message'],
		'The body must carry the exact FluentSMTP audit marker.'
	);
	release_assert_not_contains(
		'A new signed Windows desktop release is ready to install.',
		$mail_call['message'],
		'The body must not contain unnecessary release wording.'
	);
	release_assert_not_contains(
		'http',
		$mail_call['message'],
		'Desktop release notification emails must not contain links.'
	);
	release_assert_not_contains(
		'Release details',
		$mail_call['message'],
		'Desktop release notification emails must not include release-detail wording.'
	);
	release_assert_not_contains(
		'Release workflow',
		$mail_call['message'],
		'Desktop release notification emails must not include internal workflow wording.'
	);
}
release_assert_response_privacy($valid_response, 'offline-release-secret');
release_assert_same(0, $GLOBALS['mc_release_network_calls'], 'Release delivery must not contact any external network in offline tests.');

release_reset_mail();
$duplicate_response = $plugin->rest_release_notification(release_request($valid_payload));
release_assert_same(200, $duplicate_response->get_status(), 'A completed duplicate must return HTTP 200.');
release_assert_same(true, $duplicate_response->get_data()['ok'], 'A completed duplicate must be an idempotent success.');
release_assert_same(true, $duplicate_response->get_data()['duplicate'], 'A repeated tag must be marked duplicate.');
release_assert_same('duplicate', $duplicate_response->get_data()['status'], 'A repeated tag must expose duplicate status.');
release_assert_same('v0.5.42', $duplicate_response->get_data()['tag'], 'A duplicate response must identify the tag.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'A completed duplicate must never call wp_mail.');
release_assert_response_privacy($duplicate_response, 'offline-release-secret');

release_reset_mail();
$invalid_signature_request = release_request($valid_payload, 'wrong-secret');
$invalid_signature_response = $plugin->rest_release_notification($invalid_signature_request);
release_assert_same(401, $invalid_signature_response->get_status(), 'An invalid signature must return HTTP 401.');
release_assert_same('invalid_signature', $invalid_signature_response->get_data()['code'], 'An invalid signature must return a stable code.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'An invalid signature must never call wp_mail.');
release_assert_response_privacy($invalid_signature_response, 'offline-release-secret');

$invalid_cases = array();
$invalid_cases['event'] = array(release_payload('v0.5.50'), 'push', 'invalid_event');
$invalid_action = release_payload('v0.5.51');
$invalid_action['action'] = 'created';
$invalid_cases['action'] = array($invalid_action, 'release', 'invalid_action');
$invalid_repository = release_payload('v0.5.52');
$invalid_repository['repository']['full_name'] = 'OtherOwner/mc-admissions-app';
$invalid_cases['repository'] = array($invalid_repository, 'release', 'invalid_repository');
$invalid_tag = release_payload('v0.5.53');
$invalid_tag['release']['tag_name'] = 'release-0.5.53';
$invalid_cases['tag'] = array($invalid_tag, 'release', 'invalid_tag');
$invalid_draft = release_payload('v0.5.54');
$invalid_draft['release']['draft'] = true;
$invalid_cases['draft'] = array($invalid_draft, 'release', 'invalid_release_state');
$invalid_prerelease = release_payload('v0.5.55');
$invalid_prerelease['release']['prerelease'] = true;
$invalid_cases['prerelease'] = array($invalid_prerelease, 'release', 'invalid_release_state');
$invalid_assets = release_payload('v0.5.56');
array_splice($invalid_assets['release']['assets'], 1, 1);
$invalid_cases['assets'] = array($invalid_assets, 'release', 'missing_release_assets');

foreach ($invalid_cases as $label => $case) {
	release_reset_mail();
	$response = $plugin->rest_release_notification(release_request($case[0], 'offline-release-secret', $case[1]));
	release_assert_same(400, $response->get_status(), 'Invalid ' . $label . ' must return HTTP 400.');
	release_assert_same($case[2], $response->get_data()['code'], 'Invalid ' . $label . ' must return a stable code.');
	release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'Invalid ' . $label . ' must never call wp_mail.');
	release_assert_response_privacy($response, 'offline-release-secret');
}

release_reset_mail();
$invalid_json_raw = '{"action":';
$invalid_json_response = $plugin->rest_release_notification(
	release_request_from_raw($invalid_json_raw)
);
release_assert_same(400, $invalid_json_response->get_status(), 'Signed invalid JSON must return HTTP 400.');
release_assert_same('invalid_json', $invalid_json_response->get_data()['code'], 'Signed invalid JSON must return a stable code.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'Signed invalid JSON must never call wp_mail.');

release_reset_mail();
$partial_payload = release_payload('v0.5.43');
$GLOBALS['mc_release_mail_failures'] = array('finance@example.test');
$GLOBALS['mc_release_mail_exceptions'] = array('immigration@example.test');
$partial_response = $plugin->rest_release_notification(
	release_request($partial_payload, 'offline-release-secret', 'release', '42002')
);
release_assert_same(502, $partial_response->get_status(), 'Partial delivery must request a workflow retry.');
release_assert_same(false, $partial_response->get_data()['ok'], 'Partial delivery must not report full success.');
release_assert_same(false, $partial_response->get_data()['duplicate'], 'The first partial attempt is not a duplicate.');
release_assert_same('partial', $partial_response->get_data()['status'], 'Partial delivery must expose partial status.');
release_assert_same(5, $partial_response->get_data()['sentCount'], 'Partial delivery must count successful recipients.');
release_assert_same(2, $partial_response->get_data()['failedCount'], 'False and thrown mail failures must both be counted.');
release_assert_same(7, count($GLOBALS['mc_release_mail_calls']), 'One failing recipient must not stop later recipients.');
release_assert_response_privacy($partial_response, 'offline-release-secret');

release_reset_mail();
$partial_retry_response = $plugin->rest_release_notification(
	release_request($partial_payload, 'offline-release-secret', 'release', '42003')
);
release_assert_same(200, $partial_retry_response->get_status(), 'A successful partial retry must return HTTP 200.');
release_assert_same('sent', $partial_retry_response->get_data()['status'], 'A successful partial retry must complete delivery.');
release_assert_same(2, $partial_retry_response->get_data()['sentCount'], 'A partial retry must send only the two prior failures.');
release_assert_same(0, $partial_retry_response->get_data()['failedCount'], 'A successful partial retry must have no failures.');
$retry_addresses = release_mail_addresses();
sort($retry_addresses);
release_assert_same(
	array('finance@example.test', 'immigration@example.test'),
	$retry_addresses,
	'Successful recipients must never receive duplicate mail on a partial retry.'
);

release_reset_mail();
$completed_partial_duplicate = $plugin->rest_release_notification(release_request($partial_payload));
release_assert_same(true, $completed_partial_duplicate->get_data()['duplicate'], 'A completed partial retry must suppress later duplicates.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'A completed partial retry duplicate must not call wp_mail.');

release_reset_mail();
$failure_payload = release_payload('v0.5.44');
$GLOBALS['mc_release_mail_failures'] = array(
	'president@mesoyios.ac.cy',
	'admin@example.test',
	'admissions@example.test',
	'finance@example.test',
	'migration@example.test',
	'immigration@example.test',
	'registrar@example.test',
);
$failure_response = $plugin->rest_release_notification(release_request($failure_payload));
release_assert_same(502, $failure_response->get_status(), 'Total mail failure must request a workflow retry.');
release_assert_same('failed', $failure_response->get_data()['status'], 'Total mail failure must expose failed status.');
release_assert_same(0, $failure_response->get_data()['sentCount'], 'Total failure must report zero sends.');
release_assert_same(7, $failure_response->get_data()['failedCount'], 'Total failure must count every recipient.');
release_assert_response_privacy($failure_response, 'offline-release-secret');

release_reset_mail();
$GLOBALS['wpdb']->force_lock_busy = true;
$busy_response = $plugin->rest_release_notification(release_request(release_payload('v0.5.45')));
$GLOBALS['wpdb']->force_lock_busy = false;
release_assert_same(409, $busy_response->get_status(), 'A concurrent tag delivery must return HTTP 409.');
release_assert_same('release_notification_in_progress', $busy_response->get_data()['code'], 'A concurrent delivery must return a stable code.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'A concurrent delivery must never call wp_mail.');

release_reset_mail();
$GLOBALS['wpdb']->fail_release_state_writes = true;
$storage_response = $plugin->rest_release_notification(release_request(release_payload('v0.5.46')));
$GLOBALS['wpdb']->fail_release_state_writes = false;
release_assert_same(503, $storage_response->get_status(), 'Unavailable idempotency storage must fail closed.');
release_assert_same('idempotency_storage_failed', $storage_response->get_data()['code'], 'Storage failure must return a stable code.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'No mail may be sent before idempotency state is durable.');

$saved_secret = $GLOBALS['wpdb']->settings['release_notification_secret'];
unset($GLOBALS['wpdb']->settings['release_notification_secret']);
release_reset_mail();
$unconfigured_response = $plugin->rest_release_notification(release_request(release_payload('v0.5.47')));
$GLOBALS['wpdb']->settings['release_notification_secret'] = $saved_secret;
release_assert_same(503, $unconfigured_response->get_status(), 'A missing secret must fail closed.');
release_assert_same('release_notification_not_configured', $unconfigured_response->get_data()['code'], 'A missing secret must return a stable code.');
release_assert_same(array(), $GLOBALS['mc_release_mail_calls'], 'A missing secret must never call wp_mail.');

$reflection = new ReflectionClass($plugin);
$release_method = $reflection->getMethod('rest_release_notification');
$lines = file($release_method->getFileName());
$release_source = implode(
	'',
	array_slice(
		$lines,
		$release_method->getStartLine() - 1,
		$release_method->getEndLine() - $release_method->getStartLine() + 1
	)
);
release_assert_contains('$request->get_body()', $release_source, 'Signature verification must use the exact raw request body.');
release_assert_contains("get_header('x-hub-signature-256')", $release_source, 'The endpoint must read the GitHub HMAC header.');
release_assert_contains("hash_hmac('sha256', \$raw_body, \$secret)", $release_source, 'The endpoint must calculate HMAC-SHA256.');
release_assert_contains('hash_equals($expected_signature, $signature)', $release_source, 'The endpoint must use constant-time signature comparison.');
release_assert_not_contains('current_session_user', $release_source, 'The release endpoint must not require a WordPress session.');
release_assert_not_contains('wp_remote_', $release_source, 'The release endpoint must not contact GitHub or any external service.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
release_assert_contains('Version: 0.2.55', $plugin_source, 'The plugin header must advertise version 0.2.55.');
release_assert_contains('name="release_notification_secret"', $plugin_source, 'Settings must expose the release notification password field.');
release_assert_contains('type="password"', $plugin_source, 'The release notification setting must use a password input.');
release_assert_same(0, $GLOBALS['mc_release_network_calls'], 'The complete offline suite must never contact the network.');

echo 'Desktop release notification tests passed.' . PHP_EOL;
