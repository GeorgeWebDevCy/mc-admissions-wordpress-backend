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

final class MC_Pending_Message_Test_Role {
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

final class MC_Pending_Message_Test_Wpdb {
	public $application = array();
	public $activities = array();
	public $communications = array();
	public $events = array();
	public $force_stale = false;
	public $fail_authoritative_read_after_commit = false;
	public $fail_rich_read_after_commit = false;
	public $audit_insert_false_tables = array();
	public $audit_insert_exception_tables = array();
	private $committed = false;
	private $transaction_snapshot = null;

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) {
			$args = array_values($args[0]);
		}

		return array(
			'query' => (string) $query,
			'args' => array_values($args),
		);
	}

	public function esc_like($value) {
		return addcslashes((string) $value, '_%\\');
	}

	private function unpack($prepared) {
		return is_array($prepared)
			? $prepared
			: array('query' => (string) $prepared, 'args' => array());
	}

	public function get_row($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$this->events[] = 'get_row:' . trim(preg_replace('/\s+/', ' ', $query));

		if (false !== strpos($query, 'FROM mc_admission_applications')) {
			if (
				$this->fail_authoritative_read_after_commit
				&& $this->committed
				&& false !== strpos($query, 'SELECT id, referenceCode, wordpressUserId')
			) {
				throw new RuntimeException('Offline post-commit authoritative review read failure.');
			}
			if ($this->fail_rich_read_after_commit && $this->committed && false !== strpos($query, 'SELECT *')) {
				throw new RuntimeException('Offline post-commit rich case read failure.');
			}
			if (false !== strpos($query, 'SELECT status, reviewerDecision, workflowNote')) {
				return array(
					'status' => $this->application['status'],
					'reviewerDecision' => $this->application['reviewerDecision'],
					'workflowNote' => $this->application['workflowNote'],
				);
			}

			return $this->application;
		}

		return null;
	}

	public function get_results($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$this->events[] = 'get_results:' . trim(preg_replace('/\s+/', ' ', $query));

		if (false !== strpos($query, 'FROM mc_admission_activities')) {
			return array_reverse($this->activities);
		}
		if (false !== strpos($query, 'FROM mc_admission_communications')) {
			return array_reverse($this->communications);
		}

		return array();
	}

	public function get_var($prepared) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$args = $call['args'];
		$this->events[] = 'get_var:' . trim(preg_replace('/\s+/', ' ', $query));

		if (false !== strpos($query, 'SHOW TABLES LIKE')) {
			$table = isset($args[0]) ? (string) $args[0] : '';
			return 'mc_admission_communications' === $table ? $table : null;
		}

		return null;
	}

	public function query($prepared) {
		$call = $this->unpack($prepared);
		$query = trim($call['query']);
		$args = $call['args'];
		$this->events[] = 'query:' . trim(preg_replace('/\s+/', ' ', $query));

		if ('START TRANSACTION' === $query) {
			$this->committed = false;
			$this->transaction_snapshot = array(
				'application' => $this->application,
				'activities' => $this->activities,
				'communications' => $this->communications,
			);
			return 1;
		}
		if ('COMMIT' === $query) {
			$this->committed = true;
			$this->transaction_snapshot = null;
			return 1;
		}
		if ('ROLLBACK' === $query) {
			if (is_array($this->transaction_snapshot)) {
				$this->application = $this->transaction_snapshot['application'];
				$this->activities = $this->transaction_snapshot['activities'];
				$this->communications = $this->transaction_snapshot['communications'];
			}
			$this->committed = false;
			$this->transaction_snapshot = null;
			return 1;
		}

		if (0 !== strpos($query, 'UPDATE mc_admission_applications SET ')) {
			return 1;
		}

		if ($this->force_stale && false !== strpos($query, 'AND updatedAt = %s')) {
			return 0;
		}

		if (!preg_match('/ SET (.+) WHERE id = %s/s', $query, $matches)) {
			throw new RuntimeException('Unable to parse the offline application update.');
		}

		$arg_index = 0;
		foreach (preg_split('/,\s*/', trim($matches[1])) as $assignment) {
			if (preg_match('/^([A-Za-z0-9_]+) = %[sd]$/', $assignment, $column_match)) {
				$this->application[$column_match[1]] = $args[$arg_index++];
				continue;
			}
			if (preg_match('/^([A-Za-z0-9_]+) = NULL$/', $assignment, $column_match)) {
				$this->application[$column_match[1]] = null;
				continue;
			}
			if ('updatedAt = CURRENT_TIMESTAMP(3)' === $assignment) {
				$this->application['updatedAt'] = '2026-08-11 10:00:01.000';
				continue;
			}
			throw new RuntimeException('Unexpected offline update assignment: ' . $assignment);
		}

		$application_id = isset($args[$arg_index]) ? (string) $args[$arg_index] : '';
		if ($application_id !== (string) $this->application['id']) {
			return 0;
		}

		return 1;
	}

	public function insert($table, $data, $format = null) {
		$this->events[] = 'insert:' . $table;
		$is_email_audit = 'mc_admission_communications' === $table
			|| ('mc_admission_activities' === $table && isset($data['kind']) && 'communication' === $data['kind']);
		if ($is_email_audit && in_array($table, $this->audit_insert_exception_tables, true)) {
			throw new RuntimeException('Offline audit insert exception for ' . $table . '.');
		}
		if ($is_email_audit && in_array($table, $this->audit_insert_false_tables, true)) {
			return false;
		}
		if ('mc_admission_activities' === $table) {
			$this->activities[] = $data;
			return 1;
		}
		if ('mc_admission_communications' === $table) {
			$this->communications[] = $data;
			return 1;
		}

		return 1;
	}

	public function reset(array $application) {
		$this->application = $application;
		$this->activities = array();
		$this->communications = array();
		$this->events = array();
		$this->force_stale = false;
		$this->fail_authoritative_read_after_commit = false;
		$this->fail_rich_read_after_commit = false;
		$this->audit_insert_false_tables = array();
		$this->audit_insert_exception_tables = array();
		$this->committed = false;
		$this->transaction_snapshot = null;
	}
}

$GLOBALS['wpdb'] = new MC_Pending_Message_Test_Wpdb();
$GLOBALS['mc_pending_roles'] = array();
$GLOBALS['mc_pending_routes'] = array();
$GLOBALS['mc_pending_mail_calls'] = array();
$GLOBALS['mc_pending_mail_result'] = true;
$GLOBALS['mc_pending_mail_exception'] = null;
$GLOBALS['mc_pending_uuid'] = 0;
$GLOBALS['mc_pending_current_user'] = null;
$GLOBALS['mc_pending_identity_exception'] = false;

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_pending_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Pending_Message_Test_Role($label);
	$GLOBALS['mc_pending_roles'][$slug] = $role;
	return $role;
}

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

