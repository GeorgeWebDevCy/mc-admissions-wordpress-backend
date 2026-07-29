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
	private $json_params;

	public function __construct($json_params = null) {
		$this->json_params = $json_params;
	}

	public function get_json_params() {
		return $this->json_params;
	}
}

final class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct($code, $message, $data = null) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}

	public function get_error_data() {
		return $this->data;
	}
}

final class MC_Account_Test_Role {
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

final class MC_Account_Test_Wpdb {
	public function prepare($query, ...$args) {
		return $query;
	}

	public function get_var($query) {
		return null;
	}

	public function query($query) {
		return 1;
	}
}

final class WP_Session_Tokens {
	private $user_id;

	private function __construct($user_id) {
		$this->user_id = (int) $user_id;
	}

	public static function get_instance($user_id) {
		$GLOBALS['mc_account_session_instance_ids'][] = (int) $user_id;
		return new self($user_id);
	}

	public function destroy_all() {
		$GLOBALS['mc_account_destroyed_session_ids'][] = $this->user_id;
	}
}

$GLOBALS['wpdb'] = new MC_Account_Test_Wpdb();
$GLOBALS['mc_account_roles'] = array();
$GLOBALS['mc_account_routes'] = array();
$GLOBALS['mc_account_filters'] = array();
$GLOBALS['mc_account_actions'] = array();
$GLOBALS['mc_account_logged_in'] = true;
$GLOBALS['mc_account_current_user'] = (object) array(
	'ID' => 17,
	'user_login' => 'offline-user',
	'display_name' => 'Offline User',
	'user_email' => 'offline@example.test',
	'user_pass' => 'hash:CurrentPass123!',
	'roles' => array('administrator'),
);
$GLOBALS['mc_account_persisted_user_hashes'] = array(
	17 => 'hash:CurrentPass123!',
	18 => 'hash:SecondCurrent123!',
);
$GLOBALS['mc_account_user_meta'] = array();
$GLOBALS['mc_account_meta_update_calls'] = array();
$GLOBALS['mc_account_transients'] = array();
$GLOBALS['mc_account_password_checks'] = array();
$GLOBALS['mc_account_password_writes'] = array();
$GLOBALS['mc_account_session_instance_ids'] = array();
$GLOBALS['mc_account_destroyed_session_ids'] = array();
$GLOBALS['mc_account_clear_cookie_calls'] = 0;
$GLOBALS['mc_account_mail_calls'] = 0;
$GLOBALS['mc_account_password_email_calls'] = 0;
$GLOBALS['mc_account_update_user_calls'] = 0;
$GLOBALS['mc_account_throw_on_set'] = false;
$GLOBALS['mc_account_silent_password_write_failure'] = false;
$GLOBALS['mc_account_meta_update_result'] = true;

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_account_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Account_Test_Role($label);
	$GLOBALS['mc_account_roles'][$slug] = $role;
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

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_account_filters'][$hook] = array(
		'callback' => $callback,
		'priority' => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}


function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_account_actions'][$hook][] = array(
		'callback' => $callback,
		'priority' => $priority,
		'accepted_args' => $accepted_args,
	);
	return true;
}

function do_action($hook, ...$args) {
	foreach ($GLOBALS['mc_account_actions'][$hook] ?? array() as $registration) {
		call_user_func_array(
			$registration['callback'],
			array_slice($args, 0, $registration['accepted_args'])
		);
	}
}

function register_activation_hook(...$args) {
	return true;
}

function register_rest_route($namespace, $route, $args) {
	$GLOBALS['mc_account_routes'][$namespace . $route] = $args;
	return true;
}

function is_user_logged_in() {
	return (bool) $GLOBALS['mc_account_logged_in'];
}

function wp_get_current_user() {
	return $GLOBALS['mc_account_current_user'];
}

function get_userdata($user_id) {
	$user_id = (int) $user_id;
	if (!isset($GLOBALS['mc_account_persisted_user_hashes'][$user_id])) {
		return false;
	}

	return (object) array(
		'ID' => $user_id,
		'user_pass' => $GLOBALS['mc_account_persisted_user_hashes'][$user_id],
	);
}

function get_user_meta($user_id, $key, $single = false) {
	return $GLOBALS['mc_account_user_meta'][(int) $user_id][$key] ?? '';
}

