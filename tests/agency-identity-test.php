<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');
define('MINUTE_IN_SECONDS', 60);

final class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
}

final class WP_REST_Response {
	public $data;
	public $status;

	public function __construct($data = null, $status = 200) {
		$this->data = $data;
		$this->status = $status;
	}
}

final class WP_REST_Request {
	private $params;

	public function __construct($params = array()) {
		$this->params = $params;
	}

	public function get_json_params() {
		return $this->params;
	}
}

final class WP_Error {
	private $message;

	public function __construct($code = '', $message = '', $data = null) {
		$this->message = $message;
	}

	public function get_error_message() {
		return $this->message;
	}
}

final class MC_Agency_Identity_Role {
	public function has_cap($capability) { return true; }
	public function add_cap($capability) { return true; }
}

final class MC_Agency_Identity_Wpdb {
	public $prefix = 'wp_';
	public $users = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $profiles = array();
	public $applications = array();
	public $updates = array();
	public $insert_calls = array();
	public $queries = array();

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) $args = $args[0];
		return array('query' => (string) $query, 'args' => $args);
	}

	public function esc_like($value) { return addcslashes((string) $value, '_%\\'); }

	private function unpack($prepared) {
		return is_array($prepared) ? $prepared : array('query' => (string) $prepared, 'args' => array());
	}

	public function get_var($prepared) {
		$call = $this->unpack($prepared);
		if (false !== strpos($call['query'], 'SHOW TABLES LIKE')) {
			return (string) ($call['args'][0] ?? '');
		}
		if (false !== strpos($call['query'], 'SELECT id FROM mc_agency_profiles')) {
			$user_id = (int) ($call['args'][0] ?? 0);
			return isset($this->profiles[$user_id]) ? $this->profiles[$user_id]['id'] : null;
		}
		if (false !== strpos($call['query'], 'SELECT agencyName FROM mc_admission_applications')) {
			$user_id = (int) ($call['args'][0] ?? 0);
			$matches = array_values(array_filter($this->applications, function ($application) use ($user_id) {
				return (int) ($application['wordpressUserId'] ?? 0) === $user_id
					&& '' !== trim((string) ($application['agencyName'] ?? ''));
			}));
			usort($matches, function ($left, $right) { return strcmp((string) ($right['updatedAt'] ?? ''), (string) ($left['updatedAt'] ?? '')); });
			return $matches ? $matches[0]['agencyName'] : null;
		}
		return null;
	}

	public function get_row($prepared, $output = null) {
		$call = $this->unpack($prepared);
		if (false !== strpos($call['query'], 'FROM mc_agency_profiles')) {
			if (false !== strpos($call['query'], 'WHERE id =')) {
				$profile_id = (string) ($call['args'][0] ?? '');
				foreach ($this->profiles as $profile) {
					if ((string) $profile['id'] === $profile_id) return $profile;
				}
				return null;
			}
			$user_id = (int) ($call['args'][0] ?? 0);
			return $this->profiles[$user_id] ?? null;
		}
		return null;
	}

	public function get_results($query, $output = null) {
		if (false !== strpos((string) $query, 'FROM mc_agency_profiles')) {
			return array_values($this->profiles);
		}
		return array();
	}

	public function update($table, $data, $where) {
		if (!empty($GLOBALS['mc_identity_fail_table_write']) && $GLOBALS['mc_identity_fail_table_write'] === $table) return false;
		$this->updates[] = array('table' => $table, 'data' => $data, 'where' => $where);
		$user_id = (int) ($where['wordpressUserId'] ?? 0);
		if ('mc_agency_profiles' === $table && isset($this->profiles[$user_id])) {
			$this->profiles[$user_id] = array_merge($this->profiles[$user_id], $data);
		}
		if ('mc_admission_applications' === $table) {
			foreach ($this->applications as $id => $application) {
				if ((int) $application['wordpressUserId'] === $user_id) {
					$this->applications[$id] = array_merge($application, $data);
				}
			}
		}
		return 1;
	}

	public function insert($table, $data) { if (!empty($GLOBALS['mc_identity_fail_table_write']) && $GLOBALS['mc_identity_fail_table_write'] === $table) return false; $this->insert_calls[] = array('table' => $table, 'data' => $data); return 1; }
	public function query($prepared) {
		$call = $this->unpack($prepared);
		$this->queries[] = $call;
		if (false !== strpos($call['query'], 'UPDATE mc_admission_applications SET')) {
			if ('mc_admission_applications' === ($GLOBALS['mc_identity_fail_table_write'] ?? null)) return false;
			$args = $call['args'];
			$user_id = (int) array_pop($args);
			$data = array(
				'wordpressUsername' => $args[0] ?? null,
				'wordpressEmail' => $args[1] ?? null,
				'agencyName' => $args[2] ?? '',
				'consultantEmail' => $args[3] ?? null,
			);
			if (false !== strpos($call['query'], 'consultantName = %s')) {
				$data['consultantName'] = $args[4] ?? '';
				$data['consultantPhone'] = $args[5] ?? null;
			}
			$written = 0;
			foreach ($this->applications as $id => $application) {
				if ((int) ($application['wordpressUserId'] ?? 0) === $user_id) {
					$this->applications[$id] = array_merge($application, $data);
					$written++;
				}
			}
			return $written;
		}
		return 1;
	}
	public function get_col($prepared) {
		$call = $this->unpack($prepared);
		if (false !== strpos($call['query'], 'FROM wp_users')) {
			$cursor = (int) ($call['args'][1] ?? 0);
			$limit = (int) end($call['args']);
			$ids = array_keys(array_filter($GLOBALS['mc_identity_users'], function ($user) use ($cursor) {
				return (int) $user->ID > $cursor && in_array('mc_agent', (array) $user->roles, true);
			}));
			sort($ids, SORT_NUMERIC);
			$ids = array_slice($ids, 0, $limit);
			$GLOBALS['mc_identity_agent_batches'][] = $ids;
			return $ids;
		}
		if (false !== strpos($call['query'], 'SELECT owner.wordpressUserId')) {
			$cursor = (int) ($call['args'][0] ?? 0);
			$limit = (int) ($call['args'][2] ?? 200);
			$ids = array_unique(array_merge(
				array_map('intval', array_keys($this->profiles)),
				array_map(function ($application) { return (int) ($application['wordpressUserId'] ?? 0); }, $this->applications)
			));
			$ids = array_values(array_filter($ids, function ($user_id) use ($cursor) { return $user_id > $cursor; }));
			sort($ids, SORT_NUMERIC);
			$ids = array_slice($ids, 0, $limit);
			$GLOBALS['mc_identity_owner_batches'][] = $ids;
			return $ids;
		}
		return array();
	}
}