function register_rest_route($namespace, $route, $args = array(), $override = false) {
	$GLOBALS['mc_pending_routes'][] = array(
		'namespace' => $namespace,
		'route' => $route,
		'args' => $args,
	);
	return true;
}

function sanitize_email($email) {
	return trim((string) $email);
}

function is_email($email) {
	return false !== filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function sanitize_key($value) {
	return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value));
}

function sanitize_textarea_field($value) {
	return trim(strip_tags((string) $value));
}

function wp_strip_all_tags($value, $remove_breaks = false) {
	return strip_tags((string) $value);
}

function esc_html($value) {
	return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function wp_generate_uuid4() {
	$GLOBALS['mc_pending_uuid']++;
	return 'offline-pending-' . $GLOBALS['mc_pending_uuid'];
}

function current_time($type, $gmt = false) {
	return '2026-08-11 10:00:02';
}

function wp_get_current_user() {
	return $GLOBALS['mc_pending_current_user'];
}

function get_avatar_url($user_id, $args = array()) {
	return '';
}

function wp_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
	$GLOBALS['mc_pending_mail_calls'][] = array(
		'to' => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
		'attachments' => $attachments,
	);
	$GLOBALS['wpdb']->events[] = 'mail:' . $subject;
	if ($GLOBALS['mc_pending_mail_exception'] instanceof Throwable) {
		throw $GLOBALS['mc_pending_mail_exception'];
	}
	return (bool) $GLOBALS['mc_pending_mail_result'];
}

function get_userdata($user_id) {
	if (!empty($GLOBALS['mc_pending_identity_exception'])) {
		throw new RuntimeException('Offline authoritative agency identity failure.');
	}
	return false;
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function pending_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function pending_assert_true($actual, $message) {
	if (!$actual) {
		throw new RuntimeException($message);
	}
}

function pending_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function pending_assert_not_contains($needle, $haystack, $message) {
	if (false !== strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function pending_assert_throws_message($expected, $callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		pending_assert_same($expected, $error->getMessage(), $message);
		return;
	}

	throw new RuntimeException($message . ' No exception was thrown.');
}

function pending_find_activity($title) {
	foreach ($GLOBALS['wpdb']->activities as $activity) {
		if (isset($activity['title']) && (string) $activity['title'] === (string) $title) {
			return $activity;
		}
	}

	return null;
}


function pending_find_communication($subject) {
	foreach ($GLOBALS['wpdb']->communications as $communication) {
		if (isset($communication['subject']) && (string) $communication['subject'] === (string) $subject) {
			return $communication;
		}
	}

	return null;
}

function pending_application(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'app-pending-offline',
			'referenceCode' => 'MC-PENDING1',
			'wordpressUserId' => 42,
			'wordpressUsername' => 'origin-agent',
			'wordpressEmail' => 'owner@example.invalid',
			'fullName' => 'Offline Student',
			'passportNumber' => 'OFFLINE',
			'email' => 'student@example.invalid',
			'phone' => '+000000000',
			'birthday' => '2000-01-01',
			'address' => 'Offline address',
			'city' => 'Offline city',
			'postalCode' => '0000',
			'country' => 'Offline country',
			'gender' => 'Other',
			'programmeCode' => 'business-administration',
			'programmeLabel' => 'Business Administration',
			'semester' => 'fall',
			'year' => '2026',
			'applicationRoute' => 'standard',
			'agencyName' => 'Offline Agency',
			'consultantName' => 'Origin Consultant',
			'consultantEmail' => 'consultant@example.invalid',
			'consultantPhone' => null,
			'submissionDate' => '2026-08-11',
			'tuitionAcknowledged' => 1,
			'offerTermsAcknowledged' => 1,
			'gdprAcknowledged' => 1,
			'isTestData' => 0,
			'status' => 'review-pending',
			'workflowNote' => 'Queued for review.',
			'reviewerDecision' => 'pending',
			'reviewSummary' => null,
			'decisionDueDate' => null,
			'offerIssuedDate' => null,
			'offerExpiryDate' => null,
			'offerConditionNote' => null,
			'classesStartDate' => null,
			'tuitionFeeFirstYear' => null,
			'tuitionFeeFollowingYears' => null,
			'termBalanceApplies' => 0,
			'paymentStatus' => 'awaiting-invoice',
			'paymentAmount' => null,
			'paymentCurrency' => 'EUR',
			'paymentReference' => null,
			'paymentConfirmedDate' => null,
			'financeNote' => null,
			'permitStatus' => 'not-started',
			'permitReference' => null,
			'permitSubmittedDate' => null,
			'permitDecisionDate' => null,
			'permitNote' => null,
			'arrivalStatus' => 'planning',
			'travelDate' => null,
			'accommodationStatus' => null,
			'enrollmentStatus' => 'pending',
			'orientationDate' => null,
			'enrollmentNote' => null,
			'lateArrivalReason' => null,
			'lastUpdatedByName' => 'Origin Agent',
			'createdAt' => '2026-08-11 09:00:00.000',
			'updatedAt' => '2026-08-11 10:00:00.000',
		),
		$overrides
	);
}

function pending_wp_user(array $roles) {
	return (object) array(
		'ID' => 7,
		'user_login' => 'offline-user',
		'display_name' => 'Offline User',
		'user_email' => 'staff@example.invalid',
		'roles' => $roles,
		'allcaps' => array(),
	);
}

function pending_internal_user(array $roles = array('admissions-officer')) {
	return array(
		'id' => 7,
		'username' => 'admissions-offline',
		'name' => 'Admissions Officer',
		'email' => 'staff@example.invalid',
		'roles' => $roles,
	);
}

function pending_request(array $json_params) {
	return new WP_REST_Request(
		array('application_id' => 'app-pending-offline'),
		$json_params
	);
}

function reset_pending_case(array $overrides = array()) {
	$GLOBALS['wpdb']->reset(pending_application($overrides));
	$GLOBALS['mc_pending_mail_calls'] = array();
	$GLOBALS['mc_pending_mail_result'] = true;
	$GLOBALS['mc_pending_mail_exception'] = null;
	$GLOBALS['mc_pending_identity_exception'] = false;
	$GLOBALS['mc_pending_current_user'] = pending_wp_user(array('admissions-officer'));
}

function invoke_pending_command($method, $expected_updated_at = '2026-08-11T10:00:00.000Z', $message = 'Please upload the corrected evidence.') {
	return $method->invoke(
		mc_admissions_wordpress_backend(),
		array(
			'applicationId' => 'app-pending-offline',
			'message' => $message,
			'expectedUpdatedAt' => $expected_updated_at,
			'user' => pending_internal_user(),
		)
	);
}

