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

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

final class WP_REST_Request implements ArrayAccess {
	private $route_params;
	private $json_params;

	public function __construct(array $route_params = array(), array $json_params = array()) {
		$this->route_params = $route_params;
		$this->json_params = $json_params;
	}

	public function get_json_params() {
		return $this->json_params;
	}

	public function offsetExists($offset) {
		return array_key_exists($offset, $this->route_params);
	}

	public function offsetGet($offset) {
		return $this->route_params[$offset] ?? null;
	}

	public function offsetSet($offset, $value) {
		$this->route_params[$offset] = $value;
	}

	public function offsetUnset($offset) {
		unset($this->route_params[$offset]);
	}
}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Finance_Test_Role {
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

final class MC_Finance_Test_Wpdb {
	public $prefix = 'wp_';
	public $users = 'wp_users';
	public $usermeta = 'wp_usermeta';
	public $application = array();
	public $profile = array();
	public $commissions = array();
	public $refunds = array();
	public $letter_drafts = array();
	public $communications = array();
	public $activities = array();
	public $insert_inputs = array();
	public $events = array();
	public $force_stale = false;
	public $fail_post_commit_reload = false;
	public $fail_delivery_audit = false;
	public $fail_required_activity = false;
	public $throw_required_activity = false;
	public $throw_rollback = false;
	public $known_tables = array();
	public $refund_payment_reference_column = true;
	private $transaction_snapshot = null;
	private $committed = false;
	private $version_tick = 0;

	public function __construct() {
		$this->known_tables = array(
			'mc_admission_applications',
			'mc_admission_documents',
			'mc_admission_activities',
			'mc_admission_communications',
			'mc_admission_letter_drafts',
			'mc_admission_settings',
			'mc_admission_payments',
			'mc_commission_records',
			'mc_refund_records',
			'mc_admission_migration_cases',
			'mc_admission_immigration_cases',
			'mc_agency_profiles',
		);
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) {
			$args = array_values($args[0]);
		}
		return array('query' => (string) $query, 'args' => array_values($args));
	}

	private function unpack($prepared) {
		return is_array($prepared)
			? $prepared
			: array('query' => (string) $prepared, 'args' => array());
	}

	public function get_var($prepared) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$args = $call['args'];
		if (false !== strpos($query, 'SHOW TABLES LIKE')) {
			$table = isset($args[0]) ? (string) $args[0] : '';
			return in_array($table, $this->known_tables, true) ? $table : null;
		}
		if (false !== strpos($query, "SHOW COLUMNS FROM mc_refund_records LIKE 'paymentReference'")) {
			return $this->refund_payment_reference_column ? 'paymentReference' : null;
		}
		return null;
	}

	public function get_row($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$args = $call['args'];
		$this->events[] = 'get_row:' . trim((string) preg_replace('/\s+/', ' ', $query));

		if (false !== strpos($query, 'FROM mc_admission_applications')) {
			if (
				$this->fail_post_commit_reload
				&& $this->committed
				&& false === strpos($query, 'FOR UPDATE')
			) {
				throw new RuntimeException('Offline post-commit case reload failure.');
			}
			return $this->application;
		}
		if (false !== strpos($query, 'FROM mc_agency_profiles')) {
			return $this->profile;
		}
		if (false !== strpos($query, 'FROM mc_commission_records') && false !== strpos($query, 'WHERE id = %s')) {
			return $this->find_record($this->commissions, (string) ($args[0] ?? ''), (string) ($args[1] ?? ''));
		}
		if (false !== strpos($query, 'FROM mc_refund_records') && false !== strpos($query, 'WHERE id = %s')) {
			return $this->find_record($this->refunds, (string) ($args[0] ?? ''), (string) ($args[1] ?? ''));
		}
		if (false !== strpos($query, 'FROM mc_admission_letter_drafts')) {
			foreach ($this->letter_drafts as $draft) {
				if (false !== strpos($query, 'WHERE id = %s')) {
					if ((string) $draft['id'] === (string) ($args[0] ?? '')) return $draft;
				} elseif (
					(string) $draft['applicationId'] === (string) ($args[0] ?? '')
					&& (string) $draft['templateId'] === (string) ($args[1] ?? '')
				) {
					return $draft;
				}
			}
			return null;
		}
		if (false !== strpos($query, 'FROM mc_admission_migration_cases')) {
			return null;
		}
		if (false !== strpos($query, 'FROM mc_admission_immigration_cases')) {
			return null;
		}
		return null;
	}

	private function find_record(array $records, $record_id, $application_id) {
		foreach ($records as $record) {
			if ((string) $record['id'] === $record_id && (string) $record['applicationId'] === $application_id) {
				return $record;
			}
		}
		return null;
	}

	public function get_results($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		if (false !== strpos($query, 'FROM mc_admission_activities')) {
			return array_reverse($this->activities);
		}
		if (false !== strpos($query, 'FROM mc_admission_communications')) {
			return array_reverse($this->communications);
		}
		if (false !== strpos($query, 'FROM mc_commission_records')) {
			return array_reverse($this->commissions);
		}
		if (false !== strpos($query, 'FROM mc_refund_records')) {
			return array_reverse($this->refunds);
		}
		if (false !== strpos($query, 'FROM mc_admission_letter_drafts')) {
			return array_reverse($this->letter_drafts);
		}
		return array();
	}

	public function query($prepared) {
		$call = $this->unpack($prepared);
		$query = trim($call['query']);
		$args = $call['args'];
		$this->events[] = 'query:' . trim((string) preg_replace('/\s+/', ' ', $query));

		if ('START TRANSACTION' === $query) {
			$this->transaction_snapshot = array(
				'application' => $this->application,
				'commissions' => $this->commissions,
				'refunds' => $this->refunds,
				'letter_drafts' => $this->letter_drafts,
				'communications' => $this->communications,
				'activities' => $this->activities,
			);
			return 1;
		}
		if ('COMMIT' === $query) {
			$this->committed = true;
			$this->transaction_snapshot = null;
			return 1;
		}
		if ('ROLLBACK' === $query) {
			if ($this->throw_rollback) {
				throw new Error('Offline rollback throwable.');
			}
			if (is_array($this->transaction_snapshot)) {
				foreach ($this->transaction_snapshot as $key => $value) {
					$this->{$key} = $value;
				}
			}
			$this->transaction_snapshot = null;
			return 1;
		}
		if (preg_match('/CREATE TABLE IF NOT EXISTS\s+([A-Za-z0-9_]+)/i', $query, $matches)) {
			if (!in_array($matches[1], $this->known_tables, true)) {
				$this->known_tables[] = $matches[1];
			}
			return 1;
		}
		if (false !== strpos($query, 'ALTER TABLE mc_refund_records ADD COLUMN paymentReference')) {
			$this->refund_payment_reference_column = true;
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_admission_applications SET lastUpdatedByName')) {
			if ($this->force_stale || (string) ($args[2] ?? '') !== (string) $this->application['updatedAt']) {
				return 0;
			}
			$this->version_tick++;
			$this->application['lastUpdatedByName'] = (string) $args[0];
			$this->application['updatedAt'] = sprintf('2026-08-20 10:00:%02d.000', $this->version_tick);
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_commission_records SET ')) {
			return $this->apply_record_update('commissions', $query, $args);
		}
		if (false !== strpos($query, 'UPDATE mc_refund_records SET ')) {
			return $this->apply_record_update('refunds', $query, $args);
		}
		if (false !== strpos($query, 'UPDATE mc_admission_communications SET detail = CONCAT')) {
			if ($this->fail_delivery_audit) {
				return false;
			}
			foreach ($this->communications as &$communication) {
				if ((string) $communication['id'] === (string) $args[1] && (string) $communication['applicationId'] === (string) $args[2]) {
					$communication['detail'] .= (string) $args[0];
					return 1;
				}
			}
			unset($communication);
			return 0;
		}
		return 1;
	}

	private function apply_record_update($property, $query, array $args) {
		if (!preg_match('/ SET (.+) WHERE id = %s AND applicationId = %s/s', $query, $matches)) {
			return false;
		}
		$arg_index = 0;
		$values = array();
		foreach (preg_split('/,\s*/', trim($matches[1])) as $assignment) {
			if (preg_match('/^([A-Za-z0-9_]+) = %s$/', $assignment, $column)) {
				$values[$column[1]] = $args[$arg_index++];
			} elseif (preg_match('/^([A-Za-z0-9_]+) = NULL$/', $assignment, $column)) {
				$values[$column[1]] = null;
			} elseif ('updatedAt = CURRENT_TIMESTAMP(3)' === $assignment) {
				$values['updatedAt'] = '2026-08-20 10:00:50.000';
			}
		}
		$record_id = (string) ($args[$arg_index] ?? '');
		$application_id = (string) ($args[$arg_index + 1] ?? '');
		foreach ($this->{$property} as &$record) {
			if ((string) $record['id'] === $record_id && (string) $record['applicationId'] === $application_id) {
				$record = array_merge($record, $values);
				return 1;
			}
		}
		unset($record);
		return 0;
	}

	public function insert($table, $data, $format = null) {
		$this->insert_inputs[] = array('table' => $table, 'data' => $data);
		if (!isset($data['createdAt'])) {
			$data['createdAt'] = '2026-08-20 10:00:30.123';
		}
		if ('mc_commission_records' === $table) {
			$data['updatedAt'] = $data['updatedAt'] ?? '2026-08-20 10:00:30.123';
			$this->commissions[] = $data;
			return 1;
		}
		if ('mc_refund_records' === $table) {
			$data['updatedAt'] = $data['updatedAt'] ?? '2026-08-20 10:00:30.123';
			$this->refunds[] = $data;
			return 1;
		}
		if ('mc_admission_letter_drafts' === $table) {
			$this->letter_drafts[] = $data;
			return 1;
		}
		if ('mc_admission_communications' === $table) {
			$this->communications[] = $data;
			return 1;
		}
		if ('mc_admission_activities' === $table) {
			if ($this->throw_required_activity) {
				throw new Error('Offline activity throwable.');
			}
			if ($this->fail_required_activity) {
				return false;
			}
			if ($this->fail_delivery_audit && $this->committed) {
				return false;
			}
			$this->activities[] = $data;
			return 1;
		}
		return 1;
	}

	public function update($table, $data, $where, $format = null, $where_format = null) {
		if ('mc_admission_letter_drafts' !== $table) {
			return 1;
		}
		foreach ($this->letter_drafts as &$draft) {
			if ((string) $draft['id'] === (string) ($where['id'] ?? '')) {
				$draft = array_merge($draft, $data);
				return 1;
			}
		}
		unset($draft);
		return 0;
	}

	public function reset(array $application, array $refunds = array()) {
		$this->application = $application;
		$this->profile = array(
			'id' => 'profile-1',
			'wordpressUserId' => 42,
			'consultantName' => 'Agency Consultant',
			'consultantPhone' => '+357000000',
		);
		$this->commissions = array();
		$this->refunds = $refunds;
		$this->letter_drafts = array();
		$this->communications = array();
		$this->activities = array();
		$this->insert_inputs = array();
		$this->events = array();
		$this->force_stale = false;
		$this->fail_post_commit_reload = false;
		$this->fail_delivery_audit = false;
		$this->fail_required_activity = false;
		$this->throw_required_activity = false;
		$this->throw_rollback = false;
		$this->committed = false;
		$this->transaction_snapshot = null;
		$this->version_tick = 0;
	}
}

