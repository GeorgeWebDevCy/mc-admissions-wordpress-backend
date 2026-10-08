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

	public function __construct($data = null, $status = 200) {
		$this->data = $data;
		$this->status = $status;
	}

	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
}

final class WP_REST_Request implements ArrayAccess {
	private $route_params;
	private $json_params;

	public function __construct(array $route_params = array(), array $json_params = array()) {
		$this->route_params = $route_params;
		$this->json_params = $json_params;
	}

	public function get_json_params() { return $this->json_params; }
	public function offsetExists($offset) { return array_key_exists($offset, $this->route_params); }
	public function offsetGet($offset) { return $this->route_params[$offset] ?? null; }
	public function offsetSet($offset, $value) { $this->route_params[$offset] = $value; }
	public function offsetUnset($offset) { unset($this->route_params[$offset]); }
}

final class WP_Error {
	private $code;
	private $message;
	private $data;

	public function __construct($code = '', $message = '', $data = null) {
		$this->code = $code;
		$this->message = $message;
		$this->data = $data;
	}

	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}

final class MC_Agent_Status_Test_Role {
	public function has_cap($capability) { return true; }
	public function add_cap($capability) { return true; }
}

final class MC_Agent_Status_Test_Wpdb {
	public $profiles = array();

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) $args = array_values($args[0]);
		return array('query' => (string) $query, 'args' => array_values($args));
	}

	private function unpack($prepared) {
		return is_array($prepared) ? $prepared : array('query' => (string) $prepared, 'args' => array());
	}

	public function get_var($prepared) {
		$call = $this->unpack($prepared);
		if (false !== strpos($call['query'], 'SHOW TABLES LIKE')) return (string) ($call['args'][0] ?? '');
		return null;
	}

	public function get_row($prepared, $output = null) {
		$call = $this->unpack($prepared);
		if (false === strpos($call['query'], 'FROM mc_agency_profiles')) return null;
		$user_id = (int) ($call['args'][0] ?? 0);
		return $this->profiles[$user_id] ?? null;
	}

	public function get_results($prepared, $output = null) {
		$call = $this->unpack($prepared);
		if (false === strpos($call['query'], 'FROM mc_agency_profiles')) return array();
		return array_values($this->profiles);
	}

	public function query($prepared) { return 1; }
}

final class WP_Session_Tokens {
	private $user_id;

	private function __construct($user_id) { $this->user_id = (int) $user_id; }
	public static function get_instance($user_id) { return new self($user_id); }
	public function destroy_all() { $GLOBALS['mc_agent_status_destroyed_sessions'][] = $this->user_id; }
}