function invoke_rejection_command($method, $expected_updated_at = '2026-08-11T10:00:00.000Z', $reason = 'The entry requirements were not met.') {
	return $method->invoke(
		mc_admissions_wordpress_backend(),
		array(
			'applicationId' => 'app-pending-offline',
			'reason' => $reason,
			'expectedUpdatedAt' => $expected_updated_at,
			'user' => pending_internal_user(),
		)
	);
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$pending_command = $reflection->getMethod('send_pending_review_message');
$pending_command->setAccessible(true);
$rejection_command = $reflection->getMethod('reject_review_application');
$rejection_command->setAccessible(true);
$can_assess = $reflection->getMethod('can_assess_admission_documents');
$can_assess->setAccessible(true);
$can_view_history = $reflection->getMethod('can_view_assessment_message_history');
$can_view_history->setAccessible(true);
$get_history = $reflection->getMethod('get_assessment_message_history');
$get_history->setAccessible(true);
$normalize_manual_communication = $reflection->getMethod('normalize_finance_communication_draft');
$normalize_manual_communication->setAccessible(true);

// The route must be an authenticated POST with a separate application-scoped callback.
$plugin->register_rest_routes();
$pending_routes = array_values(
	array_filter(
		$GLOBALS['mc_pending_routes'],
		function ($route) {
			return '/applications/(?P<application_id>[A-Za-z0-9_-]+)/pending-message' === $route['route'];
		}
	)
);
pending_assert_same(1, count($pending_routes), 'The pending-message REST route must be registered exactly once.');
$pending_route = $pending_routes[0]['args'];
pending_assert_same(WP_REST_Server::CREATABLE, $pending_route['methods'], 'The pending-message route must use POST.');
pending_assert_same(array($plugin, 'rest_send_pending_review_message'), $pending_route['callback'], 'The route must use the dedicated handler.');
pending_assert_same(array($plugin, 'permission_authenticated'), $pending_route['permission_callback'], 'The route must require an authenticated session.');

$rejection_routes = array_values(
	array_filter(
		$GLOBALS['mc_pending_routes'],
		function ($route) {
			return '/applications/(?P<application_id>[A-Za-z0-9_-]+)/rejection' === $route['route'];
		}
	)
);
pending_assert_same(1, count($rejection_routes), 'The rejection REST route must be registered exactly once.');
$rejection_route = $rejection_routes[0]['args'];
pending_assert_same(WP_REST_Server::CREATABLE, $rejection_route['methods'], 'The rejection route must use POST.');
pending_assert_same(array($plugin, 'rest_reject_review_application'), $rejection_route['callback'], 'The rejection route must use the dedicated handler.');
pending_assert_same(array($plugin, 'permission_authenticated'), $rejection_route['permission_callback'], 'The rejection route must require an authenticated session.');

pending_assert_same(true, $can_assess->invoke($plugin, pending_internal_user(array('administrator'))), 'Administrators must be able to send pending-review messages.');
pending_assert_same(true, $can_assess->invoke($plugin, pending_internal_user(array('admissions-officer'))), 'Admissions Officers must be able to send pending-review messages.');
pending_assert_same(false, $can_assess->invoke($plugin, pending_internal_user(array('finance-officer'))), 'Finance Officers must not use the admissions review action.');
pending_assert_same(false, $can_assess->invoke($plugin, pending_internal_user(array('mc_agent'))), 'Agents must not send pending-review messages through the staff action.');
pending_assert_same(true, $can_view_history->invoke($plugin, pending_internal_user(array('administrator'))), 'Administrators must see popup history.');
pending_assert_same(true, $can_view_history->invoke($plugin, pending_internal_user(array('admissions-officer'))), 'Admissions Officers must see popup history.');
pending_assert_same(true, $can_view_history->invoke($plugin, pending_internal_user(array('migration-officer'))), 'Migration Officers must see popup history.');
pending_assert_same(false, $can_view_history->invoke($plugin, pending_internal_user(array('finance-officer'))), 'Finance Officers must not see assessment popup history.');
pending_assert_same(false, $can_view_history->invoke($plugin, pending_internal_user(array('mc_agent'))), 'Agents must not see internal assessment popup history.');

// Existing legacy email-audit rows remain readable as exact history, while
// unrelated case communication is not exposed through this restricted view.
reset_pending_case();
$GLOBALS['wpdb']->communications = array(
	array(
		'id' => 'legacy-pending',
		'applicationId' => 'app-pending-offline',
		'direction' => 'outbound',
		'channel' => 'email',
		'subject' => 'Additional information required for Offline Student (MC-PENDING1)',
		'detail' => "First line.\nSecond line.\nRecipient: Origin Consultant (consultant@example.invalid).\nEmail delivery: sent to 1 recipient(s).",
		'actorName' => 'Admissions Officer',
		'createdAt' => '2026-08-10 10:00:00',
	),
	array(
		'id' => 'standard-rejected',
		'applicationId' => 'app-pending-offline',
		'direction' => 'internal',
		'channel' => 'portal',
		'subject' => '[Assessment message: rejected]',
		'detail' => "Most recent exact reason.\nRecipient: this is staff-entered text.\nEmail delivery: this is also staff-entered text.",
		'actorName' => 'Administrator',
		'createdAt' => '2026-08-11 10:00:00',
	),
	array(
		'id' => 'manual-legacy-subject-collision',
		'applicationId' => 'app-pending-offline',
		'direction' => 'outbound',
		'channel' => 'email',
		'subject' => 'Additional information required for a manually logged case',
		'detail' => 'This ordinary manual message has no legacy delivery-audit suffix.',
		'actorName' => 'Administrator',
		'createdAt' => '2026-08-11 11:00:00',
	),
	array(
		'id' => 'wrong-shape-standard-subject-collision',
		'applicationId' => 'app-pending-offline',
		'direction' => 'outbound',
		'channel' => 'email',
		'subject' => '[Assessment message: pending]',
		'detail' => "A manual collision.\nRecipient: Someone.\nEmail delivery: logged only.",
		'actorName' => 'Administrator',
		'createdAt' => '2026-08-11 12:00:00',
	),
	array(
		'id' => 'unrelated',
		'applicationId' => 'app-pending-offline',
		'direction' => 'internal',
		'channel' => 'portal',
		'subject' => 'General case note',
		'detail' => 'Must not appear.',
		'actorName' => 'Administrator',
		'createdAt' => '2026-08-12 10:00:00',
	),
);
$legacy_history = $get_history->invoke($plugin, 'app-pending-offline', pending_internal_user(array('migration-officer')));
pending_assert_same(2, count($legacy_history), 'Migration history must include standardized and legacy assessment messages only.');
pending_assert_same('rejected', $legacy_history[0]['kind'], 'History must be newest first.');
pending_assert_same(
	"Most recent exact reason.\nRecipient: this is staff-entered text.\nEmail delivery: this is also staff-entered text.",
	$legacy_history[0]['message'],
	'Standardized history must retain exact text even when staff type legacy-looking marker lines.'
);
pending_assert_same("First line.\nSecond line.", $legacy_history[1]['message'], 'Legacy history must strip recipient and delivery audit suffixes only.');
pending_assert_same(array(), $get_history->invoke($plugin, 'app-pending-offline', pending_internal_user(array('finance-officer'))), 'Unauthorized roles must receive no history data.');

foreach (
	array(
		'[Assessment message: pending]',
		'[Assessment message: rejected] attempted manual suffix',
		'Additional information required for a manually logged case',
		'Application closed after review for a manually logged case',
	) as $reserved_subject
) {
	pending_assert_throws_message(
		'This communication subject is reserved for assessment history.',
		function () use ($normalize_manual_communication, $plugin, $reserved_subject) {
			$normalize_manual_communication->invoke(
				$plugin,
				array(
					'direction' => 'internal',
					'channel' => 'portal',
					'subject' => $reserved_subject,
					'detail' => 'A manual communication must not impersonate assessment history.',
				),
				false
			);
		},
		'Reserved assessment subjects must be rejected for manual communications.'
	);
}

// Handler validation rejects malformed input before session or database work.
$response = $plugin->rest_send_pending_review_message(pending_request(array('message' => '  ', 'expectedUpdatedAt' => 'version')));
pending_assert_same(400, $response->get_status(), 'An empty message must be rejected.');
pending_assert_same('A message to the agent is required.', $response->get_data()['error'], 'The empty-message error must be explicit.');

$response = $plugin->rest_send_pending_review_message(pending_request(array('message' => str_repeat('x', 4001), 'expectedUpdatedAt' => 'version')));
pending_assert_same(400, $response->get_status(), 'A message over 4,000 characters must be rejected.');
pending_assert_same('The message must be 4,000 characters or fewer.', $response->get_data()['error'], 'The length error must state the limit.');

$response = $plugin->rest_send_pending_review_message(pending_request(array('message' => 'Need corrected evidence.')));
pending_assert_same(400, $response->get_status(), 'A missing application version must be rejected.');
pending_assert_same('Application version is required.', $response->get_data()['error'], 'The version error must be explicit.');

reset_pending_case();
$GLOBALS['mc_pending_current_user'] = pending_wp_user(array('mc_agent'));
$response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => 'Agent must not send this.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(403, $response->get_status(), 'An agent must receive a permission error.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A denied request must not call wp_mail.');
pending_assert_same(0, count($GLOBALS['wpdb']->events), 'A denied request must not touch application data.');

// Happy path: persist the hold and exact popup text in one transaction, then
// send exactly that message to consultantEmail and record delivery separately.
reset_pending_case();
$typed_message = 'Please upload the corrected bank confirmation before we continue.';
$response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => $typed_message, 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $response->get_status(), 'A valid Admissions request must succeed.');
$response_data = $response->get_data();
pending_assert_same(true, $response_data['ok'], 'The response must mark the command successful.');
pending_assert_same('review-pending', $response_data['application']['stageKey'], 'Pending must keep the case in the review queue.');
pending_assert_same('hold', $GLOBALS['wpdb']->application['reviewerDecision'], 'Pending must save reviewerDecision=hold.');
pending_assert_same(
	array('ok' => true, 'skipped' => false, 'sentCount' => 1, 'failedCount' => 0, 'error' => null),
	$response_data['delivery'],
	'The response must expose an exact successful delivery summary.'
);
pending_assert_same(
	array(
		'ok' => true,
		'skipped' => false,
		'communicationRecorded' => true,
		'activityRecorded' => true,
		'error' => null,
	),
	$response_data['audit'],
	'The response must confirm both durable email-audit records.'
);
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'The action must send exactly one email.');
$mail = $GLOBALS['mc_pending_mail_calls'][0];
pending_assert_same(array('consultant@example.invalid'), $mail['to'], 'The email must target consultantEmail only.');
pending_assert_same('Additional information required for Offline Student (MC-PENDING1)', $mail['subject'], 'The subject must identify the application.');
pending_assert_contains($typed_message, $mail['message'], 'The email body must contain the exact typed message.');
pending_assert_true(in_array('Reply-To: Offline User <staff@example.invalid>', $mail['headers'], true), 'The acting staff member must be Reply-To.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A successful send must retain history plus a sanitized email-delivery audit row.');
$pending_history_row = $GLOBALS['wpdb']->communications[0];
pending_assert_same('internal', $pending_history_row['direction'], 'Assessment popup history must be internal, not an outbound communication item.');
pending_assert_same('portal', $pending_history_row['channel'], 'Assessment popup history must use the portal channel.');
pending_assert_same('[Assessment message: pending]', $pending_history_row['subject'], 'Pending history must use the bounded reserved marker as its entire subject.');
pending_assert_true(strlen($pending_history_row['subject']) <= 191, 'Pending history subject must fit the database column independently of applicant name length.');
pending_assert_same($typed_message, $pending_history_row['detail'], 'The history row must contain only the exact popup message.');
$pending_delivery_communication = pending_find_communication('Pending assessment email delivery audit');
pending_assert_true(null !== $pending_delivery_communication, 'The email result must be a separate communication visible in Email audit.');
pending_assert_same('outbound', $pending_delivery_communication['direction'], 'The delivery audit communication must be outbound.');
pending_assert_same('email', $pending_delivery_communication['channel'], 'The delivery audit communication must use the email channel.');
pending_assert_contains('Recipient: Origin Consultant (consultant@example.invalid).', $pending_delivery_communication['detail'], 'The delivery communication must identify the consultant.');
pending_assert_contains('Email delivery: sent to 1 recipient(s).', $pending_delivery_communication['detail'], 'The delivery communication must record successful delivery.');
pending_assert_not_contains($typed_message, $pending_delivery_communication['detail'], 'The Email-audit row must never duplicate the restricted popup text.');
pending_assert_same(1, count($response_data['application']['assessmentMessageHistory']), 'The returned case must expose the new history entry.');
pending_assert_same($typed_message, $response_data['application']['assessmentMessageHistory'][0]['message'], 'The returned history must preserve the exact message.');
pending_assert_same(array(), $response_data['application']['communications'], 'Restricted popup history must not duplicate into general case communications.');
$pending_delivery_activity = pending_find_activity('Pending assessment email delivery audit');
pending_assert_true(null !== $pending_delivery_activity, 'The delivery result must be recorded as a separate activity.');
pending_assert_contains('Recipient: Origin Consultant (consultant@example.invalid).', $pending_delivery_activity['detail'], 'The delivery audit must identify the exact consultant.');
pending_assert_contains('Email delivery: sent to 1 recipient(s).', $pending_delivery_activity['detail'], 'The delivery activity must record sent delivery.');
$commit_index = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$mail_index = array_search('mail:' . $mail['subject'], $GLOBALS['wpdb']->events, true);
pending_assert_true(false !== $commit_index && false !== $mail_index && $commit_index < $mail_index, 'The review hold must commit before wp_mail is called.');