$GLOBALS['wpdb'] = new MC_Agency_Identity_Wpdb();
$GLOBALS['mc_identity_roles'] = array();
$GLOBALS['mc_identity_current_user_id'] = 10;
$GLOBALS['mc_identity_pluggable_ready'] = false;
$GLOBALS['mc_identity_early_user_calls'] = 0;
$GLOBALS['mc_identity_actions'] = array();
$GLOBALS['mc_identity_scheduled_events'] = array();
$GLOBALS['mc_identity_wp_update_calls'] = array();
$GLOBALS['mc_identity_wp_update_fail_ids'] = array();
$GLOBALS['mc_identity_fail_table_write'] = null;
$GLOBALS['mc_identity_agent_batches'] = array();
$GLOBALS['mc_identity_owner_batches'] = array();
$GLOBALS['mc_identity_deleted_user_ids'] = array();
$GLOBALS['mc_identity_delete_user_result'] = true;
$GLOBALS['mc_identity_next_user_id'] = 30;
$GLOBALS['mc_identity_options'] = array(
	'mc_admissions_notification_activity_schema_version' => '1',
	'mc_admissions_application_test_data_schema_version' => '1',
	'mc_admissions_resource_index_version' => '1',
	'mc_admissions_schema_version' => '0.2.14',
	'mc_admissions_offer_detail_schema_version' => '0.2.38',
	'mc_admissions_case_detail_schema_version' => '0.2.45',
	'mc_admissions_document_assessment_schema_version' => '1',
	// Deliberately incomplete before the plugin file is required. The original
	// synchronous migration fatally called get_userdata() under this state.
	'mc_admissions_agency_identity_version' => '0.2.58',
);
$GLOBALS['mc_identity_transients'] = array();
$GLOBALS['mc_identity_users'] = array(
	10 => (object) array('ID' => 10, 'user_login' => '12th-Study-Abroad', 'display_name' => '12th-Study-Abroad', 'user_email' => 'owner@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	11 => (object) array('ID' => 11, 'user_login' => 'Atlas_Bridge', 'display_name' => '', 'user_email' => 'atlas@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	12 => (object) array('ID' => 12, 'user_login' => 'machineagent', 'display_name' => 'Intentional Agency Ltd', 'user_email' => 'custom@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	13 => (object) array('ID' => 13, 'user_login' => 'staff-account', 'display_name' => 'staff-account', 'user_email' => 'staff@example.invalid', 'roles' => array('administrator'), 'allcaps' => array()),
	14 => (object) array('ID' => 14, 'user_login' => 'incomplete-agent', 'display_name' => 'Incomplete Agency', 'user_email' => 'incomplete@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	15 => (object) array('ID' => 15, 'user_login' => '', 'display_name' => '', 'user_email' => 'nameless@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	16 => (object) array('ID' => 16, 'user_login' => 'invalid-email-agent', 'display_name' => 'Invalid Email Agency', 'user_email' => 'not-an-email', 'roles' => array('mc_agent'), 'allcaps' => array()),
	17 => (object) array('ID' => 17, 'user_login' => 'profileowner', 'display_name' => 'Old Custom Name', 'user_email' => 'profile-owner@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	18 => (object) array('ID' => 18, 'user_login' => 'applicationowner', 'display_name' => 'Another Custom Name', 'user_email' => 'application-owner@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	19 => (object) array('ID' => 19, 'user_login' => 'Fallback_Agency', 'display_name' => 'Fallback_Agency', 'user_email' => 'fallback@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	20 => (object) array('ID' => 20, 'user_login' => 'Retry_Agency', 'display_name' => 'Retry_Agency', 'user_email' => 'retry@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	21 => (object) array('ID' => 21, 'user_login' => 'html-agency', 'display_name' => '<b>Agency & Co</b>', 'user_email' => 'html@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	22 => (object) array('ID' => 22, 'user_login' => 'Whitespace_Agency', 'display_name' => 'Whitespace Agency', 'user_email' => 'whitespace@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
	23 => (object) array('ID' => 23, 'user_login' => 'admissions-staff', 'display_name' => 'Admissions Staff', 'user_email' => 'admissions@example.invalid', 'roles' => array('admissions-officer'), 'allcaps' => array()),
	24 => (object) array('ID' => 24, 'user_login' => 'finance-staff', 'display_name' => 'Finance Staff', 'user_email' => 'finance@example.invalid', 'roles' => array('finance-officer'), 'allcaps' => array()),
	25 => (object) array('ID' => 25, 'user_login' => 'dual-role-staff', 'display_name' => 'Dual Role Staff', 'user_email' => 'dual-role@example.invalid', 'roles' => array('mc_agent', 'admissions-officer'), 'allcaps' => array()),
	54 => (object) array('ID' => 54, 'user_login' => 'OnePoint-Education', 'display_name' => 'Kashif', 'user_email' => 'onepoint@example.invalid', 'roles' => array('mc_agent'), 'allcaps' => array()),
);
$GLOBALS['wpdb']->profiles[10] = array(
	'id' => 'profile-10', 'wordpressUserId' => 10, 'wordpressUsername' => 'stale-user',
	'wordpressEmail' => 'stale@example.invalid', 'agencyName' => '12th-Study-Abroad',
	'consultantName' => 'Profile Consultant', 'consultantEmail' => 'spoof@example.invalid',
	'consultantPhone' => '+357 99112233', 'defaultApplicationRoute' => 'standard',
	'agreementOnFile' => 0, 'authorizationOnFile' => 0, 'notes' => null,
	'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->profiles[13] = array(
	'id' => 'profile-13', 'wordpressUserId' => 13, 'wordpressUsername' => 'staff-account',
	'wordpressEmail' => 'staff@example.invalid', 'agencyName' => 'staff-account',
	'consultantName' => 'Staff Contact', 'consultantEmail' => 'staff@example.invalid',
	'consultantPhone' => '+357 25000000', 'defaultApplicationRoute' => 'standard',
	'agreementOnFile' => 0, 'authorizationOnFile' => 0, 'notes' => null,
	'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->profiles[23] = array(
	'id' => 'profile-23', 'wordpressUserId' => 23, 'wordpressUsername' => 'admissions-staff',
	'wordpressEmail' => 'admissions@example.invalid', 'agencyName' => 'Admissions Staff',
	'consultantName' => 'Admissions Contact', 'consultantEmail' => 'admissions@example.invalid',
	'consultantPhone' => '+357 25000001', 'defaultApplicationRoute' => 'standard',
	'agreementOnFile' => 0, 'authorizationOnFile' => 0, 'notes' => null,
	'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->applications['case-10'] = array(
	'id' => 'case-10', 'wordpressUserId' => 10, 'wordpressUsername' => 'stale-user',
	'wordpressEmail' => 'stale@example.invalid', 'agencyName' => 'Spoofed Agency',
	'consultantName' => 'Application Spoof', 'consultantEmail' => 'spoof@example.invalid',
	'consultantPhone' => '000', 'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->profiles[14] = array(
	'id' => 'profile-14', 'wordpressUserId' => 14, 'wordpressUsername' => 'incomplete-agent',
	'wordpressEmail' => 'incomplete@example.invalid', 'agencyName' => 'Incomplete Agency',
	'consultantName' => '', 'consultantEmail' => 'incomplete@example.invalid', 'consultantPhone' => '',
	'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->profiles[17] = array(
	'id' => 'profile-17', 'wordpressUserId' => 17, 'agencyName' => 'Legacy Profile Agency',
	'consultantName' => 'Profile Owner', 'consultantPhone' => '111',
);
$GLOBALS['wpdb']->profiles[22] = array(
	'id' => 'profile-22', 'wordpressUserId' => 22, 'wordpressUsername' => 'Whitespace_Agency',
	'wordpressEmail' => 'whitespace@example.invalid', 'agencyName' => 'Whitespace Agency',
	'consultantName' => '   ', 'consultantEmail' => 'whitespace@example.invalid', 'consultantPhone' => "\t",
	'updatedAt' => '2026-08-13 08:00:00',
);
$GLOBALS['wpdb']->applications['case-18-old'] = array(
	'id' => 'case-18-old', 'wordpressUserId' => 18, 'agencyName' => 'Old Application Agency', 'updatedAt' => '2026-08-12 08:00:00',
);
$GLOBALS['wpdb']->applications['case-18-new'] = array(
	'id' => 'case-18-new', 'wordpressUserId' => 18, 'agencyName' => 'Latest Application Agency', 'updatedAt' => '2026-08-13 08:00:00',
);

function __($text, $domain = null) { return $text; }
function get_role($slug) { return $GLOBALS['mc_identity_roles'][$slug] ?? null; }
function add_role($slug, $label, $capabilities = array()) { return $GLOBALS['mc_identity_roles'][$slug] = new MC_Agency_Identity_Role(); }
function get_option($key, $fallback = false) {
	return $GLOBALS['mc_identity_options'][$key] ?? $fallback;
}
function update_option($key, $value, $autoload = null) { $GLOBALS['mc_identity_options'][$key] = $value; return true; }
function delete_option($key) { unset($GLOBALS['mc_identity_options'][$key]); return true; }
function get_transient($key) { return $GLOBALS['mc_identity_transients'][$key] ?? false; }
function set_transient($key, $value, $expiration = 0) { $GLOBALS['mc_identity_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['mc_identity_transients'][$key]); return true; }
function add_filter(...$args) { return true; }
function remove_filter(...$args) { return true; }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_identity_actions'][$hook][] = array(
		'callback' => $callback,
		'priority' => $priority,
		'acceptedArgs' => $accepted_args,
	);
	return true;
}
function wp_next_scheduled($hook) { return $GLOBALS['mc_identity_scheduled_events'][$hook] ?? false; }
function wp_schedule_single_event($timestamp, $hook, $args = array()) {
	$GLOBALS['mc_identity_scheduled_events'][$hook] = (int) $timestamp;
	return true;
}
function register_activation_hook(...$args) { return true; }
function get_userdata($user_id) {
	if (!$GLOBALS['mc_identity_pluggable_ready']) {
		$GLOBALS['mc_identity_early_user_calls']++;
		throw new RuntimeException('get_userdata was called before WordPress loaded pluggable functions.');
	}
	return $GLOBALS['mc_identity_users'][(int) $user_id] ?? false;
}
function get_users($args = array()) {
	$users = $GLOBALS['mc_identity_users'];
	if (!empty($args['role__in'])) {
		$roles = array_map('strval', (array) $args['role__in']);
		$users = array_filter($users, function ($user) use ($roles) {
			return count(array_intersect($roles, (array) $user->roles)) > 0;
		});
	}
	if (isset($args['fields']) && 'ids' === $args['fields']) {
		return array_keys($users);
	}
	return array_values($users);
}
function wp_get_current_user() { return get_userdata($GLOBALS['mc_identity_current_user_id']); }
function get_current_user_id() { return (int) $GLOBALS['mc_identity_current_user_id']; }
function get_avatar_url($user_id, $args = array()) { return ''; }
function current_time($type, $gmt = false) { return '2026-08-13 10:00:00'; }
function absint($value) { return abs((int) $value); }
function wp_list_pluck($list, $field) { return array_map(function ($item) use ($field) { return $item->{$field}; }, $list); }
function sanitize_email($value) { return trim((string) $value); }
function sanitize_user($value, $strict = false) { return trim((string) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
function validate_username($value) { return '' !== trim((string) $value); }
function username_exists($value) { return false; }
function email_exists($value) { return false; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function clean_user_cache($user_id) { return true; }
function wp_update_user($data) {
	$GLOBALS['mc_identity_wp_update_calls'][] = $data;
	if (in_array((int) $data['ID'], $GLOBALS['mc_identity_wp_update_fail_ids'], true)) return new WP_Error('offline-failure', 'Offline update failure.');
	$user = get_userdata((int) $data['ID']);
	if (!$user) return new WP_Error('missing', 'Missing user.');
	if (isset($data['display_name'])) $user->display_name = (string) $data['display_name'];
	if (isset($data['user_email'])) $user->user_email = (string) $data['user_email'];
	return (int) $user->ID;
}
function wp_insert_user($data) {
	$user_id = $GLOBALS['mc_identity_next_user_id']++;
	$GLOBALS['mc_identity_users'][$user_id] = (object) array(
		'ID' => $user_id,
		'user_login' => (string) $data['user_login'],
		'display_name' => (string) $data['display_name'],
		'user_email' => (string) $data['user_email'],
		'roles' => array((string) $data['role']),
		'allcaps' => array(),
	);
	return $user_id;
}
function wp_delete_user($user_id) {
	if (!$GLOBALS['mc_identity_delete_user_result']) return false;
	$GLOBALS['mc_identity_deleted_user_ids'][] = (int) $user_id;
	unset($GLOBALS['mc_identity_users'][(int) $user_id]);
	return true;
}
function wp_mail() { throw new RuntimeException('Agency identity tests must never send email.'); }
function wp_generate_uuid4() { return 'offline-profile-id'; }

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';
$GLOBALS['mc_identity_pluggable_ready'] = true;

function identity_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
}
function identity_assert_true($actual, $message) { identity_assert_same(true, (bool) $actual, $message); }
function identity_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) throw new RuntimeException($message);
}
function identity_method($reflection, $name) {
	$method = $reflection->getMethod($name);
	$method->setAccessible(true);
	return $method;
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
identity_assert_same(0, $GLOBALS['mc_identity_early_user_calls'], 'Plugin boot must not call get_userdata before WordPress pluggable functions load.');
identity_assert_same('0.2.58', $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'], 'Plugin boot must not synchronously advance an incomplete identity migration.');
identity_assert_true(!empty($GLOBALS['mc_identity_actions']['plugins_loaded']), 'Agency identity migration must be registered on plugins_loaded.');
$migration_hook = end($GLOBALS['mc_identity_actions']['plugins_loaded']);
identity_assert_same(20, $migration_hook['priority'], 'Agency identity migration must run only after pluggable functions become available.');
identity_assert_same(array($plugin, 'schedule_authoritative_agency_identity_backfill'), $migration_hook['callback'], 'Normal requests must only schedule the guarded migration.');
identity_assert_true(!empty($GLOBALS['mc_identity_actions']['mc_admissions_agency_identity_backfill']), 'The migration runner must be registered only on its dedicated WordPress cron hook.');
$cron_hook = end($GLOBALS['mc_identity_actions']['mc_admissions_agency_identity_backfill']);
identity_assert_same(array($plugin, 'run_authoritative_agency_identity_backfill'), $cron_hook['callback'], 'The dedicated cron hook must target the guarded migration runner.');
identity_assert_same(array(), $GLOBALS['mc_identity_agent_batches'], 'Loading the plugin must perform no agent migration queries.');
identity_assert_same(array(), $GLOBALS['mc_identity_owner_batches'], 'Loading the plugin must perform no owner migration queries.');

unset($GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version']);
$plugin->schedule_authoritative_agency_identity_backfill();
identity_assert_true(!empty($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']), 'An incomplete migration must schedule one background cron event.');
$scheduled_once = $GLOBALS['mc_identity_scheduled_events'];
$plugin->schedule_authoritative_agency_identity_backfill();
identity_assert_same($scheduled_once, $GLOBALS['mc_identity_scheduled_events'], 'The scheduler must not duplicate a pending identity migration event.');
identity_assert_same(array(), $GLOBALS['mc_identity_agent_batches'], 'Scheduling must perform no agent migration work synchronously.');
identity_assert_same(array(), $GLOBALS['mc_identity_owner_batches'], 'Scheduling must perform no owner migration work synchronously.');
$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'] = '0.2.59';
unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'] = '0.2.58';
$GLOBALS['mc_identity_transients']['mc_admissions_agency_identity_backfill_lock'] = 1;
$plugin->run_authoritative_agency_identity_backfill();
identity_assert_same(array(), $GLOBALS['mc_identity_agent_batches'], 'An overlapping cron callback must perform no migration work while the lock is held.');
identity_assert_true(!empty($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']), 'An overlapping cron callback must schedule a later retry.');
unset(
	$GLOBALS['mc_identity_transients']['mc_admissions_agency_identity_backfill_lock'],
	$GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']
);
$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'] = '0.2.59';
$contact = identity_method($reflection, 'authoritative_agency_contact');
$overlay = identity_method($reflection, 'application_with_authoritative_agency_identity');
$migrate_display_name = identity_method($reflection, 'migrate_legacy_agency_display_name');
$resolve_owner = identity_method($reflection, 'resolve_application_owner');

$canonical = $contact->invoke($plugin, 10, array(), true);
identity_assert_same('12th-Study-Abroad', $canonical['agencyName'], 'WP display name must be authoritative before migration seeding.');
identity_assert_same('owner@example.invalid', $canonical['consultantEmail'], 'WP email must be authoritative.');
identity_assert_same('Profile Consultant', $canonical['consultantName'], 'Consultant name must come from Agency Profile.');
identity_assert_same('+357 99112233', $canonical['consultantPhone'], 'Consultant phone must come from Agency Profile.');
identity_assert_true($canonical['profileComplete'], 'A complete current profile must pass the application gate.');

$overlaid = $overlay->invoke($plugin, $GLOBALS['wpdb']->applications['case-10']);
identity_assert_same('12th-Study-Abroad', $overlaid['agencyName'], 'Application reads must ignore a spoofed agency snapshot.');
identity_assert_same('owner@example.invalid', $overlaid['consultantEmail'], 'Application reads must ignore a spoofed email snapshot.');
identity_assert_same('Profile Consultant', $overlaid['consultantName'], 'Application reads must use current Agency Profile consultant name.');
identity_assert_same('+357 99112233', $overlaid['consultantPhone'], 'Application reads must use current Agency Profile consultant phone.');

$legacy = $contact->invoke($plugin, 14, array('consultantName' => 'Legacy Contact', 'consultantPhone' => '111'), false);
identity_assert_same(false, $legacy['profileComplete'], 'Legacy snapshot fallback must not masquerade as a complete current Agency Profile.');
try {
	$contact->invoke($plugin, 14, array('consultantName' => 'Legacy Contact', 'consultantPhone' => '111'), true);
	throw new RuntimeException('An external agent without a complete Agency Profile must be blocked.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	identity_assert_contains('Complete the owning agency profile', $error->getMessage(), 'The incomplete-profile error must be clear.');
}

$GLOBALS['mc_identity_wp_update_calls'] = array();
identity_assert_true($migrate_display_name->invoke($plugin, 10), 'A raw legacy profile agency name must migrate successfully.');
identity_assert_same('12th Study Abroad', $GLOBALS['mc_identity_users'][10]->display_name, 'A legacy agency name equal to the raw username must normalize separator runs.');
identity_assert_true($migrate_display_name->invoke($plugin, 11), 'A blank display name must migrate from an underscored username.');
identity_assert_same('Atlas Bridge', $GLOBALS['mc_identity_users'][11]->display_name, 'Underscore runs must become spaces.');
identity_assert_true($migrate_display_name->invoke($plugin, 13), 'Internal staff must be a successful migration no-op.');
identity_assert_same('staff-account', $GLOBALS['mc_identity_users'][13]->display_name, 'Internal staff display names must not be changed by agency migration.');
identity_assert_true($migrate_display_name->invoke($plugin, 25), 'Internal dual-role staff must be a successful migration no-op.');
identity_assert_same('Dual Role Staff', $GLOBALS['mc_identity_users'][25]->display_name, 'Agency migration must preserve dual-role internal staff display names even when their username uses separators.');
identity_assert_true($migrate_display_name->invoke($plugin, 17), 'Legacy profile agency name migration must succeed.');
identity_assert_same('Legacy Profile Agency', $GLOBALS['mc_identity_users'][17]->display_name, 'Agency Profile name must take migration precedence.');
identity_assert_true($migrate_display_name->invoke($plugin, 18), 'Legacy application agency name migration must succeed.');
identity_assert_same('Latest Application Agency', $GLOBALS['mc_identity_users'][18]->display_name, 'The latest nonblank application agency name must be the migration fallback.');
identity_assert_true($migrate_display_name->invoke($plugin, 19), 'Username fallback migration must succeed.');
identity_assert_same('Fallback Agency', $GLOBALS['mc_identity_users'][19]->display_name, 'Username separators must be replaced when no legacy agency name exists.');
identity_assert_true($migrate_display_name->invoke($plugin, 12), 'A custom display name without a legacy agency snapshot is a successful no-op.');
identity_assert_same('Intentional Agency Ltd', $GLOBALS['mc_identity_users'][12]->display_name, 'Migration must preserve a custom display name when no legacy agency snapshot exists.');
identity_assert_true($migrate_display_name->invoke($plugin, 54), 'A separator-based agency username must override an individual display name.');
identity_assert_same('OnePoint Education', $GLOBALS['mc_identity_users'][54]->display_name, 'OnePoint-Education must become the WordPress agency display name even when the previous display name was Kashif.');
unset($GLOBALS['mc_identity_users'][54]);

$columns = $plugin->add_agency_display_name_user_column(array('username' => 'Username'));
identity_assert_same('Display Name / Agency Name', $columns['mc_admissions_agency_name'], 'WordPress Users must expose the agency display-name column.');
identity_assert_same('&lt;b&gt;Agency &amp; Co&lt;/b&gt;', $plugin->render_agency_display_name_user_column('', 'mc_admissions_agency_name', 21), 'The Users agency column must escape the WordPress display name.');
identity_assert_same('unchanged', $plugin->render_agency_display_name_user_column('unchanged', 'email', 21), 'Unrelated WordPress Users columns must remain unchanged.');

$GLOBALS['mc_identity_current_user_id'] = 11;
$session_profile = $plugin->rest_get_profile();
identity_assert_same(200, $session_profile->status, 'An agent without an Agency Profile must receive session defaults.');
identity_assert_same('Atlas Bridge', $session_profile->data['profile']['agencyName'], 'The session default agency name must come from WordPress.');
identity_assert_same('', $session_profile->data['profile']['consultantName'], 'The session default must not copy the agency name into the required consultant name.');
identity_assert_same(false, $session_profile->data['profile']['profileComplete'], 'An unsaved session-default profile must be incomplete.');
$GLOBALS['mc_identity_current_user_id'] = 14;
$incomplete_saved_profile = $plugin->rest_get_profile();
identity_assert_same('', $incomplete_saved_profile->data['profile']['consultantName'], 'A saved incomplete profile must not copy agency name into consultant name.');
identity_assert_same(false, $incomplete_saved_profile->data['profile']['profileComplete'], 'A saved profile without consultant contact fields must remain incomplete.');
$GLOBALS['mc_identity_current_user_id'] = 22;
$whitespace_saved_profile = $plugin->rest_get_profile();
identity_assert_same(false, $whitespace_saved_profile->data['profile']['profileComplete'], 'Whitespace-only consultant contact fields must remain incomplete.');

$GLOBALS['mc_identity_current_user_id'] = 13;
$administrator_profile = $plugin->rest_get_profile();
identity_assert_same(200, $administrator_profile->status, 'Administrator profile GET must use the authenticated WordPress profile endpoint.');
identity_assert_same('staff-account', $administrator_profile->data['profile']['agencyName'], 'Administrator profile GET must retain current WordPress identity.');
$administrator_profile_saved = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array(
	'agencyName' => 'Forged Administrator Name',
	'consultantName' => 'Updated Staff Contact',
	'consultantEmail' => 'forged-admin@example.invalid',
	'consultantPhone' => '+357 25111111',
))));
identity_assert_same(200, $administrator_profile_saved->status, 'Administrator profile PUT must persist through WordPress.');
identity_assert_same('staff-account', $administrator_profile_saved->data['profile']['agencyName'], 'Administrator profile PUT must ignore client identity fields.');
identity_assert_same('staff@example.invalid', $administrator_profile_saved->data['profile']['consultantEmail'], 'Administrator profile PUT must preserve the authenticated WordPress email.');
identity_assert_same('Updated Staff Contact', $administrator_profile_saved->data['profile']['consultantName'], 'Administrator profile PUT must persist editable profile fields.');

$GLOBALS['mc_identity_current_user_id'] = 23;
$admissions_profile = $plugin->rest_get_profile();
identity_assert_same(200, $admissions_profile->status, 'Admissions Officer profile GET must not be restricted to external agents.');
$admissions_profile_saved = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array(
	'consultantName' => 'Updated Admissions Contact',
	'consultantPhone' => '+357 25222222',
))));
identity_assert_same(200, $admissions_profile_saved->status, 'Admissions Officer profile PUT must persist through WordPress.');
identity_assert_same('Updated Admissions Contact', $admissions_profile_saved->data['profile']['consultantName'], 'Admissions Officer profile PUT must return the persisted internal profile.');
$GLOBALS['mc_identity_current_user_id'] = 10;

$selected_owner = $resolve_owner->invoke(
	$plugin,
	array('id' => 13, 'username' => 'staff-account', 'name' => 'Staff', 'email' => 'staff@example.invalid', 'roles' => array('administrator')),
	10
);
identity_assert_same(10, $selected_owner['id'], 'Administrator create must use the selected agent as owner.');
identity_assert_same('12th Study Abroad', $selected_owner['name'], 'Selected ownership must use the authoritative WP agency display name.');
identity_assert_same('owner@example.invalid', $selected_owner['email'], 'Selected ownership must use the authoritative WP email.');

$admissions_selected_owner = $resolve_owner->invoke(
	$plugin,
	array('id' => 23, 'username' => 'admissions-staff', 'name' => 'Admissions Staff', 'email' => 'admissions@example.invalid', 'roles' => array('admissions-officer')),
	10
);
identity_assert_same(10, $admissions_selected_owner['id'], 'Admissions Officer create must use the selected external agent as owner.');
$admissions_selected_contact = $contact->invoke($plugin, $admissions_selected_owner['id'], $admissions_selected_owner, true);
identity_assert_true($admissions_selected_contact['profileComplete'], 'Admissions Officer create must require the selected external agent to have a complete Agency Profile.');

try {
	$resolve_owner->invoke(
		$plugin,
		array('id' => 10, 'username' => '12th-Study-Abroad', 'name' => '12th Study Abroad', 'email' => 'owner@example.invalid', 'roles' => array('mc_agent')),
		11
	);
	throw new RuntimeException('An external agent must not be able to assign an application to another agent.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	identity_assert_contains('Only an administrator or Admissions Officer can assign', $error->getMessage(), 'Agent ownership tampering must be rejected explicitly.');
}

try {
	$resolve_owner->invoke(
		$plugin,
		array('id' => 24, 'username' => 'finance-staff', 'name' => 'Finance Staff', 'email' => 'finance@example.invalid', 'roles' => array('finance-officer')),
		0
	);
	throw new RuntimeException('Internal staff must not create a staff-owned application.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	identity_assert_contains('Only an external agent, administrator, or Admissions Officer can create', $error->getMessage(), 'Non-admissions staff-owned application creation must be rejected explicitly.');
}

try {
	$resolve_owner->invoke(
		$plugin,
		array('id' => 23, 'username' => 'admissions-staff', 'name' => 'Admissions Staff', 'email' => 'admissions@example.invalid', 'roles' => array('admissions-officer')),
		25
	);
	throw new RuntimeException('Internal dual-role staff must not be accepted as an external application owner.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	identity_assert_contains('not a valid agent', $error->getMessage(), 'Selected application owners must be external agents, not internal staff with an agent role.');
}

$GLOBALS['mc_identity_current_user_id'] = 13;
$administrator_agent_list = $plugin->rest_list_agents();
identity_assert_same(200, $administrator_agent_list->status, 'Administrators must be able to list agents for ownership selection.');
$administrator_agent_ids = array_map(function ($agent) { return (int) $agent['id']; }, $administrator_agent_list->data['agents']);
identity_assert_same(false, in_array(25, $administrator_agent_ids, true), 'The ownership selector must exclude internal staff even when they also have an agent role.');
$GLOBALS['mc_identity_current_user_id'] = 23;
$admissions_agent_list = $plugin->rest_list_agents();
identity_assert_same(200, $admissions_agent_list->status, 'Admissions Officers must be able to list agents for ownership selection.');
$GLOBALS['mc_identity_current_user_id'] = 10;
$external_agent_list = $plugin->rest_list_agents();
identity_assert_same(403, $external_agent_list->status, 'External agents must not be able to list other agents.');
$GLOBALS['mc_identity_current_user_id'] = 24;
$finance_agent_list = $plugin->rest_list_agents();
identity_assert_same(403, $finance_agent_list->status, 'Other internal roles must not be able to list agents for ownership selection.');
$GLOBALS['mc_identity_current_user_id'] = 23;
$admissions_agent_create = $plugin->rest_create_agent(new WP_REST_Request(array('draft' => array())));
identity_assert_same(403, $admissions_agent_create->status, 'Admissions Officers must not gain administrator-only agent-account creation access.');
unset($GLOBALS['mc_identity_users'][25]);
$GLOBALS['mc_identity_current_user_id'] = 10;

$GLOBALS['wpdb']->updates = array();
$application_updated_at = $GLOBALS['wpdb']->applications['case-10']['updatedAt'];
$repaired_profile = $plugin->rest_get_profile();
identity_assert_same(200, $repaired_profile->status, 'Profile GET must repair a stale WordPress identity snapshot.');
identity_assert_same('12th Study Abroad', $repaired_profile->data['profile']['agencyName'], 'Profile GET must return the current WordPress display name.');
identity_assert_same('12th Study Abroad', $GLOBALS['wpdb']->applications['case-10']['agencyName'], 'Application snapshot must receive the current WP display name.');
identity_assert_same('owner@example.invalid', $GLOBALS['wpdb']->applications['case-10']['consultantEmail'], 'Application snapshot must receive the current WP email.');
identity_assert_same('Profile Consultant', $GLOBALS['wpdb']->applications['case-10']['consultantName'], 'Application snapshot must receive Agency Profile consultant name.');
identity_assert_same('+357 99112233', $GLOBALS['wpdb']->applications['case-10']['consultantPhone'], 'Application snapshot must receive Agency Profile consultant phone.');
identity_assert_same($application_updated_at, $GLOBALS['wpdb']->applications['case-10']['updatedAt'], 'Profile GET repair must not invalidate an open application version.');
$application_sync_queries = array_values(array_filter($GLOBALS['wpdb']->queries, function ($query) {
	return false !== strpos($query['query'], 'UPDATE mc_admission_applications SET');
}));
identity_assert_true(!empty($application_sync_queries), 'Identity synchronization must issue an explicit application snapshot query.');
$application_sync_query = end($application_sync_queries);
identity_assert_contains('updatedAt = updatedAt', $application_sync_query['query'], 'Application identity SQL must suppress the live ON UPDATE timestamp behavior.');
foreach ($GLOBALS['wpdb']->updates as $update) {
	identity_assert_same(false, 'mc_admission_applications' === $update['table'], 'Application identity synchronization must not use wpdb::update, which triggers the live ON UPDATE timestamp.');
}
foreach ($GLOBALS['wpdb']->updates as $update) {
	identity_assert_same(false, array_key_exists('updatedAt', $update['data']), 'Identity synchronization must not invalidate open application versions.');
}

$before_update_calls = count($GLOBALS['mc_identity_wp_update_calls']);
$response = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array(
	'agencyName' => 'Client Spoof',
	'consultantName' => 'Updated Consultant',
	'consultantEmail' => 'attacker@example.invalid',
	'consultantPhone' => '+357 99000000',
	'defaultApplicationRoute' => 'standard',
))));
identity_assert_same(200, $response->status, 'A valid Agency Profile update must succeed.');
identity_assert_same('12th Study Abroad', $response->data['profile']['agencyName'], 'Profile PUT must ignore client agency name.');
identity_assert_same('owner@example.invalid', $response->data['profile']['consultantEmail'], 'Profile PUT must ignore client consultant email.');
identity_assert_same('Updated Consultant', $response->data['profile']['consultantName'], 'Profile PUT must preserve editable consultant name.');
identity_assert_same('+357 99000000', $response->data['profile']['consultantPhone'], 'Profile PUT must preserve editable consultant phone.');
identity_assert_same($before_update_calls, count($GLOBALS['mc_identity_wp_update_calls']), 'Profile PUT must never call wp_update_user.');

$missing_phone = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array('consultantName' => 'Contact', 'consultantPhone' => ''))));
identity_assert_same(400, $missing_phone->status, 'Profile PUT must require consultant phone.');
identity_assert_contains('Consultant phone is required', $missing_phone->data['error'], 'Missing phone response must be explicit.');
$missing_name = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array('consultantName' => '', 'consultantPhone' => '+357 99000000'))));
identity_assert_same(400, $missing_name->status, 'Profile PUT must require consultant name.');
identity_assert_contains('Consultant name is required', $missing_name->data['error'], 'Missing name response must be explicit.');

$GLOBALS['mc_identity_current_user_id'] = 15;
$missing_wp_name = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array('consultantName' => 'Contact', 'consultantPhone' => '+357 99000000'))));
identity_assert_same(400, $missing_wp_name->status, 'Profile PUT must reject a missing WordPress agency name.');
identity_assert_contains('Update the WordPress account display name and email', $missing_wp_name->data['error'], 'Missing WordPress identity response must be explicit.');
$GLOBALS['mc_identity_current_user_id'] = 16;
$invalid_wp_email = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array('consultantName' => 'Contact', 'consultantPhone' => '+357 99000000'))));
identity_assert_same(400, $invalid_wp_email->status, 'Profile PUT must reject an invalid WordPress email.');
identity_assert_contains('Update the WordPress account display name and email', $invalid_wp_email->data['error'], 'Invalid WordPress identity response must be explicit.');
$GLOBALS['mc_identity_current_user_id'] = 10;