$GLOBALS['wpdb'] = new MC_Agent_Status_Test_Wpdb();
$GLOBALS['mc_agent_status_roles'] = array();
$GLOBALS['mc_agent_status_routes'] = array();
$GLOBALS['mc_agent_status_filters'] = array();
$GLOBALS['mc_agent_status_actions'] = array();
$GLOBALS['mc_agent_status_meta'] = array(
	3 => array(
		'mc_admissions_agent_active' => '0',
		'mc_admissions_agent_deactivated_at' => '2026-10-07 08:00:00',
		'mc_admissions_auth_epoch' => 4,
	),
	10 => array(
		'mc_admissions_agent_active' => '0',
		'mc_admissions_auth_epoch' => 2,
	),
);
$GLOBALS['mc_agent_status_destroyed_sessions'] = array();
$GLOBALS['mc_agent_status_failed_meta_writes'] = array();
$GLOBALS['mc_agent_status_current_user_id'] = 1;
$GLOBALS['mc_agent_status_logged_in'] = true;
$GLOBALS['mc_agent_status_users'] = array(
	1 => (object) array('ID' => 1, 'user_login' => 'principal', 'display_name' => 'Principal', 'user_email' => 'principal@example.test', 'roles' => array('administrator'), 'allcaps' => array()),
	2 => (object) array('ID' => 2, 'user_login' => 'agency-active', 'display_name' => 'Active Agency', 'user_email' => 'active@example.test', 'roles' => array('mc_agent'), 'allcaps' => array()),
	3 => (object) array('ID' => 3, 'user_login' => 'agency-inactive', 'display_name' => 'Inactive Agency', 'user_email' => 'inactive@example.test', 'roles' => array('mc_agent'), 'allcaps' => array()),
	4 => (object) array('ID' => 4, 'user_login' => 'dual-role', 'display_name' => 'Internal Dual Role', 'user_email' => 'dual@example.test', 'roles' => array('mc_agent', 'admissions-officer'), 'allcaps' => array()),
	5 => (object) array('ID' => 5, 'user_login' => 'MC-ADMISSIONS-DPT', 'display_name' => 'MC Admissions Department', 'user_email' => 'department@example.test', 'roles' => array('subscriber'), 'allcaps' => array()),
	6 => (object) array('ID' => 6, 'user_login' => 'finance', 'display_name' => 'Finance', 'user_email' => 'finance@example.test', 'roles' => array('finance-officer'), 'allcaps' => array()),
	7 => (object) array('ID' => 7, 'user_login' => 'agency-write-test', 'display_name' => 'Write Test Agency', 'user_email' => 'write-test@example.test', 'roles' => array('mc_agent'), 'allcaps' => array()),
	8 => (object) array('ID' => 8, 'user_login' => 'ordinary-subscriber', 'display_name' => 'Ordinary Subscriber', 'user_email' => 'subscriber@example.test', 'roles' => array('subscriber'), 'allcaps' => array()),
	9 => (object) array('ID' => 9, 'user_login' => 'legacy-agency', 'display_name' => 'Legacy Agency', 'user_email' => 'legacy@example.test', 'roles' => array('subscriber'), 'allcaps' => array()),
	10 => (object) array('ID' => 10, 'user_login' => 'legacy-status-agent', 'display_name' => 'Legacy Status Agent', 'user_email' => 'legacy-status@example.test', 'roles' => array('subscriber'), 'allcaps' => array()),
);
$GLOBALS['wpdb']->profiles = array(
	2 => array('id' => 'profile-2', 'wordpressUserId' => 2, 'consultantName' => 'Agent Two', 'consultantPhone' => '222', 'agreementOnFile' => 1, 'authorizationOnFile' => 1),
	3 => array('id' => 'profile-3', 'wordpressUserId' => 3, 'consultantName' => 'Agent Three', 'consultantPhone' => '333', 'agreementOnFile' => 0, 'authorizationOnFile' => 0),
	9 => array('id' => 'profile-9', 'wordpressUserId' => 9, 'consultantName' => 'Legacy Agent', 'consultantPhone' => '999', 'agreementOnFile' => 1, 'authorizationOnFile' => 1),
);