// Applicant names are unbounded user data, but communication subjects are
// VARCHAR(191). History and delivery-audit subjects must therefore remain
// machine-sized, and marker-looking lines typed by staff remain exact history.
reset_pending_case(array('fullName' => str_repeat('Very Long Applicant Name ', 20)));
$marker_message = "Please review both lines.\nRecipient: wording entered by staff.\nEmail delivery: wording entered by staff.";
$long_name_result = invoke_pending_command($pending_command, '2026-08-11T10:00:00.000Z', $marker_message);
pending_assert_same('hold', $GLOBALS['wpdb']->application['reviewerDecision'], 'A long applicant name must not prevent the Pending transition.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'Long-name delivery must retain history and sanitized audit.');
pending_assert_same('[Assessment message: pending]', $GLOBALS['wpdb']->communications[0]['subject'], 'The history subject must not include the applicant name.');
pending_assert_same($marker_message, $GLOBALS['wpdb']->communications[0]['detail'], 'Standardized history must retain staff-entered marker-looking lines exactly.');
pending_assert_same('Pending assessment email delivery audit', $GLOBALS['wpdb']->communications[1]['subject'], 'The delivery audit must use a bounded machine subject.');
pending_assert_true(strlen($GLOBALS['wpdb']->communications[1]['subject']) <= 191, 'The delivery audit subject must fit the database column.');
pending_assert_not_contains($marker_message, $GLOBALS['wpdb']->communications[1]['detail'], 'The delivery audit must not copy restricted popup text.');
pending_assert_same($marker_message, $long_name_result['application']['assessmentMessageHistory'][0]['message'], 'The returned long-name case must preserve the exact popup text.');

// Popup history is part of the state transition. If its insert fails, the hold
// rolls back and no email can be attempted.
reset_pending_case();
$GLOBALS['wpdb']->audit_insert_false_tables = array('mc_admission_communications');
pending_assert_throws_message(
	'Unable to record the assessment message history.',
	function () use ($pending_command) {
		invoke_pending_command($pending_command, '2026-08-11T10:00:00.000Z', 'False history insert test.');
	},
	'A required history insert failure must abort the command.'
);
pending_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A required history failure must roll back the Pending decision.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A required history failure must not send email.');
pending_assert_same(0, count($GLOBALS['wpdb']->communications), 'A rolled-back history insert must not remain stored.');

// A thrown activity-audit write is also contained and reported after the
// communication audit and delivered mail remain intact.
reset_pending_case();
$GLOBALS['wpdb']->audit_insert_exception_tables = array('mc_admission_activities');
$thrown_audit_result = invoke_pending_command($pending_command, '2026-08-11T10:00:00.000Z', 'Thrown audit insert test.');
pending_assert_same(true, $thrown_audit_result['delivery']['ok'], 'A delivered message must remain delivered when an audit insert throws.');
pending_assert_same('hold', $GLOBALS['wpdb']->application['reviewerDecision'], 'A thrown audit failure must not roll back the committed Pending decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'A thrown audit failure must never trigger a resend.');
pending_assert_same(
	array(
		'ok' => false,
		'skipped' => false,
		'communicationRecorded' => true,
		'activityRecorded' => false,
		'error' => 'Activity audit could not be recorded.',
	),
	$thrown_audit_result['audit'],
	'A thrown insert must be exposed as a partial audit failure without leaking exception details.'
);
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'The popup history and sanitized delivery communication must survive a later activity exception.');
pending_assert_same('Thrown audit insert test.', $GLOBALS['wpdb']->communications[0]['detail'], 'A delivery-audit failure must not alter the exact popup history.');
pending_assert_not_contains('Thrown audit insert test.', $GLOBALS['wpdb']->communications[1]['detail'], 'A delivery audit must not disclose the popup text when its peer activity insert fails.');

// A non-review case is rejected before mutation or email.
reset_pending_case(array('status' => 'offer-issued'));
pending_assert_throws_message(
	'Only an application awaiting review can be kept pending with an agent message.',
	function () use ($pending_command) {
		invoke_pending_command($pending_command);
	},
	'Only review-pending applications may use the Pending action.'
);
pending_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A wrong-stage request must not change the decision.');
pending_assert_same(false, in_array('query:START TRANSACTION', $GLOBALS['wpdb']->events, true), 'A wrong-stage request must fail before a transaction.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A wrong-stage request must not email anyone.');

// Optimistic concurrency failure rolls back and maps to HTTP 409 without audit/email.
reset_pending_case();
$GLOBALS['wpdb']->force_stale = true;
$response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => 'This request is stale.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(409, $response->get_status(), 'A stale pending request must be a conflict.');
pending_assert_same('This application changed since you opened it. Refresh and try again.', $response->get_data()['error'], 'The stale error must remain actionable.');
pending_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A stale request must roll back the review decision.');
pending_assert_same(0, count($GLOBALS['wpdb']->communications), 'A stale request must not create an email audit.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A stale request must not call wp_mail.');

// Never fall back to the student. Missing or unsafe consultant addresses are
// skipped and visibly audited after the hold is committed.
foreach (
	array(
		array('consultantEmail' => null),
		array('consultantEmail' => 'student@example.invalid', 'email' => 'student@example.invalid'),
	) as $unsafe_recipient
) {
	reset_pending_case($unsafe_recipient);
	$result = invoke_pending_command($pending_command);
	pending_assert_same(true, $result['delivery']['skipped'], 'An unsafe consultant destination must be reported as skipped.');
	pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'The student must never receive a fallback email.');
	pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A skipped delivery must preserve history plus its sanitized email-audit row.');
	pending_assert_same('Please upload the corrected evidence.', $GLOBALS['wpdb']->communications[0]['detail'], 'A skipped delivery must not alter the exact popup message.');
	pending_assert_not_contains('Please upload the corrected evidence.', $GLOBALS['wpdb']->communications[1]['detail'], 'A skipped delivery audit must not disclose the popup message.');
	$skipped_activity = pending_find_activity('Pending assessment email delivery audit');
	pending_assert_true(null !== $skipped_activity, 'A skipped delivery must remain visible in the delivery activity.');
	pending_assert_contains('Email delivery skipped:', $skipped_activity['detail'], 'The delivery activity must classify unsafe delivery as skipped.');
}