$GLOBALS['mc_identity_fail_table_write'] = 'mc_agency_profiles';
$failed_profile_write = $plugin->rest_save_profile(new WP_REST_Request(array('draft' => array('consultantName' => 'Contact', 'consultantPhone' => '+357 99000000'))));
identity_assert_same(500, $failed_profile_write->status, 'Profile PUT must report a failed database write.');
identity_assert_contains('Unable to save the agency profile', $failed_profile_write->data['error'], 'Failed profile persistence must have a clear error.');
$GLOBALS['mc_identity_fail_table_write'] = null;

$save_application_source = identity_method($reflection, 'save_admission_application');
$source_lines = file($save_application_source->getFileName());
$save_source = implode('', array_slice($source_lines, $save_application_source->getStartLine() - 1, $save_application_source->getEndLine() - $save_application_source->getStartLine() + 1));
identity_assert_contains("\$owner_identity['agencyName']", $save_source, 'Application creates and updates must overwrite client agency names with the selected owner.');
identity_assert_contains("\$owner_identity['consultantEmail']", $save_source, 'Application creates and updates must overwrite client emails with the selected owner.');
identity_assert_contains("\$owner_identity['consultantName']", $save_source, 'Application creates and updates must ignore per-application consultant names.');
identity_assert_contains("\$owner_identity['consultantPhone']", $save_source, 'Application creates and updates must ignore per-application consultant phones.');
identity_assert_contains('$assigned_agent_id', $save_source, 'Administrator create must resolve identity from the explicitly selected owner.');