function update_user_meta($user_id, $key, $value) {
	$GLOBALS['mc_account_meta_update_calls'][] = array(
		'user_id' => (int) $user_id,
		'key' => $key,
		'value' => $value,
	);

	if (false === $GLOBALS['mc_account_meta_update_result']) {
		return false;
	}

	$GLOBALS['mc_account_user_meta'][(int) $user_id][$key] = $value;
	return true;
}

function get_transient($key) {
	return $GLOBALS['mc_account_transients'][$key]['value'] ?? false;
}

function set_transient($key, $value, $expiration) {
	$GLOBALS['mc_account_transients'][$key] = array('value' => $value, 'expiration' => $expiration);
	return true;
}

function delete_transient($key) {
	unset($GLOBALS['mc_account_transients'][$key]);
	return true;
}

function wp_check_password($password, $hash, $user_id = '') {
	$GLOBALS['mc_account_password_checks'][] = array(
		'password' => $password,
		'hash' => $hash,
		'user_id' => (int) $user_id,
	);
	return hash_equals((string) $hash, 'hash:' . (string) $password);
}

function wp_set_password($password, $user_id) {
	if ($GLOBALS['mc_account_throw_on_set']) {
		throw new RuntimeException('Sensitive native update detail.');
	}

	$GLOBALS['mc_account_password_writes'][] = array(
		'password' => $password,
		'user_id' => (int) $user_id,
	);
	if (!$GLOBALS['mc_account_silent_password_write_failure']) {
		$GLOBALS['mc_account_persisted_user_hashes'][(int) $user_id] = 'hash:' . $password;
	}

	do_action(
		'wp_set_password',
		$password,
		(int) $user_id,
		(object) array('ID' => (int) $user_id, 'user_pass' => 'previous-hash')
	);
}

function wp_clear_auth_cookie() {
	$GLOBALS['mc_account_clear_cookie_calls']++;
}

function wp_mail(...$args) {
	$GLOBALS['mc_account_mail_calls']++;
	return true;
}

function wp_send_password_change_email(...$args) {
	$GLOBALS['mc_account_password_email_calls']++;
}