// Test-data applications remain email-silent, while their popup history is still
// durable because staff need the same review record during test workflows.
reset_pending_case(array('isTestData' => 1));
$test_result = invoke_pending_command($pending_command);
pending_assert_same(true, $test_result['delivery']['skipped'], 'Test data must report a safe delivery skip.');
pending_assert_same('Test-data applications do not send email.', $test_result['delivery']['error'], 'The safe-skip reason must be returned.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'Test data must never call wp_mail.');
pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'Test data must retain the exact popup history.');
pending_assert_same('Please upload the corrected evidence.', $GLOBALS['wpdb']->communications[0]['detail'], 'Test history must retain the exact popup message.');

// A transport refusal is failed (not skipped) and audited without undoing the
// already committed review hold.
reset_pending_case();
$GLOBALS['mc_pending_mail_result'] = false;
$failed_result = invoke_pending_command($pending_command);
pending_assert_same(false, $failed_result['delivery']['ok'], 'A refused mail must not report success.');
pending_assert_same(false, $failed_result['delivery']['skipped'], 'A refused mail is a failure, not a skip.');
pending_assert_same(0, $failed_result['delivery']['sentCount'], 'A refused mail must report no sent recipients.');
pending_assert_same(1, $failed_result['delivery']['failedCount'], 'A refused mail must report one failed consultant.');
pending_assert_same('WordPress did not accept the message.', $failed_result['delivery']['error'], 'The transport failure must be actionable.');
pending_assert_same('hold', $GLOBALS['wpdb']->application['reviewerDecision'], 'Mail failure must not roll back the committed Pending decision.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A failed send must retain history plus a sanitized email-audit row.');
pending_assert_same('Please upload the corrected evidence.', $GLOBALS['wpdb']->communications[0]['detail'], 'A transport failure must not alter the popup message.');
pending_assert_not_contains('Please upload the corrected evidence.', $GLOBALS['wpdb']->communications[1]['detail'], 'A failed delivery audit must not disclose the popup message.');
$failed_activity = pending_find_activity('Pending assessment email delivery audit');
pending_assert_true(null !== $failed_activity, 'A failed delivery must create a delivery activity.');
pending_assert_contains('Email delivery failed:', $failed_activity['detail'], 'The delivery activity must classify failed delivery.');