$continue_assigned_preparation = identity_method($reflection, 'can_continue_assigned_preparation');
$submit_prepared_application = identity_method($reflection, 'can_submit_prepared_application');
$admissions_user = array('roles' => array('admissions-officer'));
$finance_user = array('roles' => array('finance-officer'));
identity_assert_same(
	true,
	$continue_assigned_preparation->invoke($plugin, $admissions_user, 'profile-preparation'),
	'Admissions must be able to resume the assigned draft after its first save.'
);
identity_assert_same(
	true,
	$submit_prepared_application->invoke($plugin, $admissions_user, 'profile-preparation'),
	'Admissions must be able to submit the resumed assigned draft for review.'
);
identity_assert_same(
	false,
	$continue_assigned_preparation->invoke($plugin, $admissions_user, 'review-pending'),
	'Admissions assigned-draft editing must end when the case leaves preparation.'
);
identity_assert_same(
	false,
	$continue_assigned_preparation->invoke($plugin, $admissions_user, 'future-unknown-status'),
	'Unknown or future statuses must never inherit preparation-stage editing permission.'
);
identity_assert_same(
	false,
	$submit_prepared_application->invoke($plugin, array('roles' => array('mc_agent')), 'future-unknown-status'),
	'Unknown or future statuses must never inherit agent submission permission.'
);
identity_assert_same(
	false,
	$submit_prepared_application->invoke($plugin, $admissions_user, 'review-pending'),
	'Admissions must not replay preparation submission after the stage advances.'
);
identity_assert_same(
	false,
	$continue_assigned_preparation->invoke($plugin, $finance_user, 'profile-preparation'),
	'Finance must not gain assigned application intake editing.'
);
identity_assert_contains('$can_continue_assigned_preparation', $save_source, 'Existing assigned draft saves must use the preparation-only permission gate.');
identity_assert_contains('can_submit_prepared_application', $save_source, 'Review submission must use the preparation-only permission gate.');
identity_assert_contains(
	'$this->is_external_agent_user($user) || $can_continue_assigned_preparation',
	$save_source,
	'Admissions continuation must revalidate that the selected owner still has a complete Agency Profile.'
);