$GLOBALS['wpdb'] = new MC_Finance_Test_Wpdb();
$GLOBALS['mc_finance_roles'] = array();
$GLOBALS['mc_finance_routes'] = array();
$GLOBALS['mc_finance_mail_calls'] = array();
$GLOBALS['mc_finance_mail_result'] = true;
$GLOBALS['mc_finance_current_user'] = null;
$GLOBALS['mc_finance_uuid'] = 0;
$GLOBALS['mc_finance_options'] = array('mc_admissions_finance_workspace_schema_version' => '0.2.61');

function __($text, $domain = null) { return $text; }
function get_role($slug) { return $GLOBALS['mc_finance_roles'][$slug] ?? null; }
function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Finance_Test_Role($label);
	$GLOBALS['mc_finance_roles'][$slug] = $role;
	return $role;
}
function get_option($key, $fallback = false) {
	$versions = array(
		'mc_admissions_application_test_data_schema_version' => '1',
		'mc_admissions_notification_activity_schema_version' => '1',
		'mc_admissions_resource_index_version' => '1',
		'mc_admissions_schema_version' => '0.2.14',
		'mc_admissions_offer_detail_schema_version' => '0.2.38',
		'mc_admissions_case_detail_schema_version' => '0.2.45',
		'mc_admissions_document_assessment_schema_version' => '1',
	);
	if (array_key_exists($key, $GLOBALS['mc_finance_options'])) {
		return $GLOBALS['mc_finance_options'][$key];
	}
	return array_key_exists($key, $versions) ? $versions[$key] : $fallback;
}
function update_option($key, $value, $autoload = null) {
	$GLOBALS['mc_finance_options'][$key] = $value;
	return true;
}
function add_filter(...$args) { return true; }
function add_action(...$args) { return true; }
function register_activation_hook(...$args) { return true; }
function register_rest_route($namespace, $route, $args = array(), $override = false) {
	$GLOBALS['mc_finance_routes'][] = array('namespace' => $namespace, 'route' => $route, 'args' => $args);
	return true;
}
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_email($value) { return trim((string) $value); }
function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function wp_strip_all_tags($value, $remove_breaks = false) { return strip_tags((string) $value); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function rest_url($path = '') { return '/wp-json/' . ltrim((string) $path, '/'); }
function wp_generate_uuid4() {
	$GLOBALS['mc_finance_uuid']++;
	return 'offline-finance-' . $GLOBALS['mc_finance_uuid'];
}
function current_time($type, $gmt = false) { return '2026-08-20 10:00:30'; }
function wp_get_current_user() { return $GLOBALS['mc_finance_current_user']; }
function get_avatar_url($user_id, $args = array()) { return ''; }
function get_userdata($user_id) {
	if (42 !== (int) $user_id) {
		return false;
	}
	return (object) array(
		'ID' => 42,
		'user_login' => 'agency-owner',
		'display_name' => 'Current Agency',
		'user_email' => 'agency@example.invalid',
		'roles' => array('mc_agent'),
		'allcaps' => array(),
	);
}
function get_users($args = array()) {
	$users = array();
	foreach ((array) ($args['role__in'] ?? array()) as $role) {
		$users[] = (object) array(
			'ID' => count($users) + 100,
			'display_name' => $role,
			'user_email' => $role . '@example.invalid',
		);
	}
	return $users;
}
function wp_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
	$GLOBALS['mc_finance_mail_calls'][] = compact('to', 'subject', 'message', 'headers', 'attachments');
	$GLOBALS['wpdb']->events[] = 'mail:' . $subject;
	return (bool) $GLOBALS['mc_finance_mail_result'];
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function finance_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}
function finance_assert_true($actual, $message) {
	if (!$actual) {
		throw new RuntimeException($message);
	}
}
function finance_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}
function finance_assert_throws($expected, $callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		finance_assert_same($expected, $error->getMessage(), $message);
		return;
	}
	throw new RuntimeException($message . ' No exception was thrown.');
}
function finance_application(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'app-finance-offline', 'referenceCode' => 'MC-FINANCE1',
			'wordpressUserId' => 42, 'wordpressUsername' => 'agency-owner', 'wordpressEmail' => 'agency@example.invalid',
			'fullName' => 'Finance Student', 'passportNumber' => 'OFFLINE', 'email' => 'student@example.invalid',
			'phone' => '+000000', 'birthday' => '2000-01-01', 'address' => 'Offline', 'city' => 'Offline',
			'postalCode' => '0000', 'country' => 'Cyprus', 'gender' => 'Other', 'semester' => 'fall', 'year' => '2026',
			'applicationRoute' => 'standard', 'programmeCode' => 'business-administration', 'programmeLabel' => 'Business Administration',
			'agencyName' => 'Snapshot Agency', 'consultantName' => 'Snapshot Consultant', 'consultantEmail' => 'old@example.invalid',
			'consultantPhone' => null, 'submissionDate' => '2026-08-20', 'tuitionAcknowledged' => 1,
			'offerTermsAcknowledged' => 1, 'gdprAcknowledged' => 1, 'isTestData' => 0, 'status' => 'prepayment-pending',
			'workflowNote' => null, 'reviewerDecision' => 'academically-cleared', 'reviewSummary' => null,
			'decisionDueDate' => null, 'offerIssuedDate' => null, 'offerExpiryDate' => null, 'offerConditionNote' => null,
			'classesStartDate' => null, 'tuitionFeeFirstYear' => null, 'tuitionFeeFollowingYears' => null,
			'termBalanceApplies' => 0, 'paymentStatus' => 'awaiting-payment', 'paymentAmount' => null,
			'paymentCurrency' => 'EUR', 'paymentReference' => null, 'paymentConfirmedDate' => null, 'financeNote' => null,
			'permitStatus' => 'not-started', 'permitReference' => null, 'permitSubmittedDate' => null,
			'permitDecisionDate' => null, 'permitNote' => null, 'arrivalStatus' => 'planning', 'travelDate' => null,
			'accommodationStatus' => null, 'enrollmentStatus' => 'pending', 'orientationDate' => null,
			'enrollmentNote' => null, 'lateArrivalReason' => null, 'lastUpdatedByName' => 'Previous User',
			'createdAt' => '2026-08-20 09:00:00.000', 'updatedAt' => '2026-08-20 10:00:00.000',
		),
		$overrides
	);
}
function finance_user(array $roles = array('administrator'), $id = 7) {
	return array('id' => $id, 'username' => 'offline-user', 'name' => 'Offline User', 'email' => 'staff@example.invalid', 'roles' => $roles);
}
function finance_wp_user(array $roles = array('administrator'), $id = 7) {
	return (object) array(
		'ID' => $id, 'user_login' => 'offline-user', 'display_name' => 'Offline User',
		'user_email' => 'staff@example.invalid', 'roles' => $roles, 'allcaps' => array(),
	);
}
function finance_request(array $json) {
	return new WP_REST_Request(array('application_id' => 'app-finance-offline'), $json);
}
function finance_reset(array $application_overrides = array(), array $refunds = array()) {
	$GLOBALS['wpdb']->reset(finance_application($application_overrides), $refunds);
	$GLOBALS['mc_finance_mail_calls'] = array();
	$GLOBALS['mc_finance_mail_result'] = true;
	$GLOBALS['mc_finance_current_user'] = finance_wp_user();
}
function finance_invoke($method, $action, array $draft, array $user = null, $expected = '2026-08-20T10:00:00.000Z') {
	return $method->invoke(
		mc_admissions_wordpress_backend(),
		array(
			'applicationId' => 'app-finance-offline', 'action' => $action, 'draft' => $draft,
			'expectedUpdatedAt' => $expected, 'user' => $user ?? finance_user(),
		)
	);
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$finance_command = $reflection->getMethod('record_finance_workspace_action');
$finance_command->setAccessible(true);
$can_manage = $reflection->getMethod('can_manage_finance_workspace');
$can_manage->setAccessible(true);
$assert_operations = $reflection->getMethod('assert_operations_patch_authorized');
$assert_operations->setAccessible(true);
$ensure_schema = $reflection->getMethod('ensure_finance_workspace_schema');
$ensure_schema->setAccessible(true);
$normalize_money = $reflection->getMethod('normalize_finance_money');
$normalize_money->setAccessible(true);

// Dedicated routes and permissions.
$plugin->register_rest_routes();
$finance_routes = array_values(array_filter($GLOBALS['mc_finance_routes'], static function ($route) {
	return '/applications/(?P<application_id>[A-Za-z0-9_-]+)/finance' === $route['route'];
}));
$communication_routes = array_values(array_filter($GLOBALS['mc_finance_routes'], static function ($route) {
	return '/applications/(?P<application_id>[A-Za-z0-9_-]+)/communications' === $route['route'];
}));
finance_assert_same(1, count($finance_routes), 'The finance route must be registered once.');
finance_assert_same(WP_REST_Server::CREATABLE, $finance_routes[0]['args']['methods'], 'The finance route must use POST.');
finance_assert_same(array($plugin, 'rest_record_finance_workspace'), $finance_routes[0]['args']['callback'], 'The finance route must use its dedicated handler.');
finance_assert_same(1, count($communication_routes), 'The communications route must be registered once.');
finance_assert_same(array($plugin, 'rest_record_finance_communication'), $communication_routes[0]['args']['callback'], 'The communications route must use its dedicated handler.');
finance_assert_same(true, $can_manage->invoke($plugin, finance_user(array('administrator'))), 'Administrator must manage finance.');
finance_assert_same(true, $can_manage->invoke($plugin, finance_user(array('finance-officer'))), 'Finance Officer must manage finance.');
finance_assert_same(false, $can_manage->invoke($plugin, finance_user(array('admissions-officer'))), 'Admissions Officer must not mutate finance records.');

finance_reset();
$GLOBALS['mc_finance_current_user'] = finance_wp_user(array('admissions-officer'));
$response = $plugin->rest_record_finance_workspace(finance_request(array(
	'action' => 'commission',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('status' => 'pending-approval', 'baseAmount' => '100', 'amount' => '10', 'currency' => 'EUR'),
)));
finance_assert_same(403, $response->get_status(), 'Admissions Officer must receive a 403 from the finance endpoint.');
finance_assert_same(0, count($GLOBALS['wpdb']->commissions), 'Forbidden finance requests must not write records.');

$maximum_money = $normalize_money->invoke($plugin, '999,999,999.99', 'Commission amount');
finance_assert_same('999999999.99', $maximum_money['value'], 'Nine whole digits must be accepted and canonicalized.');
finance_assert_throws(
	'Commission amount is too large.',
	static function () use ($normalize_money, $plugin) {
		$normalize_money->invoke($plugin, '1,000,000,000', 'Commission amount');
	},
	'Ten whole digits must exceed the deliberate money bound.'
);

// The generic operations path can still update payment/prepayment details, but
// cannot bypass dedicated commission/refund validation.
$assert_operations->invoke($plugin, array('paymentAmount' => '1000.00'), finance_user(array('finance-officer')));
finance_assert_throws(
	'Use the Commissions & Refunds workspace to update commission or refund records.',
	static function () use ($assert_operations, $plugin) {
		$assert_operations->invoke($plugin, array('commissionAmount' => '999999'), finance_user(array('administrator')));
	},
	'Generic operations must reject commission/refund fields.'
);
finance_assert_throws(
	'Use the Commissions & Refunds workspace to update commission or refund records.',
	static function () use ($assert_operations, $plugin) {
		$assert_operations->invoke($plugin, array('refundPaymentReference' => 'BYPASS'), finance_user(array('administrator')));
	},
	'Generic operations must reject every refund-prefixed field, including future settlement fields.'
);

finance_reset();
finance_assert_throws(
	'Finance action is invalid.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'unknown-action', array('status' => 'approved'));
	},
	'The command layer must reject invalid actions even when invoked outside REST.'
);