function wp_update_user($userdata) {
	$GLOBALS['mc_account_update_user_calls']++;
	return isset($userdata['ID']) ? (int) $userdata['ID'] : 0;
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function account_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function account_assert_contains($needle, $haystack, $message) {
	if (false === strpos($haystack, $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function account_assert_not_contains($needle, $haystack, $message) {
	if (false !== strpos($haystack, $needle)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function account_assert_response($response, $status, $ok, $message, $label) {
	account_assert_same($status, $response->get_status(), $label . ' status must be stable.');
	account_assert_same(
		array('ok' => $ok, 'message' => $message),
		$response->get_data(),
		$label . ' must return only ok and a safe message.'
	);
}

function account_reset_side_effects() {
	$GLOBALS['mc_account_password_checks'] = array();
	$GLOBALS['mc_account_password_writes'] = array();
	$GLOBALS['mc_account_session_instance_ids'] = array();
	$GLOBALS['mc_account_destroyed_session_ids'] = array();
	$GLOBALS['mc_account_clear_cookie_calls'] = 0;
	$GLOBALS['mc_account_mail_calls'] = 0;
	$GLOBALS['mc_account_password_email_calls'] = 0;
	$GLOBALS['mc_account_update_user_calls'] = 0;
	$GLOBALS['mc_account_meta_update_calls'] = array();
	$GLOBALS['mc_account_throw_on_set'] = false;
	$GLOBALS['mc_account_silent_password_write_failure'] = false;
	$GLOBALS['mc_account_meta_update_result'] = true;
}

function account_jwt($payload) {
	$segments = array(
		array('alg' => 'HS256', 'typ' => 'JWT'),
		$payload,
	);
	$encoded = array_map(function ($segment) {
		return rtrim(strtr(base64_encode(json_encode($segment)), '+/', '-_'), '=');
	}, $segments);

	return $encoded[0] . '.' . $encoded[1] . '.already-validated-signature';
}

function account_token_payload($user_id, $epoch_marker = null, $include_epoch = true) {
	$payload = array('data' => array('user' => array('id' => $user_id)));
	if ($include_epoch) {
		$payload[MC_Admissions_WordPress_Backend::AUTH_EPOCH_CLAIM] = $epoch_marker;
	}

	return $payload;
}

$plugin = mc_admissions_wordpress_backend();
$plugin->register_rest_routes();

$route_key = MC_Admissions_WordPress_Backend::API_NAMESPACE . '/account/password';
$password_route = $GLOBALS['mc_account_routes'][$route_key] ?? null;
account_assert_same(true, is_array($password_route), 'The password route must be registered.');
account_assert_same('PUT', $password_route['methods'] ?? null, 'The password route must accept PUT only.');
account_assert_same('rest_change_password', $password_route['callback'][1] ?? null, 'The password route must use the password callback.');
account_assert_same('permission_authenticated', $password_route['permission_callback'][1] ?? null, 'The password route must require authentication.');

account_assert_same(
	array('priority' => 10, 'accepted_args' => 2),
	array(
		'priority' => $GLOBALS['mc_account_filters']['jwt_auth_token_before_sign']['priority'],
		'accepted_args' => $GLOBALS['mc_account_filters']['jwt_auth_token_before_sign']['accepted_args'],
	),
	'JWT signing must include the current authentication epoch.'
);
account_assert_same(100, $GLOBALS['mc_account_filters']['determine_current_user']['priority'], 'Epoch validation must run after JWT user resolution.');
account_assert_same(20, $GLOBALS['mc_account_filters']['rest_authentication_errors']['priority'], 'Epoch errors must be surfaced through REST authentication.');

$password_action = $GLOBALS['mc_account_actions']['wp_set_password'][0] ?? null;
account_assert_same(true, is_array($password_action), 'Every WordPress password change must register the epoch hook.');
account_assert_same(10, $password_action['priority'], 'The password epoch hook must use the default action priority.');
account_assert_same(3, $password_action['accepted_args'], 'The password epoch hook must accept the modern three-argument signature.');

$GLOBALS['mc_account_user_meta'] = array();
account_reset_side_effects();
call_user_func($password_action['callback'], 'CompatibilityPass123!', 17);
account_assert_same(1, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'WordPress 6.2-6.6 two-argument actions must advance the epoch.');
account_assert_same(1, count($GLOBALS['mc_account_meta_update_calls']), 'A two-argument password action must increment exactly once.');

$GLOBALS['mc_account_user_meta'] = array();
account_reset_side_effects();
wp_set_password('ExternalWordPressPass123!', 17);
account_assert_same(1, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'A password changed outside the admissions endpoint must revoke old JWTs.');
account_assert_same(1, count($GLOBALS['mc_account_meta_update_calls']), 'An external WordPress password change must increment exactly once.');
account_assert_same(false, false !== strpos(json_encode($GLOBALS['mc_account_user_meta']), 'ExternalWordPressPass123!'), 'The epoch hook must never persist plaintext passwords.');
account_assert_same(0, $GLOBALS['mc_account_mail_calls'], 'The global epoch hook must never send email.');
$GLOBALS['mc_account_user_meta'] = array();
account_reset_side_effects();

$GLOBALS['mc_account_logged_in'] = false;
$permission_denied = call_user_func($password_route['permission_callback']);
account_assert_same(true, $permission_denied instanceof WP_Error, 'Anonymous password requests must be denied.');
account_assert_same('mc_admissions_not_authenticated', $permission_denied->get_error_code(), 'Anonymous requests must use the canonical code.');
account_assert_same(array('status' => 401), $permission_denied->get_error_data(), 'Anonymous requests must return HTTP 401.');
$GLOBALS['mc_account_logged_in'] = true;
account_assert_same(true, call_user_func($password_route['permission_callback']), 'Every authenticated role may change its own password.');

$base_payload = account_token_payload(17, null, false);
$signed_payload = $plugin->add_jwt_auth_epoch_claim($base_payload, $GLOBALS['mc_account_current_user']);
account_assert_same(0, $signed_payload[MC_Admissions_WordPress_Backend::AUTH_EPOCH_CLAIM], 'A user with no epoch meta must receive epoch zero.');
$signed_without_user_argument = $plugin->add_jwt_auth_epoch_claim($base_payload);
account_assert_same(0, $signed_without_user_argument[MC_Admissions_WordPress_Backend::AUTH_EPOCH_CLAIM], 'Older JWT plugin calls must resolve the user from token data.');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . account_jwt(account_token_payload(17, null, false));
account_assert_same(17, $plugin->enforce_jwt_auth_epoch(17), 'A legacy token must work while both claim and stored epoch normalize to zero.');
account_assert_same('sentinel', $plugin->surface_jwt_auth_epoch_error('sentinel'), 'A valid legacy token must not create an auth error.');

$GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY] = 1;
account_assert_same(false, $plugin->enforce_jwt_auth_epoch(17), 'A legacy token must be revoked once the user epoch increments.');
$old_token_error = $plugin->surface_jwt_auth_epoch_error(null);
account_assert_same(true, $old_token_error instanceof WP_Error, 'A revoked token must surface a REST authentication error.');
account_assert_same('mc_admissions_session_revoked', $old_token_error->get_error_code(), 'Revoked tokens must use the stable session code.');
account_assert_same(array('status' => 401), $old_token_error->get_error_data(), 'Revoked tokens must return HTTP 401.');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . account_jwt(account_token_payload(17, 1));
account_assert_same(17, $plugin->enforce_jwt_auth_epoch(17), 'A newly issued token with the current epoch must pass globally.');
account_assert_same('sentinel', $plugin->surface_jwt_auth_epoch_error('sentinel'), 'A current token must not create an auth error.');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . account_jwt(account_token_payload(99, 1));
account_assert_same(false, $plugin->enforce_jwt_auth_epoch(17), 'A token payload for another user must never authorize the resolved user.');

unset($_SERVER['HTTP_AUTHORIZATION']);
account_assert_same(17, $plugin->enforce_jwt_auth_epoch(17), 'Cookie authentication without a Bearer token must remain unchanged.');
account_assert_same('sentinel', $plugin->surface_jwt_auth_epoch_error('sentinel'), 'Cookie authentication must not create an epoch error.');

$GLOBALS['mc_account_user_meta'] = array();
account_reset_side_effects();
$missing = $plugin->rest_change_password(new WP_REST_Request(array('currentPassword' => 'CurrentPass123!')));
account_assert_response($missing, 400, false, 'Current password, new password, and password confirmation are required.', 'Missing fields');

$oversized = str_repeat('x', 4097);
$too_large = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => $oversized,
	'confirmPassword' => $oversized,
)));
account_assert_response($too_large, 400, false, 'Password fields must not exceed 4096 characters.', 'Oversized input');
account_assert_same(array(), $GLOBALS['mc_account_password_checks'], 'Oversized input must be rejected before password verification.');

$mismatch = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass789!',
)));
account_assert_response($mismatch, 400, false, 'New password and confirmation do not match.', 'Confirmation mismatch');

