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

function pending_assert_throws_message($expected, $callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		pending_assert_same($expected, $error->getMessage(), $message);
		return;
	}

	throw new RuntimeException($message . ' No exception was thrown.');
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

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$pending_command = $reflection->getMethod('send_pending_review_message');
$pending_command->setAccessible(true);
$can_assess = $reflection->getMethod('can_assess_admission_documents');
$can_assess->setAccessible(true);

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

pending_assert_same(true, $can_assess->invoke($plugin, pending_internal_user(array('administrator'))), 'Administrators must be able to send pending-review messages.');
pending_assert_same(true, $can_assess->invoke($plugin, pending_internal_user(array('admissions-officer'))), 'Admissions Officers must be able to send pending-review messages.');
pending_assert_same(false, $can_assess->invoke($plugin, pending_internal_user(array('finance-officer'))), 'Finance Officers must not use the admissions review action.');
pending_assert_same(false, $can_assess->invoke($plugin, pending_internal_user(array('mc_agent'))), 'Agents must not send pending-review messages through the staff action.');

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

// Happy path: persist hold first, then send exactly the typed message to exactly
// consultantEmail and record sent delivery in the communication audit.
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
pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'A successful send must create one communication audit.');
pending_assert_contains($typed_message, $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must retain the distinct pending message.');
pending_assert_contains('Recipient: Origin Consultant (consultant@example.invalid).', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must identify the exact consultant.');
pending_assert_contains('Email delivery: sent to 1 recipient(s).', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must record sent delivery.');
$commit_index = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$mail_index = array_search('mail:' . $mail['subject'], $GLOBALS['wpdb']->events, true);
pending_assert_true(false !== $commit_index && false !== $mail_index && $commit_index < $mail_index, 'The review hold must commit before wp_mail is called.');

// Audit-storage failures are visible without changing successful delivery into
// a resendable command failure. A false communication insert still allows the
// independent activity audit to be attempted.
reset_pending_case();
$GLOBALS['wpdb']->audit_insert_false_tables = array('mc_admission_communications');
$false_audit_result = invoke_pending_command($pending_command, '2026-08-11T10:00:00.000Z', 'False audit insert test.');
pending_assert_same(true, $false_audit_result['delivery']['ok'], 'A delivered message must remain delivered when an audit insert returns false.');
pending_assert_same('hold', $GLOBALS['wpdb']->application['reviewerDecision'], 'An audit failure must not roll back the committed Pending decision.');
pending_assert_same(1, count($GLOBALS['mc_pending_mail_calls']), 'An audit failure must not resend or suppress the already attempted email.');
pending_assert_same(
	array(
		'ok' => false,
		'skipped' => false,
		'communicationRecorded' => false,
		'activityRecorded' => true,
		'error' => 'Communication audit could not be recorded.',
	),
	$false_audit_result['audit'],
	'A false insert must be exposed as a partial audit failure.'
);
pending_assert_same(0, count($GLOBALS['wpdb']->communications), 'A false communication insert must not pretend that a communication row exists.');

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
pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'The successful communication audit must survive a later activity-audit exception.');

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
	pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'A skipped delivery must remain visible in the audit.');
	pending_assert_contains('Email delivery skipped:', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must classify unsafe delivery as skipped.');
}

// Test-data applications remain completely silent, including delivery-audit noise.
reset_pending_case(array('isTestData' => 1));
$test_result = invoke_pending_command($pending_command);
pending_assert_same(true, $test_result['delivery']['skipped'], 'Test data must report a safe delivery skip.');
pending_assert_same('Test-data applications do not send email.', $test_result['delivery']['error'], 'The safe-skip reason must be returned.');
pending_assert_same(0, count($GLOBALS['mc_pending_mail_calls']), 'Test data must never call wp_mail.');
pending_assert_same(0, count($GLOBALS['wpdb']->communications), 'Test data must not create email delivery audit noise.');

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
pending_assert_same(1, count($GLOBALS['wpdb']->communications), 'A failed send must create one audit.');
pending_assert_contains('Email delivery failed:', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must classify failed delivery.');

// Guard the command ordering structurally as well as through the event log.
$method = $reflection->getMethod('send_pending_review_message');
$lines = file($method->getFileName());
$source = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
pending_assert_contains("'review-pending' !== \$this->canonical_status_key", $source, 'The command must enforce authoritative review-pending state.');
pending_assert_true(
	strpos($source, '$this->update_admission_application_operations(') < strpos($source, '$this->send_pending_review_message_notification('),
	'The command must save the Pending decision before attempting email.'
);
pending_assert_true(
	strpos($source, '$this->get_detailed_application_record(') < strpos($source, '$this->send_pending_review_message_notification('),
	'The email must use an authoritative post-save application record.'
);

echo "Pending review message tests passed.\n";