// Commission validation, canonical money, CAS, audit, and response parity.
finance_reset();
$case = finance_invoke($finance_command, 'commission', array(
	'status' => 'pending-approval', 'baseAmount' => '7,000', 'amount' => '700.5',
	'currency' => 'eur', 'dueDate' => '2026-09-01', 'note' => 'Check invoice.',
));
finance_assert_same('7000.00', $GLOBALS['wpdb']->commissions[0]['baseAmount'], 'Commission base must be canonical.');
finance_assert_same('700.50', $GLOBALS['wpdb']->commissions[0]['amount'], 'Commission amount must be canonical.');
finance_assert_same('700.50', $case['commissions'][0]['amount'], 'Case response must expose commission amount.');
finance_assert_same('EUR', $case['commissionCurrency'], 'Case summary must expose commission currency.');
finance_assert_same('finance', $GLOBALS['wpdb']->activities[0]['kind'], 'Commission mutation must write required finance activity.');
$commission_insert = array_values(array_filter($GLOBALS['wpdb']->insert_inputs, static function ($entry) {
	return 'mc_commission_records' === $entry['table'];
}))[0]['data'];
finance_assert_true(!array_key_exists('createdAt', $commission_insert) && !array_key_exists('updatedAt', $commission_insert), 'Commission insert must use DATETIME(3) defaults.');