$create_agent_source = identity_method($reflection, 'rest_create_agent');
$create_agent_source = implode('', array_slice($source_lines, $create_agent_source->getStartLine() - 1, $create_agent_source->getEndLine() - $create_agent_source->getStartLine() + 1));
identity_assert_contains('$phone', $create_agent_source, 'Administrator-created agents must provide consultant phone.');
identity_assert_contains('Display name, consultant name, and consultant phone are required.', $create_agent_source, 'Agent creation must explain the complete-profile requirement.');

$GLOBALS['mc_identity_current_user_id'] = 13;
$GLOBALS['mc_identity_fail_table_write'] = 'mc_agency_profiles';
$failed_agent_create = $plugin->rest_create_agent(new WP_REST_Request(array('draft' => array(
	'username' => 'new-agency',
	'email' => 'new-agency@example.invalid',
	'name' => 'New Agency',
	'consultantName' => 'New Consultant',
	'consultantPhone' => '+357 99111111',
	'password' => 'Temporary-Password-123',
))));
identity_assert_same(500, $failed_agent_create->status, 'Agent creation must fail if its required Agency Profile cannot be inserted.');
identity_assert_contains('incomplete account was removed', $failed_agent_create->data['error'], 'Agent profile failure must explain that the incomplete WordPress account was rolled back.');
identity_assert_same(array(30), $GLOBALS['mc_identity_deleted_user_ids'], 'A WordPress user created without its required profile must be deleted immediately.');
identity_assert_same(false, isset($GLOBALS['mc_identity_users'][30]), 'The rolled-back WordPress agent must not remain available.');