function __($text, $domain = null) { return $text; }
function get_role($slug) { return $GLOBALS['mc_agent_status_roles'][$slug] ?? null; }
function add_role($slug, $label, $capabilities = array()) { return $GLOBALS['mc_agent_status_roles'][$slug] = new MC_Agent_Status_Test_Role(); }
function get_option($key, $fallback = false) {
	$versions = array(
		'mc_admissions_application_test_data_schema_version' => '1',
		'mc_admissions_notification_activity_schema_version' => '1',
		'mc_admissions_resource_index_version' => '1',
		'mc_admissions_schema_version' => '0.2.14',
		'mc_admissions_migration_case_schema_version' => '0.2.64',
		'mc_admissions_offer_detail_schema_version' => '0.2.38',
		'mc_admissions_case_detail_schema_version' => '0.2.45',
		'mc_admissions_document_assessment_schema_version' => '1',
		'mc_admissions_finance_workspace_schema_version' => '0.2.61',
		'mc_admissions_intake_capacity_schema_version' => '0.2.62',
	);
	return array_key_exists($key, $versions) ? $versions[$key] : $fallback;
}
function update_option($key, $value, $autoload = null) { return true; }
function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_agent_status_filters'][$hook] = compact('callback', 'priority', 'accepted_args');
	return true;
}
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_agent_status_actions'][$hook][] = compact('callback', 'priority', 'accepted_args');
	return true;
}
function register_activation_hook(...$args) { return true; }
function register_rest_route($namespace, $route, $args = array(), $override = false) {
	$GLOBALS['mc_agent_status_routes'][$namespace . $route] = $args;
	return true;
}
function is_user_logged_in() { return (bool) $GLOBALS['mc_agent_status_logged_in']; }
function wp_get_current_user() { return get_userdata($GLOBALS['mc_agent_status_current_user_id']); }
function get_current_user_id() { return (int) $GLOBALS['mc_agent_status_current_user_id']; }
function get_userdata($user_id) { return $GLOBALS['mc_agent_status_users'][(int) $user_id] ?? false; }
function get_user_by($field, $value) {
	foreach ($GLOBALS['mc_agent_status_users'] as $user) {
		if ('login' === (string) $field && strtolower((string) $user->user_login) === strtolower((string) $value)) return $user;
	}
	return false;
}
function get_users($args = array()) {
	$users = $GLOBALS['mc_agent_status_users'];
	if (!empty($args['role__in'])) {
		$roles = array_map('strval', (array) $args['role__in']);
		$users = array_filter($users, static function ($user) use ($roles) {
			return count(array_intersect($roles, (array) $user->roles)) > 0;
		});
	}
	usort($users, static function ($left, $right) { return strcmp((string) $left->display_name, (string) $right->display_name); });
	return array_values($users);
}
function get_user_meta($user_id, $key, $single = false) { return $GLOBALS['mc_agent_status_meta'][(int) $user_id][$key] ?? ''; }
function update_user_meta($user_id, $key, $value) {
	$failure_key = (int) $user_id . ':' . (string) $key;
	if (!empty($GLOBALS['mc_agent_status_failed_meta_writes'][$failure_key])) return false;
	$GLOBALS['mc_agent_status_meta'][(int) $user_id][$key] = $value;
	return true;
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_list_pluck($items, $field) { return array_map(static function ($item) use ($field) { return $item->{$field}; }, $items); }
function absint($value) { return abs((int) $value); }
function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
function get_avatar_url($user_id, $args = array()) { return ''; }
function current_time($type, $gmt = false) { return '2026-10-08 09:15:00'; }

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function agent_status_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}

function agent_status_ids($agents) {
	$ids = array_values(array_map(static function ($agent) { return (int) $agent['id']; }, $agents));
	sort($ids);
	return $ids;
}

function agent_status_assert_throws_contains($needle, callable $callback, $message) {
	try {
		$callback();
	} catch (Throwable $error) {
		if (false !== stripos($error->getMessage(), $needle)) return;
		throw new RuntimeException($message . ' Wrong exception: ' . $error->getMessage());
	}
	throw new RuntimeException($message . ' No exception was thrown.');
}

function agent_status_token($user_id, $epoch) {
	$payload = array('data' => array('user' => array('id' => (int) $user_id)));
	if (null !== $epoch) $payload[MC_Admissions_WordPress_Backend::AUTH_EPOCH_CLAIM] = $epoch;
	$encode = static function ($value) {
		return rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
	};
	return $encode(array('alg' => 'none')) . '.' . $encode($payload) . '.signature';
}

$plugin = mc_admissions_wordpress_backend();
$plugin->register_rest_routes();

$manage_route = MC_Admissions_WordPress_Backend::API_NAMESPACE . '/agents/manage';
$status_route = MC_Admissions_WordPress_Backend::API_NAMESPACE . '/agents/(?P<agent_id>[0-9]+)/status';
agent_status_assert_same('rest_manage_agents', $GLOBALS['mc_agent_status_routes'][$manage_route]['callback'][1] ?? null, 'The administrator management route must be registered.');
agent_status_assert_same('PATCH', $GLOBALS['mc_agent_status_routes'][$status_route]['methods'] ?? null, 'The agent status route must be PATCH-only.');
agent_status_assert_same(100, $GLOBALS['mc_agent_status_filters']['authenticate']['priority'] ?? null, 'Deactivated login blocking must run after WordPress resolves credentials.');
agent_status_assert_same(3, $GLOBALS['mc_agent_status_filters']['authenticate']['accepted_args'] ?? null, 'The authentication guard must accept the full WordPress filter signature.');