finance_reset();
finance_assert_throws(
	'Commission amount cannot exceed the tuition base amount.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'commission', array(
			'status' => 'pending-approval', 'baseAmount' => '100.00', 'amount' => '100.01', 'currency' => 'EUR',
		));
	},
	'Commission must not exceed its base.'
);
finance_assert_same(0, count($GLOBALS['wpdb']->commissions), 'Invalid commission must roll back.');

finance_reset();
finance_assert_throws(
	'Commission amount must be a non-negative value with standard thousands separators and no more than two decimal places.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'commission', array(
			'status' => 'pending-approval', 'baseAmount' => '100.00', 'amount' => '007', 'currency' => 'EUR',
		));
	},
	'Ungrouped money with leading zeros must be rejected.'
);

finance_reset();
$not_applicable = finance_invoke($finance_command, 'commission', array(
	'status' => 'not-applicable', 'baseAmount' => '', 'amount' => '', 'currency' => 'EUR', 'dueDate' => '', 'paidDate' => '',
));
finance_assert_same(null, $not_applicable['commissions'][0]['amount'], 'Not-applicable commission must have no amount.');
finance_assert_throws(
	'Commission amounts and dates must be blank when commission is not applicable.',
	static function () use ($finance_command) {
		finance_reset();
		finance_invoke($finance_command, 'commission', array(
			'status' => 'not-applicable', 'baseAmount' => '1', 'amount' => '', 'currency' => 'EUR',
		));
	},
	'Not-applicable commission must reject amounts.'
);