// A committed Pending decision must not become retryable when post-commit case
// hydration fails. The exact history is already durable, and email is attempted
// once from the committed fallback snapshot.
reset_pending_case();
$GLOBALS['wpdb']->fail_rich_read_after_commit = true;
$pending_rich_read_response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => 'Pending despite the rich reload failure.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $pending_rich_read_response->get_status(), 'A committed Pending action must survive a post-commit rich case read failure.');
$pending_rich_read_data = $pending_rich_read_response->get_data();
pending_assert_same('review-pending', $pending_rich_read_data['application']['stageKey'], 'The rich-read fallback must expose the committed review stage.');
pending_assert_same('hold', $pending_rich_read_data['application']['reviewerDecision'], 'The rich-read fallback must expose the committed hold decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'A rich-read failure must neither duplicate nor suppress the Pending email.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A rich-read failure must retain history and sanitized delivery audit.');

reset_pending_case();
$GLOBALS['wpdb']->fail_authoritative_read_after_commit = true;
$pending_authoritative_read_response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => 'Pending despite the authoritative reread failure.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $pending_authoritative_read_response->get_status(), 'A committed Pending action must survive an authoritative post-commit reread failure.');
$pending_authoritative_read_data = $pending_authoritative_read_response->get_data();
pending_assert_same('review-pending', $pending_authoritative_read_data['application']['stageKey'], 'The authoritative fallback must expose the committed review stage.');
pending_assert_same('hold', $pending_authoritative_read_data['application']['reviewerDecision'], 'The authoritative fallback must expose the committed hold decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'An authoritative reread failure must neither duplicate nor suppress the Pending email.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'An authoritative reread failure must retain history and sanitized delivery audit.');

reset_pending_case();
$GLOBALS['mc_pending_identity_exception'] = true;
$pending_identity_response = $plugin->rest_send_pending_review_message(
	pending_request(array('message' => 'Pending while owner lookup is unavailable.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $pending_identity_response->get_status(), 'An owner lookup failure must not turn a committed Pending action into a retryable response.');
$pending_identity_data = $pending_identity_response->get_data();
pending_assert_same('review-pending', $pending_identity_data['application']['stageKey'], 'The identity fallback must retain the committed review stage.');
pending_assert_same(false, $pending_identity_data['delivery']['ok'], 'Owner lookup failure must not claim Pending email success.');
pending_assert_same(false, $pending_identity_data['delivery']['skipped'], 'Owner lookup failure is an audited delivery failure, not a safe skip.');
pending_assert_contains('originating agency identity could not be resolved', $pending_identity_data['delivery']['error'], 'The Pending delivery result must explain the owner lookup failure.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'Pending email must not be attempted when authoritative owner lookup throws.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'Owner lookup failure must retain history and its sanitized failed-delivery audit.');
pending_assert_same('Pending while owner lookup is unavailable.', $GLOBALS['wpdb']->communications[0]['detail'], 'Owner lookup failure must not alter the exact Pending message.');
pending_assert_not_contains('Pending while owner lookup is unavailable.', $GLOBALS['wpdb']->communications[1]['detail'], 'Owner lookup failure audit must not disclose the popup message.');

// Guard the command ordering structurally as well as through the event log.
$method = $reflection->getMethod('send_pending_review_message');
$lines = file($method->getFileName());
$source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
pending_assert_contains("'review-pending' !== \$this->canonical_status_key", $source, 'The command must enforce authoritative review-pending state.');
pending_assert_contains("'assessmentMessageKind' => 'pending'", $source, 'The Pending transition must persist a classified history entry.');
pending_assert_contains("'assessmentMessage' => \$message", $source, 'The Pending transition must persist the exact popup message.');
pending_assert_contains("'dedicatedAssessmentMessage' => true", $source, 'The Pending command must enable committed-state fallback handling.');
pending_assert_true(
	strpos($source, '$this->update_admission_application_operations(') < strpos($source, '$this->send_pending_review_message_notification('),
	'The command must save the Pending decision before attempting email.'
);
pending_assert_not_contains('$this->get_detailed_application_record(', $source, 'The Pending command must not add a second fragile post-commit reload before email.');

// Rejection uses its own required reason command. The reason is not copied into
// assessment comments, document remarks, or the generic workflow note.
$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => '  ', 'expectedUpdatedAt' => 'version'))
);
pending_assert_same(400, $response->get_status(), 'An empty rejection reason must be rejected.');
pending_assert_same('A rejection reason is required.', $response->get_data()['error'], 'The empty-reason error must be explicit.');

$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => str_repeat('x', 4001), 'expectedUpdatedAt' => 'version'))
);
pending_assert_same(400, $response->get_status(), 'A rejection reason over 4,000 characters must be rejected.');
pending_assert_same('The rejection reason must be 4,000 characters or fewer.', $response->get_data()['error'], 'The rejection length error must state the limit.');