$owner_list = $plugin->rest_list_agents();
agent_status_assert_same(200, $owner_list->get_status(), 'Administrators must be able to load the normal owner selector.');
agent_status_assert_same(array(2, 5, 7, 9), agent_status_ids($owner_list->get_data()['agents']), 'The normal selector must exclude inactive, internal dual-role, and unrelated subscriber accounts while preserving real legacy agents and the special Foundation owner.');

$management = $plugin->rest_manage_agents();
agent_status_assert_same(200, $management->get_status(), 'Administrators must be able to list managed agency accounts.');
agent_status_assert_same(array(2, 3, 7, 9, 10), agent_status_ids($management->get_data()['agents']), 'Management must include active and inactive external agents only, including legacy subscribers with admissions identity or durable agent-status metadata.');
$managed_by_id = array();
foreach ($management->get_data()['agents'] as $agent) $managed_by_id[(int) $agent['id']] = $agent;
agent_status_assert_same(true, $managed_by_id[2]['active'], 'Missing status meta must remain backward-compatible as active.');
agent_status_assert_same(null, $managed_by_id[2]['deactivatedAt'], 'An active agent must not expose a deactivation timestamp.');
agent_status_assert_same(false, $managed_by_id[3]['active'], 'Explicit zero status meta must mark an agent inactive.');
agent_status_assert_same('2026-10-07 08:00:00', $managed_by_id[3]['deactivatedAt'], 'Inactive summaries may safely expose their deactivation timestamp.');
agent_status_assert_same(false, $managed_by_id[10]['active'], 'Explicit inactive metadata must fail closed for a legacy subscriber even without a readable profile or owned application.');

$GLOBALS['mc_agent_status_current_user_id'] = 10;
$legacy_status_rest_block = $plugin->permission_authenticated();
agent_status_assert_same(true, $legacy_status_rest_block instanceof WP_Error, 'A deactivated legacy subscriber identified by durable status metadata must be blocked from REST access.');
agent_status_assert_same('mc_admissions_agent_deactivated', $legacy_status_rest_block->get_error_code(), 'Legacy subscriber blocking must use the stable deactivation code.');
$GLOBALS['mc_agent_status_current_user_id'] = 1;

$GLOBALS['mc_agent_status_current_user_id'] = 6;
agent_status_assert_same(403, $plugin->rest_manage_agents()->get_status(), 'Non-administrators must not access agent management.');
agent_status_assert_same(403, $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 2), array('active' => false)))->get_status(), 'Non-administrators must not mutate agent status.');
$GLOBALS['mc_agent_status_current_user_id'] = 1;

agent_status_assert_same(400, $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 2), array('active' => 'false')))->get_status(), 'Agent status must require a JSON boolean.');
agent_status_assert_same(400, $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 4), array('active' => false)))->get_status(), 'Internal dual-role users must not be mutable as agents.');
agent_status_assert_same(400, $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 5), array('active' => false)))->get_status(), 'The internal Foundation owner must not be mutable through external-agent management.');
agent_status_assert_same(400, $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 8), array('active' => false)))->get_status(), 'An unrelated generic subscriber must not be mutable through external-agent management.');

$resolve_application_owner = new ReflectionMethod(MC_Admissions_WordPress_Backend::class, 'resolve_application_owner');
$resolve_application_owner->setAccessible(true);
$administrator = array('id' => 1, 'username' => 'principal', 'name' => 'Principal', 'email' => 'principal@example.test', 'roles' => array('administrator'));
agent_status_assert_throws_contains('valid agent', static function () use ($resolve_application_owner, $plugin, $administrator) {
	$resolve_application_owner->invoke($plugin, $administrator, 8, 'business-administration');
}, 'An unrelated generic subscriber must not be assignable as an application owner.');
$legacy_owner = $resolve_application_owner->invoke($plugin, $administrator, 9, 'business-administration');
agent_status_assert_same(9, $legacy_owner['id'], 'A legacy subscriber with an admissions profile must remain assignable for backward compatibility.');