finance_reset();
$GLOBALS['wpdb']->force_stale = true;
finance_assert_throws(
	MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR,
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'commission', array(
			'status' => 'pending-approval', 'baseAmount' => '100', 'amount' => '10', 'currency' => 'EUR',
		));
	},
	'Stale finance command must fail with the shared CAS message.'
);
finance_assert_same(0, count($GLOBALS['wpdb']->commissions), 'Stale commission must not persist.');

finance_reset();
$GLOBALS['wpdb']->fail_required_activity = true;
finance_assert_throws(
	'Unable to record the finance activity.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'commission', array(
			'status' => 'pending-approval', 'baseAmount' => '100', 'amount' => '10', 'currency' => 'EUR',
		));
	},
	'Finance record and required audit activity must commit atomically.'
);
finance_assert_same(0, count($GLOBALS['wpdb']->commissions), 'Finance record must roll back when its required activity fails.');
finance_assert_same('2026-08-20 10:00:00.000', $GLOBALS['wpdb']->application['updatedAt'], 'Application CAS bump must roll back with the failed finance audit.');

finance_reset();
$GLOBALS['wpdb']->throw_required_activity = true;
$response = $plugin->rest_record_finance_workspace(finance_request(array(
	'action' => 'commission',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('status' => 'pending-approval', 'baseAmount' => '100', 'amount' => '10', 'currency' => 'EUR'),
)));
finance_assert_same(400, $response->get_status(), 'Finance REST must contain a Throwable raised after START TRANSACTION.');
finance_assert_same('Offline activity throwable.', $response->get_data()['error'], 'Finance Throwable must return a controlled REST error.');
finance_assert_same(0, count($GLOBALS['wpdb']->commissions), 'Finance Throwable must roll back the record.');
finance_assert_same('2026-08-20 10:00:00.000', $GLOBALS['wpdb']->application['updatedAt'], 'Finance Throwable must roll back the CAS bump.');

finance_reset();
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$response = $plugin->rest_record_finance_workspace(finance_request(array(
	'action' => 'commission',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('status' => 'pending-approval', 'baseAmount' => '100', 'amount' => '10', 'currency' => 'EUR'),
)));
finance_assert_same(200, $response->get_status(), 'Finance mutation must remain successful when its post-commit rich reload fails.');
finance_assert_same(array('ok', 'application'), array_keys($response->get_data()), 'Finance REST success contract must contain only ok and application.');
finance_assert_same(1, count($GLOBALS['wpdb']->commissions), 'Committed finance mutation must not be retried after a reload failure.');
finance_assert_same('2026-08-20T10:00:01.000Z', $response->get_data()['application']['updatedAt'], 'Finance fallback must carry the committed application version.');