$short = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'Short123!',
	'confirmPassword' => 'Short123!',
)));
account_assert_response($short, 400, false, 'New password must contain at least 12 characters.', 'Short password');

$GLOBALS['mc_account_transients'] = array();
for ($attempt = 1; $attempt <= 4; $attempt++) {
	$incorrect = $plugin->rest_change_password(new WP_REST_Request(array(
		'currentPassword' => 'IncorrectPass123!',
		'newPassword' => 'DifferentPass456!',
		'confirmPassword' => 'DifferentPass456!',
	)));
	account_assert_response($incorrect, 400, false, 'Current password is incorrect.', 'Incorrect current password attempt ' . $attempt);
}
account_assert_same(
	array('value' => 4, 'expiration' => 900),
	$GLOBALS['mc_account_transients']['mc_admissions_password_attempts_17'],
	'Failed attempts must use a 15-minute user-scoped WordPress transient.'
);

$fifth_incorrect = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'IncorrectPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($fifth_incorrect, 429, false, 'Too many unsuccessful password attempts. Please try again later.', 'Fifth failed attempt');

$checks_before_blocked_request = count($GLOBALS['mc_account_password_checks']);
$blocked_correct_password = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($blocked_correct_password, 429, false, 'Too many unsuccessful password attempts. Please try again later.', 'Throttled request');
account_assert_same($checks_before_blocked_request, count($GLOBALS['mc_account_password_checks']), 'A throttled request must not perform password hashing work.');
account_assert_same(array(), $GLOBALS['mc_account_password_writes'], 'Throttled requests must not change a password.');

$GLOBALS['mc_account_current_user'] = (object) array(
	'ID' => 18,
	'user_login' => 'second-user',
	'display_name' => 'Second User',
	'user_email' => 'second@example.test',
	'user_pass' => 'hash:SecondCurrent123!',
	'roles' => array('mc_agent'),
);
$other_user_incorrect = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'IncorrectPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($other_user_incorrect, 400, false, 'Current password is incorrect.', 'Other user first attempt');
account_assert_same(1, $GLOBALS['mc_account_transients']['mc_admissions_password_attempts_18']['value'], 'One user throttle must not block another user.');