$deactivated = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 2), array('active' => false)));
agent_status_assert_same(200, $deactivated->get_status(), 'An administrator must be able to deactivate an external agent.');
agent_status_assert_same(false, $deactivated->get_data()['agent']['active'], 'The mutation response must return the authoritative inactive state.');
agent_status_assert_same('2026-10-08 09:15:00', $deactivated->get_data()['agent']['deactivatedAt'], 'Deactivation must record a safe UTC timestamp.');
agent_status_assert_same('0', $GLOBALS['mc_agent_status_meta'][2][MC_Admissions_WordPress_Backend::AGENT_ACTIVE_META_KEY], 'Deactivation must persist the explicit inactive flag.');
agent_status_assert_same(1, $GLOBALS['mc_agent_status_meta'][2][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'Deactivation must advance the JWT epoch immediately.');
agent_status_assert_same(1, $GLOBALS['mc_agent_status_meta'][2][MC_Admissions_WordPress_Backend::AGENT_DEACTIVATED_BY_META_KEY], 'Deactivation must record the administrator user ID.');
agent_status_assert_same(array(2), $GLOBALS['mc_agent_status_destroyed_sessions'], 'Deactivation must destroy existing WordPress sessions as defense in depth.');
agent_status_assert_same(array(5, 7, 9), agent_status_ids($plugin->rest_list_agents()->get_data()['agents']), 'A deactivated agency must disappear immediately from normal owner selection.');

$login_block = $plugin->block_deactivated_agent_authentication(get_userdata(2), 'agency-active', 'secret');
agent_status_assert_same(true, $login_block instanceof WP_Error, 'A deactivated agent must be blocked from password/JWT authentication.');
agent_status_assert_same('mc_admissions_agent_deactivated', $login_block->get_error_code(), 'The login block must use a stable deactivation code.');
$GLOBALS['mc_agent_status_current_user_id'] = 2;
$rest_block = $plugin->permission_authenticated();
agent_status_assert_same(true, $rest_block instanceof WP_Error, 'A deactivated cookie session must be blocked from admissions REST routes.');
agent_status_assert_same('mc_admissions_agent_deactivated', $rest_block->get_error_code(), 'REST blocking must report deactivation explicitly.');
$generic_rest_block = $plugin->surface_jwt_auth_epoch_error(null);
agent_status_assert_same(true, $generic_rest_block instanceof WP_Error, 'A deactivated resolved identity must be blocked from generic WordPress REST routes, including Application Password sessions.');
agent_status_assert_same('mc_admissions_agent_deactivated', $generic_rest_block->get_error_code(), 'Generic REST blocking must use the stable deactivation code.');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . agent_status_token(2, 0);
agent_status_assert_same(false, $plugin->enforce_jwt_auth_epoch(2), 'A pre-deactivation JWT must be revoked immediately.');
$jwt_block = $plugin->surface_jwt_auth_epoch_error(null);
agent_status_assert_same(true, $jwt_block instanceof WP_Error, 'Revoked JWT use must surface an authentication error.');
unset($_SERVER['HTTP_AUTHORIZATION']);

$GLOBALS['mc_agent_status_current_user_id'] = 1;
$reactivated = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 2), array('active' => true)));
agent_status_assert_same(200, $reactivated->get_status(), 'An administrator must be able to reactivate an external agent.');
agent_status_assert_same(true, $reactivated->get_data()['agent']['active'], 'Reactivation must return the authoritative active state.');
agent_status_assert_same(null, $reactivated->get_data()['agent']['deactivatedAt'], 'An active summary must not present a stale deactivation timestamp.');
agent_status_assert_same(2, $GLOBALS['mc_agent_status_meta'][2][MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY], 'Reactivation must advance the epoch again so old tokens cannot revive.');
agent_status_assert_same(1, $GLOBALS['mc_agent_status_meta'][2][MC_Admissions_WordPress_Backend::AGENT_REACTIVATED_BY_META_KEY], 'Reactivation must record the administrator user ID.');
agent_status_assert_same(get_userdata(2), $plugin->block_deactivated_agent_authentication(get_userdata(2)), 'Reactivated agents must be allowed to authenticate again.');