// Refund creation and settlement are separate; only approved can settle.
$approved_refund = array(
	'id' => 'refund-approved', 'applicationId' => 'app-finance-offline', 'status' => 'approved',
	'requestedDate' => '2026-08-19', 'amount' => '250.00', 'currency' => 'EUR', 'paidDate' => null,
	'paymentReference' => null, 'reason' => 'Duplicate payment', 'note' => null,
	'createdAt' => '2026-08-19 09:00:00.000', 'updatedAt' => '2026-08-19 09:00:00.000',
);
$legacy_approved_refund = array_merge($approved_refund, array(
	'paidDate' => '2026-08-18',
	'paymentReference' => 'LEGACY-INCONSISTENT',
));
finance_reset(array(), array($legacy_approved_refund));
$revised_refund = finance_invoke($finance_command, 'refund-request', array(
	'refundId' => 'refund-approved', 'status' => 'approved', 'requestedDate' => '2026-08-19',
	'amount' => '250', 'currency' => 'EUR', 'reason' => 'Duplicate payment',
));
finance_assert_same(null, $revised_refund['refunds'][0]['paidDate'], 'Editing a refund request must clear stale settlement date.');
finance_assert_same(null, $revised_refund['refunds'][0]['paymentReference'], 'Editing a refund request must clear stale settlement reference.');

$declined_refund = array_merge($approved_refund, array(
	'id' => 'refund-declined', 'status' => 'declined', 'amount' => '125.00', 'reason' => 'Original declined reason',
));
finance_reset(array(), array($declined_refund));
finance_assert_throws(
	'Refund status cannot change from declined to declined.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'refund-request', array(
			'refundId' => 'refund-declined', 'status' => 'declined', 'requestedDate' => '2026-08-19',
			'amount' => '999', 'currency' => 'EUR', 'reason' => 'Attempted mutation',
		));
	},
	'Declined refund records must be immutable.'
);
finance_assert_same('125.00', $GLOBALS['wpdb']->refunds[0]['amount'], 'Declined refund amount must remain unchanged.');
finance_assert_same('Original declined reason', $GLOBALS['wpdb']->refunds[0]['reason'], 'Declined refund reason must remain unchanged.');

finance_reset(array(), array($approved_refund));
$paid_case = finance_invoke($finance_command, 'refund-payment', array(
	'refundId' => 'refund-approved', 'paidDate' => '2026-08-20', 'paymentReference' => 'BANK-REF-1',
));
finance_assert_same('paid', $GLOBALS['wpdb']->refunds[0]['status'], 'Approved refund must become paid.');
finance_assert_same('BANK-REF-1', $paid_case['refunds'][0]['paymentReference'], 'Case response must expose payment reference.');
finance_assert_same('250.00', $paid_case['refundAmount'], 'Case summary must expose refund amount.');

$requested_refund = array_merge($approved_refund, array('id' => 'refund-requested', 'status' => 'requested'));
finance_reset(array(), array($requested_refund));
finance_assert_throws(
	'Only an approved refund can be recorded as paid.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'refund-payment', array(
			'refundId' => 'refund-requested', 'paidDate' => '2026-08-20', 'paymentReference' => 'NOPE',
		));
	},
	'Unapproved refund must not settle.'
);
finance_assert_same('requested', $GLOBALS['wpdb']->refunds[0]['status'], 'Rejected settlement must roll back.');

$amountless_refund = array_merge($approved_refund, array('id' => 'refund-amountless', 'amount' => null));
finance_reset(array(), array($amountless_refund));
finance_assert_throws(
	'The approved refund does not have an amount to settle.',
	static function () use ($finance_command) {
		finance_invoke($finance_command, 'refund-payment', array(
			'refundId' => 'refund-amountless', 'paidDate' => '2026-08-20', 'paymentReference' => 'NO-AMOUNT',
		));
	},
	'Legacy approved refund without an amount must not settle.'
);

// Communications default to log-only, allow an agent on their own case, and
// only send after commit when sendEmail=true.
finance_reset();
$GLOBALS['mc_finance_current_user'] = finance_wp_user(array('mc_agent'), 42);
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('direction' => 'outbound', 'channel' => 'email', 'subject' => 'Logged only', 'detail' => 'Do not send.'),
)));
finance_assert_same(200, $response->get_status(), 'Owning agent must be able to log a communication.');
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Missing sendEmail must never send.');
finance_assert_true(!array_key_exists('delivery', $response->get_data()), 'Log-only response must not invent a delivery result.');
$communication_insert = array_values(array_filter($GLOBALS['wpdb']->insert_inputs, static function ($entry) {
	return 'mc_admission_communications' === $entry['table'];
}))[0]['data'];
finance_assert_true(!array_key_exists('createdAt', $communication_insert), 'Communication insert must use its DATETIME(3) default.');

finance_reset();
$GLOBALS['mc_finance_current_user'] = finance_wp_user(array('mc_agent'), 99);
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('direction' => 'internal', 'channel' => 'portal', 'detail' => 'Wrong owner.'),
)));
finance_assert_same(403, $response->get_status(), 'An agent must receive 403 when attempting to log against another agency case.');
finance_assert_same(0, count($GLOBALS['wpdb']->communications), 'Unauthorized communication must not persist.');

finance_reset();
$GLOBALS['wpdb']->fail_required_activity = true;
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('direction' => 'internal', 'channel' => 'portal', 'detail' => 'Atomic audit.'),
)));
finance_assert_same(400, $response->get_status(), 'Communication must fail when its required activity cannot be written.');
finance_assert_same(0, count($GLOBALS['wpdb']->communications), 'Communication must roll back with its required activity.');
finance_assert_same('2026-08-20 10:00:00.000', $GLOBALS['wpdb']->application['updatedAt'], 'Communication CAS bump must roll back with its activity.');

finance_reset();
$GLOBALS['wpdb']->throw_required_activity = true;
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('direction' => 'internal', 'channel' => 'portal', 'detail' => 'Throwable rollback.'),
)));
finance_assert_same(400, $response->get_status(), 'Communication REST must contain a Throwable raised after START TRANSACTION.');
finance_assert_same(0, count($GLOBALS['wpdb']->communications), 'Communication Throwable must roll back the log entry.');
finance_assert_same('2026-08-20 10:00:00.000', $GLOBALS['wpdb']->application['updatedAt'], 'Communication Throwable must roll back the CAS bump.');