$GLOBALS['mc_identity_delete_user_result'] = false;
$failed_agent_cleanup = $plugin->rest_create_agent(new WP_REST_Request(array('draft' => array(
	'username' => 'orphaned-agency',
	'email' => 'orphaned-agency@example.invalid',
	'name' => 'Orphaned Agency',
	'consultantName' => 'Orphaned Consultant',
	'consultantPhone' => '+357 99222222',
	'password' => 'Temporary-Password-456',
))));
identity_assert_same(500, $failed_agent_cleanup->status, 'Agent creation must still fail if automatic account cleanup also fails.');
identity_assert_contains('account remains', $failed_agent_cleanup->data['error'], 'Failed cleanup must clearly say the incomplete WordPress account remains.');
identity_assert_contains('orphaned-agency (user ID 31)', $failed_agent_cleanup->data['error'], 'Failed cleanup must identify the username and ID without exposing the password.');
identity_assert_same(false, false !== strpos($failed_agent_cleanup->data['error'], 'Temporary-Password-456'), 'Failed cleanup must never expose the temporary password.');
identity_assert_true(isset($GLOBALS['mc_identity_users'][31]), 'A failed rollback must leave the incomplete WordPress user visible for manual cleanup.');
$GLOBALS['mc_identity_delete_user_result'] = true;
$GLOBALS['mc_identity_fail_table_write'] = null;
$GLOBALS['mc_identity_current_user_id'] = 10;

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
identity_assert_contains('Version: 0.2.61', $plugin_source, 'The plugin header must advertise 0.2.61.');
identity_assert_contains("\$owner_identity['agencyName']", $plugin_source, 'Application saves must use authoritative agency identity.');
identity_assert_contains("\$owner_identity['consultantName']", $plugin_source, 'Application saves must use the owning Agency Profile contact.');
identity_assert_contains('$identity_safe_draft', $plugin_source, 'Test-data inference must use the authoritative identity overlay.');
identity_assert_same(
	3,
	substr_count($save_source, "'consultantName' => \$owner_identity['consultantName']"),
	'Both application create and update must sanitize consultant name before test-data inference.'
);
identity_assert_same(
	3,
	substr_count($save_source, "'consultantPhone' => \$owner_identity['consultantPhone']"),
	'Both application create and update must sanitize consultant phone before test-data inference.'
);