$_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . agent_status_token(2, 1);
agent_status_assert_same(false, $plugin->enforce_jwt_auth_epoch(2), 'A token from before reactivation must remain revoked.');
unset($_SERVER['HTTP_AUTHORIZATION']);
$GLOBALS['mc_agent_status_current_user_id'] = 2;
agent_status_assert_same(true, $plugin->permission_authenticated(), 'A reactivated agent must regain authenticated REST access with a new session.');

// Critical status writes are read back before a successful response. Deactivation
// persists inactive first, while reactivation writes active last, so every partial
// failure remains fail closed and old credentials never become usable again.
$GLOBALS['mc_agent_status_current_user_id'] = 1;
$active_failure_key = '7:' . MC_Admissions_WordPress_Backend::AGENT_ACTIVE_META_KEY;
$epoch_failure_key = '7:' . MC_Admissions_WordPress_Backend::AUTH_EPOCH_META_KEY;
$GLOBALS['mc_agent_status_failed_meta_writes'][$active_failure_key] = true;
$failed_initial_deactivation = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => false)));
agent_status_assert_same(500, $failed_initial_deactivation->get_status(), 'A failed inactive-flag write must never report successful deactivation.');
agent_status_assert_same('', $GLOBALS['mc_agent_status_meta'][7][MC_Admissions_WordPress_Backend::AGENT_ACTIVE_META_KEY] ?? '', 'A failed initial deactivation write must not fabricate inactive state.');
unset($GLOBALS['mc_agent_status_failed_meta_writes'][$active_failure_key]);

$GLOBALS['mc_agent_status_failed_meta_writes'][$epoch_failure_key] = true;
$failed_epoch_deactivation = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => false)));
agent_status_assert_same(500, $failed_epoch_deactivation->get_status(), 'A failed revocation-epoch write must never report successful deactivation.');
agent_status_assert_same('0', $GLOBALS['mc_agent_status_meta'][7][MC_Admissions_WordPress_Backend::AGENT_ACTIVE_META_KEY], 'A partially failed deactivation must remain fail closed as inactive.');
unset($GLOBALS['mc_agent_status_failed_meta_writes'][$epoch_failure_key]);

$recovered_reactivation = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => true)));
agent_status_assert_same(200, $recovered_reactivation->get_status(), 'An administrator must be able to recover a fail-closed account once storage is healthy.');
$plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => false)));
$GLOBALS['mc_agent_status_failed_meta_writes'][$active_failure_key] = true;
$failed_active_reactivation = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => true)));
agent_status_assert_same(500, $failed_active_reactivation->get_status(), 'A failed final active-flag write must never report successful reactivation.');
agent_status_assert_same('0', $GLOBALS['mc_agent_status_meta'][7][MC_Admissions_WordPress_Backend::AGENT_ACTIVE_META_KEY], 'A partially failed reactivation must remain inactive.');
unset($GLOBALS['mc_agent_status_failed_meta_writes'][$active_failure_key]);
$final_reactivation = $plugin->rest_update_agent_status(new WP_REST_Request(array('agent_id' => 7), array('active' => true)));
agent_status_assert_same(200, $final_reactivation->get_status(), 'Reactivation must succeed after all critical writes can be verified.');

$source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
agent_status_assert_same(true, false !== strpos($source, 'Version: 0.2.73'), 'The plugin header must advertise version 0.2.73.');

echo "Agent status management tests passed.\n";