finance_reset();
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => array('direction' => 'internal', 'channel' => 'portal', 'detail' => str_repeat('x', 4001)),
)));
finance_assert_same(400, $response->get_status(), 'Communication detail must enforce the shared 4,000 character cap.');

finance_reset();
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z', 'sendEmail' => true,
	'draft' => array('direction' => 'outbound', 'channel' => 'email', 'subject' => 'Finance follow-up', 'detail' => 'Please review the finance update.'),
)));
finance_assert_same(200, $response->get_status(), 'Explicit agency email must succeed.');
$delivery = $response->get_data()['delivery'];
finance_assert_same(true, $delivery['ok'], 'Delivery summary must report success.');
finance_assert_same(1, $delivery['sentCount'], 'Delivery summary must expose sent count.');
finance_assert_same(0, $delivery['failedCount'], 'Delivery summary must expose failed count.');
finance_assert_true(!array_key_exists('sent', $delivery) && !array_key_exists('failed', $delivery), 'REST must not expose recipient arrays.');
finance_assert_same(array('agency@example.invalid'), $GLOBALS['mc_finance_mail_calls'][0]['to'], 'Email must use current owning WordPress agency email.');
finance_assert_true('student@example.invalid' !== $GLOBALS['mc_finance_mail_calls'][0]['to'][0], 'Student must never receive the finance communication.');
$first_commit = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$mail_event = array_search('mail:Finance follow-up', $GLOBALS['wpdb']->events, true);
finance_assert_true(false !== $first_commit && false !== $mail_event && $first_commit < $mail_event, 'Communication must commit before wp_mail.');

finance_reset();
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z', 'sendEmail' => true,
	'draft' => array('direction' => 'inbound', 'channel' => 'phone', 'subject' => 'Invalid send', 'detail' => 'No email.'),
)));
finance_assert_same(400, $response->get_status(), 'Explicit send must require outbound email.');
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Invalid explicit send must not call wp_mail.');

finance_reset(array('isTestData' => 1));
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z', 'sendEmail' => true,
	'draft' => array('direction' => 'outbound', 'channel' => 'email', 'subject' => 'Silent test', 'detail' => 'No live email.'),
)));
finance_assert_same(200, $response->get_status(), 'Test-data communication must still be recorded.');
finance_assert_same(true, $response->get_data()['delivery']['skipped'], 'Test-data email must report skipped.');
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Test-data email must be silent.');

// Post-commit reload, mail, and delivery-audit failures cannot turn a committed
// write into an error that invites a duplicate retry.
finance_reset();
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$GLOBALS['wpdb']->fail_delivery_audit = true;
$GLOBALS['wpdb']->throw_rollback = true;
$GLOBALS['mc_finance_mail_result'] = false;
$response = $plugin->rest_record_finance_communication(finance_request(array(
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z', 'sendEmail' => true,
	'draft' => array('direction' => 'outbound', 'channel' => 'email', 'subject' => 'Contained failure', 'detail' => 'Persist this once.'),
)));
finance_assert_same(200, $response->get_status(), 'Post-commit failures must return a successful command response.');
finance_assert_same(1, count($GLOBALS['wpdb']->communications), 'Committed communication must survive audit rollback.');
finance_assert_same(false, $response->get_data()['delivery']['ok'], 'Mail failure must be visible.');
finance_assert_same(1, $response->get_data()['delivery']['failedCount'], 'A rejected delivery attempt must count one failed recipient.');
finance_assert_same(false, $response->get_data()['delivery']['audit']['ok'], 'Audit failure must be visible and contained.');
finance_assert_same('2026-08-20T10:00:01.000Z', $response->get_data()['application']['updatedAt'], 'Fallback response must carry the committed application version.');

// Internal note routing never targets the agency: commission -> Finance only;
// refund -> Finance + Admissions, with the actor role excluded.
finance_reset();
finance_invoke($finance_command, 'commission', array(
	'status' => 'pending-approval', 'baseAmount' => '1000', 'amount' => '100', 'currency' => 'EUR', 'note' => 'Commission note marker.',
));
finance_assert_same(array('finance-officer@example.invalid'), $GLOBALS['mc_finance_mail_calls'][0]['to'], 'Commission note must notify Finance only.');
$finance_commit = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$finance_note_mail = array_search('mail:Commission note updated for Finance Student (MC-FINANCE1)', $GLOBALS['wpdb']->events, true);
finance_assert_true(false !== $finance_commit && false !== $finance_note_mail && $finance_commit < $finance_note_mail, 'Finance note email must run only after the finance transaction commits.');

finance_reset();
finance_invoke($finance_command, 'refund-request', array(
	'status' => 'requested', 'requestedDate' => '2026-08-20', 'amount' => '50', 'currency' => 'EUR',
	'reason' => 'Duplicate payment', 'note' => 'Refund note marker.',
));
$note_recipients = array_map(static function ($mail) { return $mail['to'][0]; }, $GLOBALS['mc_finance_mail_calls']);
sort($note_recipients);
finance_assert_same(array('admissions-officer@example.invalid', 'finance-officer@example.invalid'), $note_recipients, 'Refund note must notify Finance and Admissions only.');
finance_assert_true(!in_array('agency@example.invalid', $note_recipients, true), 'Finance save must never notify the agency.');

finance_reset();
finance_invoke(
	$finance_command,
	'commission',
	array('status' => 'pending-approval', 'baseAmount' => '1000', 'amount' => '100', 'currency' => 'EUR', 'note' => 'Actor filtered.'),
	finance_user(array('finance-officer'))
);
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Finance actor must be excluded from the only commission-note recipient role.');

finance_reset(array('isTestData' => 1));
finance_invoke($finance_command, 'refund-request', array(
	'status' => 'requested', 'requestedDate' => '2026-08-20', 'amount' => '50', 'currency' => 'EUR',
	'reason' => 'Offline test refund', 'note' => 'Never deliver this test note.',
));
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Test-data finance notes must never send role email.');