for ($user_id = 1000; $user_id <= 1204; $user_id++) {
	$GLOBALS['mc_identity_users'][$user_id] = (object) array(
		'ID' => $user_id,
		'user_login' => 'Batch_Agency_' . $user_id,
		'display_name' => 'Batch_Agency_' . $user_id,
		'user_email' => 'batch-' . $user_id . '@example.invalid',
		'roles' => array('mc_agent'),
		'allcaps' => array(),
	);
}
$GLOBALS['wpdb']->applications['deleted-owner-case'] = array(
	'id' => 'deleted-owner-case', 'wordpressUserId' => 9999, 'agencyName' => 'Deleted Legacy Owner', 'updatedAt' => '2026-08-13 09:00:00',
);
$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'] = '0.2.58';
unset(
	$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_phase_complete'],
	$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_cursor'],
	$GLOBALS['mc_identity_options']['mc_admissions_agency_identity_cursor']
);
$GLOBALS['mc_identity_agent_batches'] = array();
$GLOBALS['mc_identity_owner_batches'] = array();
$GLOBALS['mc_identity_wp_update_fail_ids'] = array(20);
unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
$plugin->run_authoritative_agency_identity_backfill();
identity_assert_same('0.2.58', $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'], 'A transient WordPress display-name failure must not mark the identity migration complete.');
identity_assert_same(19, $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_cursor'], 'A failed agent migration must preserve the last successful ordered ID cursor.');
identity_assert_same(0, count($GLOBALS['mc_identity_owner_batches']), 'Snapshot backfill must not overlap the agent display-name phase.');
identity_assert_true(!empty($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']), 'A failed cron batch must schedule a retry.');

$GLOBALS['mc_identity_wp_update_fail_ids'] = array();
unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
$plugin->run_authoritative_agency_identity_backfill();
identity_assert_same(25, count($GLOBALS['mc_identity_agent_batches'][1]), 'Each agent migration request must be bounded to 25 ordered users.');
identity_assert_same(1020, $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_cursor'], 'A full agent batch must persist its last processed user ID.');
$phase_runs = 0;
while ('1' !== ($GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_phase_complete'] ?? '0') && $phase_runs < 20) {
	unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
	$plugin->run_authoritative_agency_identity_backfill();
	$phase_runs++;
}
identity_assert_same('1', $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_phase_complete'], 'The final partial agent batch must complete phase one.');
identity_assert_same(false, isset($GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_cursor']), 'Completing agent migration must clear its cursor.');
identity_assert_same(0, count($GLOBALS['mc_identity_owner_batches']), 'Owner snapshot processing must start only after the agent phase completes.');
foreach ($GLOBALS['mc_identity_agent_batches'] as $agent_batch) {
	identity_assert_true(count($agent_batch) <= 25, 'No agent migration batch may exceed 25 users.');
}

$GLOBALS['mc_identity_fail_table_write'] = 'mc_admission_applications';
unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
$plugin->run_authoritative_agency_identity_backfill();
identity_assert_same('0.2.58', $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'], 'A snapshot synchronization failure must not complete the migration.');
identity_assert_same(0, $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_cursor'], 'A failed first owner must preserve the owner cursor for retry.');
$GLOBALS['mc_identity_fail_table_write'] = null;
unset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']);
$plugin->run_authoritative_agency_identity_backfill();
identity_assert_same('0.2.59', $GLOBALS['mc_identity_options']['mc_admissions_agency_identity_version'], 'A later owner-phase retry must complete the migration.');
identity_assert_same('Retry Agency', $GLOBALS['mc_identity_users'][20]->display_name, 'The retried username fallback migration must update the display name.');
identity_assert_true(in_array(9999, end($GLOBALS['mc_identity_owner_batches']), true), 'The bounded owner phase must observe deleted legacy owners.');
identity_assert_same(false, isset($GLOBALS['mc_identity_options']['mc_admissions_agency_identity_agent_phase_complete']), 'Completed migration must clean up its phase marker.');
identity_assert_same(false, isset($GLOBALS['mc_identity_scheduled_events']['mc_admissions_agency_identity_backfill']), 'A completed migration must not schedule another cron event.');

echo "Agency identity tests passed.\n";