$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'The entry requirements were not met.'))
);
pending_assert_same(400, $response->get_status(), 'A missing rejection application version must be rejected.');
pending_assert_same('Application version is required.', $response->get_data()['error'], 'The rejection version error must be explicit.');

reset_pending_case();
$GLOBALS['mc_pending_current_user'] = pending_wp_user(array('mc_agent'));
$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'Agent must not reject this.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(403, $response->get_status(), 'An agent must not use the staff rejection action.');
pending_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A denied rejection must not change the review decision.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A denied rejection must not call wp_mail.');

reset_pending_case();
$typed_reason = 'The submitted qualifications do not meet the programme entry requirements.';
$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => $typed_reason, 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $response->get_status(), 'A valid rejection request must succeed.');
$rejection_data = $response->get_data();
pending_assert_same(true, $rejection_data['ok'], 'The rejection response must mark the command successful.');
pending_assert_same('rejected', $rejection_data['application']['stageKey'], 'The authoritative case must move to Rejected.');
pending_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'The stored stage must be rejected.');
pending_assert_same('rejected', $GLOBALS['wpdb']->application['reviewerDecision'], 'The stored review decision must be rejected.');
pending_assert_same(null, $GLOBALS['wpdb']->application['reviewSummary'], 'The rejection reason must not become an assessment comment.');
pending_assert_true($typed_reason !== $GLOBALS['wpdb']->application['workflowNote'], 'The rejection reason must not become the generic workflow note.');
pending_assert_same(
	array('ok' => true, 'skipped' => false, 'sentCount' => 1, 'failedCount' => 0, 'error' => null),
	$rejection_data['delivery'],
	'The rejection response must expose exact delivery results.'
);
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'A rejection must send exactly one email.');
$rejection_mail = $GLOBALS['mc_pending_mail_calls'][0];
pending_assert_same(array('consultant@example.invalid'), $rejection_mail['to'], 'The rejection email must target the originating consultant only.');
pending_assert_same('Application closed after review for Offline Student (MC-PENDING1)', $rejection_mail['subject'], 'The rejection subject must identify the application.');
pending_assert_contains($typed_reason, $rejection_mail['message'], 'The rejection email must contain the exact typed reason.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'The rejection must create durable history plus a sanitized email-delivery audit.');
pending_assert_same('app-pending-offline', $GLOBALS['wpdb']->communications[0]['applicationId'], 'The rejection history must use the database application id, not the public reference code.');
pending_assert_same($typed_reason, $GLOBALS['wpdb']->communications[0]['detail'], 'The rejection history must retain only the exact distinct reason.');
pending_assert_same('[Assessment message: rejected]', $GLOBALS['wpdb']->communications[0]['subject'], 'The rejection history must use the bounded reserved marker as its entire subject.');
$rejection_delivery_communication = pending_find_communication('Rejected assessment email delivery audit');
pending_assert_true(null !== $rejection_delivery_communication, 'The rejected-email result must be separately visible in Email audit.');
pending_assert_same('outbound', $rejection_delivery_communication['direction'], 'The rejected delivery audit must be outbound.');
pending_assert_same('email', $rejection_delivery_communication['channel'], 'The rejected delivery audit must use the email channel.');
pending_assert_contains('Email delivery: sent to 1 recipient(s).', $rejection_delivery_communication['detail'], 'The rejected delivery communication must record successful delivery.');
pending_assert_not_contains($typed_reason, $rejection_delivery_communication['detail'], 'The rejected Email-audit row must never disclose the restricted reason.');
pending_assert_same('rejected', $rejection_data['application']['assessmentMessageHistory'][0]['kind'], 'The returned case must expose a rejected history entry.');
pending_assert_same($typed_reason, $rejection_data['application']['assessmentMessageHistory'][0]['message'], 'The returned history must expose the exact rejection reason.');
$rejection_delivery_activity = pending_find_activity('Rejected assessment email delivery audit');
pending_assert_true(null !== $rejection_delivery_activity, 'The rejection delivery must have a separate activity audit.');
pending_assert_contains('Email delivery: sent to 1 recipient(s).', $rejection_delivery_activity['detail'], 'The rejection delivery activity must record success.');
$rejection_commit_index = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$rejection_mail_index = array_search('mail:' . $rejection_mail['subject'], $GLOBALS['wpdb']->events, true);
pending_assert_true(
	false !== $rejection_commit_index && false !== $rejection_mail_index && $rejection_commit_index < $rejection_mail_index,
	'The rejection must commit before wp_mail is called.'
);

// A post-commit rich case read failure must return the committed fallback case,
// deliver the reason once, and never invite a duplicate retry.
reset_pending_case();
$GLOBALS['wpdb']->fail_rich_read_after_commit = true;
$rich_read_response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'Rejected despite the offline reload failure.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $rich_read_response->get_status(), 'A committed rejection must survive a post-commit rich case read failure.');
$rich_read_data = $rich_read_response->get_data();
pending_assert_same('rejected', $rich_read_data['application']['stageKey'], 'The fallback case must expose the committed rejected stage.');
pending_assert_same('rejected', $rich_read_data['application']['reviewerDecision'], 'The fallback case must expose the committed rejected decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'A rich-read failure must not duplicate or suppress the rejection email.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A rich-read failure must retain rejection history and sanitized delivery audit.');

// A post-commit authoritative review reread failure must also return the
// committed result instead of inviting the operator to submit the rejection
// a second time.
reset_pending_case();
$GLOBALS['wpdb']->fail_authoritative_read_after_commit = true;
$authoritative_read_response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'Rejected despite the authoritative reread failure.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $authoritative_read_response->get_status(), 'A committed rejection must survive a post-commit authoritative review reread failure.');
$authoritative_read_data = $authoritative_read_response->get_data();
pending_assert_same('rejected', $authoritative_read_data['application']['stageKey'], 'The authoritative reread fallback must expose the committed rejected stage.');
pending_assert_same('rejected', $authoritative_read_data['application']['reviewerDecision'], 'The authoritative reread fallback must expose the committed rejected decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'An authoritative reread failure must not duplicate or suppress the rejection email.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'An authoritative reread failure must retain rejection history and sanitized delivery audit.');

// An exceptional authoritative owner lookup after commit is a delivery failure,
// not a mutation failure. It must be audited while the response remains successful.
reset_pending_case();
$GLOBALS['mc_pending_identity_exception'] = true;
$identity_response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'Rejected while owner lookup is unavailable.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(200, $identity_response->get_status(), 'An owner lookup failure must not turn a committed rejection into a retryable response.');
$identity_data = $identity_response->get_data();
pending_assert_same('rejected', $identity_data['application']['stageKey'], 'The identity fallback must retain the committed stage.');
pending_assert_same(false, $identity_data['delivery']['ok'], 'Owner lookup failure must not claim delivery success.');
pending_assert_same(false, $identity_data['delivery']['skipped'], 'Owner lookup failure is an audited delivery failure, not a safe skip.');
pending_assert_contains('originating agency identity could not be resolved', $identity_data['delivery']['error'], 'The delivery result must explain the owner lookup failure.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'No email may be attempted without an authoritative owner identity.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'Owner lookup failure must retain rejection history and sanitized failed-delivery audit.');
pending_assert_same('Rejected while owner lookup is unavailable.', $GLOBALS['wpdb']->communications[0]['detail'], 'Owner lookup failure must not alter the exact rejection reason.');
pending_assert_not_contains('Rejected while owner lookup is unavailable.', $GLOBALS['wpdb']->communications[1]['detail'], 'Owner lookup audit must not disclose the rejected reason.');
$identity_delivery_activity = pending_find_activity('Rejected assessment email delivery audit');
pending_assert_true(null !== $identity_delivery_activity, 'Owner lookup failure must create a separate delivery activity.');
pending_assert_contains('Email delivery failed:', $identity_delivery_activity['detail'], 'The owner lookup delivery activity must classify delivery as failed.');

// A stale rejection rolls back without email or delivery audit.
reset_pending_case();
$GLOBALS['wpdb']->force_stale = true;
$response = $plugin->rest_reject_review_application(
	pending_request(array('reason' => 'This command is stale.', 'expectedUpdatedAt' => '2026-08-11T10:00:00.000Z'))
);
pending_assert_same(409, $response->get_status(), 'A stale rejection must be a conflict.');
pending_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A stale rejection must roll back the decision.');
pending_assert_same('review-pending', $GLOBALS['wpdb']->application['status'], 'A stale rejection must preserve the review stage.');
pending_assert_same(0, count($GLOBALS['wpdb']->communications), 'A stale rejection must not create an email audit.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A stale rejection must not call wp_mail.');

// A transport failure is reported after the rejection remains durably committed.
reset_pending_case();
$GLOBALS['mc_pending_mail_result'] = false;
$failed_rejection = invoke_rejection_command($rejection_command);
pending_assert_same(false, $failed_rejection['delivery']['ok'], 'A refused rejection email must not report delivery success.');
pending_assert_same(false, $failed_rejection['delivery']['skipped'], 'A refused rejection email is a failure, not a skip.');
pending_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'Mail failure must not roll back the committed rejection.');
pending_assert_same('rejected', $GLOBALS['wpdb']->application['reviewerDecision'], 'Mail failure must not roll back the rejected decision.');
pending_assert_same(2, count($GLOBALS['wpdb']->communications), 'A failed rejection email must retain history and sanitized failed-delivery audit.');
pending_assert_same('The entry requirements were not met.', $GLOBALS['wpdb']->communications[0]['detail'], 'A mail failure must not alter the exact rejection reason.');
pending_assert_not_contains('The entry requirements were not met.', $GLOBALS['wpdb']->communications[1]['detail'], 'A failed rejection delivery audit must not disclose the reason.');
$failed_rejection_activity = pending_find_activity('Rejected assessment email delivery audit');
pending_assert_true(null !== $failed_rejection_activity, 'A failed rejection email must create a delivery activity.');
pending_assert_contains('Email delivery failed:', $failed_rejection_activity['detail'], 'The rejection delivery activity must classify failed delivery.');

// Test-data rejection changes state without email but still retains popup history.
reset_pending_case(array('isTestData' => 1));
$test_rejection = invoke_rejection_command($rejection_command);
pending_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'Test data may exercise the offline rejection state transition.');
pending_assert_same(true, $test_rejection['delivery']['skipped'], 'Test-data rejection delivery must be safely skipped.');
pending_assert_same('Test-data applications do not send email.', $test_rejection['delivery']['error'], 'The test-data skip reason must be explicit.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'Test-data rejection must never call wp_mail.');
pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'Test-data rejection must retain the popup history.');
pending_assert_same('The entry requirements were not met.', $GLOBALS['wpdb']->communications[0]['detail'], 'Test-data history must retain the exact rejection reason.');