// Letter working drafts use the same WordPress-only, version-checked command
// contract as the packaged desktop and web clients. They never send email.
$letter_routes = array_values(array_filter($GLOBALS['mc_finance_routes'], static function ($route) {
	return '/applications/(?P<application_id>[A-Za-z0-9_-]+)/letters' === $route['route'];
}));
finance_assert_same(1, count($letter_routes), 'The shared letters route must be registered once.');
finance_assert_same('PATCH', $letter_routes[0]['args'][1]['methods'], 'Letter drafts must expose PATCH beside generated-letter POST.');
finance_assert_same(array($plugin, 'rest_update_admission_letter_draft'), $letter_routes[0]['args'][1]['callback'], 'Letter draft PATCH must use its dedicated handler.');

finance_reset();
$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'save', 'body' => "First line\r\nSecond line",
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
finance_assert_same(200, $response->get_status(), 'Administrator must be able to save an offer draft.');
finance_assert_same("First line\nSecond line", $GLOBALS['wpdb']->letter_drafts[0]['body'], 'Draft persistence must normalize line endings.');
finance_assert_same('draft', $response->get_data()['draft']['status'], 'A save action must persist draft status.');
finance_assert_same('letter', $GLOBALS['wpdb']->activities[0]['kind'], 'Draft persistence must write its required letter audit.');
finance_assert_same('2026-08-20T10:00:01.000Z', $response->get_data()['application']['updatedAt'], 'Draft persistence must return the committed case revision.');
finance_assert_same(0, count($GLOBALS['mc_finance_mail_calls']), 'Letter draft actions must never send email.');

$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'review', 'body' => 'Reviewed wording.',
	'expectedUpdatedAt' => '2026-08-20T10:00:01.000Z',
)));
finance_assert_same(200, $response->get_status(), 'Administrator must be able to review an existing draft.');
finance_assert_same('reviewed', $response->get_data()['draft']['status'], 'Review must persist reviewed status.');
finance_assert_same('Offline User', $response->get_data()['draft']['reviewedByName'], 'Review must persist the reviewing user.');

$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'approve', 'body' => 'Approved wording.',
	'expectedUpdatedAt' => '2026-08-20T10:00:02.000Z',
)));
finance_assert_same(200, $response->get_status(), 'Administrator must be able to approve an existing draft.');
finance_assert_same('approved', $response->get_data()['draft']['status'], 'Approval must persist approved status.');
finance_assert_same('Offline User', $response->get_data()['draft']['approvedByName'], 'Approval must persist the approving user.');

finance_reset();
$GLOBALS['wpdb']->force_stale = true;
$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'save', 'body' => 'Must roll back.',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
finance_assert_same(409, $response->get_status(), 'A concurrent letter-draft write must return HTTP 409.');
finance_assert_same(MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR, $response->get_data()['error'], 'Letter-draft CAS must use the shared stale message.');
finance_assert_same(0, count($GLOBALS['wpdb']->letter_drafts), 'A stale letter draft must roll back fully.');
finance_assert_same(0, count($GLOBALS['wpdb']->activities), 'A stale letter draft must not leave an audit row.');

finance_reset();
$GLOBALS['mc_finance_current_user'] = finance_wp_user(array('mc_agent'), 42);
$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'save', 'body' => 'Forged agent edit.',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
finance_assert_same(403, $response->get_status(), 'An owning agency user must not gain letter-draft permissions.');
finance_assert_same(0, count($GLOBALS['wpdb']->letter_drafts), 'A forbidden letter-draft request must not persist.');

finance_reset();
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$response = $plugin->rest_update_admission_letter_draft(finance_request(array(
	'templateId' => 'offer-letter', 'action' => 'reset', 'body' => 'Resolved current default body.',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
finance_assert_same(200, $response->get_status(), 'A committed letter draft must remain successful when the rich reload fails.');
finance_assert_same('Resolved current default body.', $response->get_data()['draft']['body'], 'The post-commit fallback must return the committed draft.');
finance_assert_same('2026-08-20T10:00:01.000Z', $response->get_data()['application']['updatedAt'], 'The draft fallback must carry the committed application version.');

// Upgrade bootstrap creates all tables and evolves the refund settlement column
// before recording the schema version.
unset($GLOBALS['mc_finance_options']['mc_admissions_finance_workspace_schema_version']);
$GLOBALS['wpdb']->known_tables = array_values(array_diff(
	$GLOBALS['wpdb']->known_tables,
	array('mc_admission_communications', 'mc_commission_records', 'mc_refund_records')
));
$GLOBALS['wpdb']->refund_payment_reference_column = false;
$ensure_schema->invoke($plugin);
finance_assert_true(in_array('mc_admission_communications', $GLOBALS['wpdb']->known_tables, true), 'Schema bootstrap must create communications.');
finance_assert_true(in_array('mc_commission_records', $GLOBALS['wpdb']->known_tables, true), 'Schema bootstrap must create commissions.');
finance_assert_true(in_array('mc_refund_records', $GLOBALS['wpdb']->known_tables, true), 'Schema bootstrap must create refunds.');
finance_assert_same(true, $GLOBALS['wpdb']->refund_payment_reference_column, 'Schema bootstrap must add paymentReference.');
finance_assert_same('0.2.61', $GLOBALS['mc_finance_options']['mc_admissions_finance_workspace_schema_version'], 'Schema version must be recorded last.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
finance_assert_contains('Version: 0.2.62', $plugin_source, 'Plugin header must advertise 0.2.62.');
finance_assert_contains('ORDER BY commission.updatedAt DESC, commission.createdAt DESC, commission.id DESC', $plugin_source, 'Commission latest reads need deterministic ordering.');
finance_assert_contains('ORDER BY refund.updatedAt DESC, refund.createdAt DESC, refund.id DESC', $plugin_source, 'Refund latest reads need deterministic ordering.');

fwrite(STDOUT, "Finance workspace tests passed.\n");