$GLOBALS['mc_account_current_user'] = (object) array(
	'ID' => 17,
	'user_login' => 'offline-user',
	'display_name' => 'Offline User',
	'user_email' => 'offline@example.test',
	'user_pass' => 'hash:CurrentPass123!',
	'roles' => array('administrator'),
);
$GLOBALS['mc_account_transients'] = array();
account_reset_side_effects();
$same = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'CurrentPass123!',
	'confirmPassword' => 'CurrentPass123!',
)));
account_assert_response($same, 400, false, 'New password must be different from the current password.', 'Unchanged password');
account_assert_same(array(), $GLOBALS['mc_account_password_writes'], 'Reusing the current password must not update WordPress.');

account_reset_side_effects();
$GLOBALS['mc_account_meta_update_result'] = false;
$meta_failure = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($meta_failure, 500, false, 'Password could not be changed. Please try again.', 'Epoch update failure');
account_assert_same(array(), $GLOBALS['mc_account_password_writes'], 'A revocation persistence failure must stop before the native password call.');
account_assert_same(array(), $GLOBALS['mc_account_destroyed_session_ids'], 'A revocation persistence failure must leave the existing password session untouched.');

$GLOBALS['mc_account_user_meta'] = array();
account_reset_side_effects();
$GLOBALS['mc_account_throw_on_set'] = true;
$native_failure = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($native_failure, 500, false, 'Password could not be changed. Please try again.', 'Native update failure');
account_assert_not_contains('Sensitive', json_encode($native_failure->get_data()), 'Native error details must never be exposed.');
account_assert_same(1, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'A native password failure must leave old JWTs revoked.');
account_assert_same(1, count($GLOBALS['mc_account_meta_update_calls']), 'A native failure must retain exactly one fail-closed epoch increment.');

account_reset_side_effects();
wp_set_password('ExternalAfterFailure123!', 17);
account_assert_same(2, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'The endpoint must clear its guard after a native failure so later external changes still revoke JWTs.');
account_assert_same(1, count($GLOBALS['mc_account_meta_update_calls']), 'The external change after failure must increment exactly once.');

$GLOBALS['mc_account_user_meta'] = array();
$GLOBALS['mc_account_persisted_user_hashes'][17] = 'hash:CurrentPass123!';
$GLOBALS['mc_account_transients'] = array();
account_reset_side_effects();
$GLOBALS['mc_account_silent_password_write_failure'] = true;
$silent_write_failure = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => 'DifferentPass456!',
	'confirmPassword' => 'DifferentPass456!',
)));
account_assert_response($silent_write_failure, 500, false, 'Password could not be changed. Please try again.', 'Silent native write failure');
account_assert_same(1, count($GLOBALS['mc_account_password_writes']), 'The silent failure simulation must reach the void native password API.');
account_assert_same('hash:CurrentPass123!', $GLOBALS['mc_account_persisted_user_hashes'][17], 'A silent native write failure must leave the stored password hash unchanged.');
account_assert_same(1, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'A silent password write failure must keep old JWTs revoked.');
account_assert_same(array(), $GLOBALS['mc_account_destroyed_session_ids'], 'A silent password write failure must not destroy sessions as if the change succeeded.');
account_assert_same(0, $GLOBALS['mc_account_clear_cookie_calls'], 'A silent password write failure must not clear authentication as if the change succeeded.');
account_assert_same(0, $GLOBALS['mc_account_mail_calls'], 'A silent password write failure must never send email.');