// Wrong-stage rejection fails before a transaction or email.
reset_pending_case(array('status' => 'offer-issued'));
pending_assert_throws_message(
	'Only an application awaiting review can be rejected.',
	function () use ($rejection_command) {
		invoke_rejection_command($rejection_command);
	},
	'Only review-pending applications may use the rejection action.'
);
pending_assert_same(false, in_array('query:START TRANSACTION', $GLOBALS['wpdb']->events, true), 'A wrong-stage rejection must fail before a transaction.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'A wrong-stage rejection must not email anyone.');

$rejection_method = $reflection->getMethod('reject_review_application');
$rejection_lines = file($rejection_method->getFileName());
$rejection_source = implode('', array_slice($rejection_lines, $rejection_method->getStartLine() - 1, $rejection_method->getEndLine() - $rejection_method->getStartLine() + 1));
pending_assert_contains("'draft' => array('reviewerDecision' => 'rejected')", $rejection_source, 'The rejection command must change only the review decision.');
pending_assert_contains("'suppressReviewRejectionNotification' => true", $rejection_source, 'The dedicated reason must replace the generic rejection email.');
pending_assert_contains("'dedicatedReviewRejection' => true", $rejection_source, 'Only the dedicated rejection command may authorize the rejected decision transition.');
pending_assert_contains("'assessmentMessageKind' => 'rejected'", $rejection_source, 'The rejection transition must persist a classified history entry.');
pending_assert_contains("'assessmentMessage' => \$reason", $rejection_source, 'The rejection transition must persist the exact popup reason.');
pending_assert_contains("\$reason,\n\t\t\t\tfalse", $rejection_source, 'Each newly committed rejection must send its current reason even when an older rejection audit exists.');
pending_assert_true(
	strpos($rejection_source, '$this->update_admission_application_operations(') < strpos($rejection_source, '$this->send_review_rejection_notification('),
	'The rejection command must save before attempting email.'
);
pending_assert_true(false === strpos($rejection_source, 'reviewSummary'), 'The rejection reason must not be coupled to review comments.');
pending_assert_true(false === strpos($rejection_source, 'workflowNote'), 'The rejection reason must not be coupled to workflow notes.');

echo "Pending and rejected review message tests passed.\n";