$GLOBALS['mc_account_user_meta'] = array();
$GLOBALS['mc_account_persisted_user_hashes'][17] = 'hash:CurrentPass123!';
$GLOBALS['mc_account_transients'] = array(
	'mc_admissions_password_attempts_17' => array('value' => 3, 'expiration' => 900),
);
account_reset_side_effects();
$success = $plugin->rest_change_password(new WP_REST_Request(array(
	'currentPassword' => 'CurrentPass123!',
	'newPassword' => ' DifferentPass456! ',
	'confirmPassword' => ' DifferentPass456! ',
	'userId' => 999,
	'wordpressUserId' => 999,
	'username' => 'another-user',
)));
account_assert_response($success, 200, true, 'Password changed successfully.', 'Successful update');
account_assert_same(
	array(array('password' => ' DifferentPass456! ', 'user_id' => 17)),
	$GLOBALS['mc_account_password_writes'],
	'The native API must preserve raw password spaces and target only the authenticated user.'
);
account_assert_same('hash: DifferentPass456! ', $GLOBALS['mc_account_persisted_user_hashes'][17], 'Success must require the persisted hash to match the raw new password.');
account_assert_same(1, $GLOBALS['mc_account_user_meta'][17][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'A successful password change must increment the JWT epoch.');
account_assert_same(1, count($GLOBALS['mc_account_meta_update_calls']), 'The endpoint password change must increment the epoch exactly once.');
account_assert_same(array(17), $GLOBALS['mc_account_session_instance_ids'], 'Session invalidation must target only the authenticated user.');
account_assert_same(array(17), $GLOBALS['mc_account_destroyed_session_ids'], 'All WordPress sessions for the changed account must be destroyed.');
account_assert_same(1, $GLOBALS['mc_account_clear_cookie_calls'], 'The current WordPress authentication cookie must be cleared.');
account_assert_same(false, isset($GLOBALS['mc_account_transients']['mc_admissions_password_attempts_17']), 'A successful change must clear failed attempts.');
account_assert_same(0, $GLOBALS['mc_account_mail_calls'], 'Changing a password must never call wp_mail.');
account_assert_same(0, $GLOBALS['mc_account_password_email_calls'], 'Changing a password must never send WordPress password-change email.');
account_assert_same(0, $GLOBALS['mc_account_update_user_calls'], 'The endpoint must avoid wp_update_user, which can trigger notification email.');

$reflection = new ReflectionClass($plugin);
$password_method = $reflection->getMethod('rest_change_password');
$source_lines = file($password_method->getFileName());
$password_source = implode('', array_slice(
	$source_lines,
	$password_method->getStartLine() - 1,
	$password_method->getEndLine() - $password_method->getStartLine() + 1
));
account_assert_contains('wp_set_password($new_password, $user_id)', $password_source, 'The native WordPress password API must perform the update.');
account_assert_contains('$updated_user = get_userdata($user_id)', $password_source, 'The endpoint must reload uncached user data after the void native write.');
account_assert_contains('wp_check_password($new_password, $updated_hash, $user_id)', $password_source, 'The endpoint must verify the persisted hash before success.');
account_assert_same(
	true,
	strpos($password_source, 'wp_set_password($new_password, $user_id)') < strpos($password_source, '$updated_user = get_userdata($user_id)')
		&& strpos($password_source, '$updated_user = get_userdata($user_id)') < strpos($password_source, 'WP_Session_Tokens::get_instance($user_id)->destroy_all()'),
	'Password verification must occur after the write and before session destruction.'
);
account_assert_contains('update_user_meta($user_id, self::AUTH_EPOCH_META_KEY, $next_epoch)', $password_source, 'The endpoint must persist revocation before changing the password.');
account_assert_contains('$this->password_epoch_preadvanced_user_ids[$user_id] = true', $password_source, 'The endpoint must guard its pre-advanced epoch against a hook double increment.');
account_assert_contains('finally', $password_source, 'The endpoint must always clear its per-user epoch guard.');
account_assert_not_contains('wp_update_user', $password_source, 'The endpoint must not use the email-triggering user update path.');
account_assert_not_contains('wp_mail', $password_source, 'The endpoint must contain no mail path.');
account_assert_not_contains('sanitize_', $password_source, 'Password values must not be sanitized.');
account_assert_not_contains('trim(', $password_source, 'Password values must not be trimmed.');
account_assert_not_contains('userId', $password_source, 'The endpoint must not accept a target user ID.');

$epoch_method = $reflection->getMethod('advance_auth_epoch_after_password_change');
$epoch_source = implode('', array_slice(
	$source_lines,
	$epoch_method->getStartLine() - 1,
	$epoch_method->getEndLine() - $epoch_method->getStartLine() + 1
));
account_assert_contains('update_user_meta($user_id, self::AUTH_EPOCH_META_KEY', $epoch_source, 'The global password hook must persist the revocation epoch.');
account_assert_same(1, substr_count($epoch_source, '$_password'), 'The plaintext hook argument must appear only in the required method signature.');
account_assert_not_contains('wp_mail', $epoch_source, 'The global password hook must never send email.');
account_assert_not_contains('error_log', $epoch_source, 'The global password hook must never log plaintext.');
account_assert_not_contains('update_option', $epoch_source, 'The global password hook must store only the numeric per-user epoch.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
account_assert_contains('Version: 0.2.47', $plugin_source, 'The plugin header must advertise version 0.2.47.');
account_assert_contains('GET, POST, PUT, PATCH, OPTIONS', $plugin_source, 'CORS must permit the password route PUT request.');

echo 'Account password tests passed.' . PHP_EOL;
