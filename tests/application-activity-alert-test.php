<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
}

final class WP_REST_Response {
	public function __construct($data, $status = 200) {}
}

final class WP_REST_Request {}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Alert_Test_Role {
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

final class MC_Alert_Test_Wpdb {
	public $insert_calls = array();
	public $throw_on_insert = false;

	public function prepare($query, ...$args) {
		foreach ($args as $arg) {
			$placeholder = false !== strpos($query, '%s') ? '%s' : '%d';
			$replacement = '%d' === $placeholder ? (string) (int) $arg : "'" . (string) $arg . "'";
			$query = preg_replace('/' . preg_quote($placeholder, '/') . '/', $replacement, $query, 1);
		}

		return $query;
	}

	public function get_var($query) {
		if (
			false !== strpos((string) $query, 'SHOW TABLES LIKE')
			&& false !== strpos((string) $query, 'mc_admission_communications')
		) {
			return 'mc_admission_communications';
		}

		return null;
	}

	public function query($query) {
		return 1;
	}

	public function insert($table, $data, $format = null) {
		if ($this->throw_on_insert) {
			throw new RuntimeException('Offline audit write failure.');
		}

		$this->insert_calls[] = array('table' => $table, 'data' => $data);
		return 1;
	}
}

$GLOBALS['wpdb'] = new MC_Alert_Test_Wpdb();
$GLOBALS['mc_alert_roles'] = array();
$GLOBALS['mc_alert_mail_calls'] = array();
$GLOBALS['mc_alert_mail_failures'] = array();
$GLOBALS['mc_alert_mail_exceptions'] = array();
$GLOBALS['mc_alert_uuid'] = 0;
$GLOBALS['mc_alert_users'] = array(
	(object) array(
		'ID' => 1,
		'display_name' => 'President Account',
		'user_email' => 'PRESIDENT@mesoyios.ac.cy',
		'roles' => array('administrator'),
	),
	(object) array(
		'ID' => 2,
		'display_name' => 'Agent Actor',
		'user_email' => 'actor@example.test',
		'roles' => array('mc_agent'),
	),
	(object) array(
		'ID' => 3,
		'display_name' => 'Immigration Officer',
		'user_email' => 'immigration@example.test',
		'roles' => array('immigration-officer'),
	),
	(object) array(
		'ID' => 4,
		'display_name' => 'Administrator',
		'user_email' => 'admin@example.test',
		'roles' => array('administrator'),
	),
	(object) array(
		'ID' => 5,
		'display_name' => 'Finance Officer',
		'user_email' => 'finance@example.test',
		'roles' => array('finance-officer'),
	),
	(object) array(
		'ID' => 6,
		'display_name' => 'Admissions Officer',
		'user_email' => 'admissions@example.test',
		'roles' => array('admissions-officer'),
	),
);

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_alert_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Alert_Test_Role($label);
	$GLOBALS['mc_alert_roles'][$slug] = $role;
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

function get_users($args = array()) {
	$roles = isset($args['role__in']) ? (array) $args['role__in'] : array();

	return array_values(
		array_filter(
			$GLOBALS['mc_alert_users'],
			function ($user) use ($roles) {
				return count(array_intersect($roles, (array) $user->roles)) > 0;
			}
		)
	);
}

function get_userdata($user_id) {
	foreach ($GLOBALS['mc_alert_users'] as $user) {
		if ((int) $user->ID === (int) $user_id) {
			return $user;
		}
	}

	return false;
}

function wp_generate_uuid4() {
	$GLOBALS['mc_alert_uuid']++;
	return 'offline-alert-' . $GLOBALS['mc_alert_uuid'];
}

function current_time($type, $gmt = false) {
	return '2026-07-30 10:00:00';
}

function wp_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
	$email = strtolower((string) reset($to));
	$GLOBALS['mc_alert_mail_calls'][] = array(
		'email' => $email,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
	);

	if (in_array($email, $GLOBALS['mc_alert_mail_exceptions'], true)) {
		throw new RuntimeException('Offline transport exception.');
	}

	return !in_array($email, $GLOBALS['mc_alert_mail_failures'], true);
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function alert_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function alert_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function alert_assert_not_contains($needle, $haystack, $message) {
	if (false !== strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function alert_reset_side_effects() {
	$GLOBALS['mc_alert_mail_calls'] = array();
	$GLOBALS['mc_alert_mail_failures'] = array();
	$GLOBALS['mc_alert_mail_exceptions'] = array();
	$GLOBALS['wpdb']->insert_calls = array();
	$GLOBALS['wpdb']->throw_on_insert = false;
}

function alert_mail_addresses() {
	return array_column($GLOBALS['mc_alert_mail_calls'], 'email');
}

function alert_method_source($reflection, $method_name) {
	$method = $reflection->getMethod($method_name);
	$lines = file($method->getFileName());
	return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$review_gate = $reflection->getMethod('should_send_review_submission_alert');
$review_gate->setAccessible(true);
$gate = $reflection->getMethod('should_send_post_submission_agent_document_alert');
$gate->setAccessible(true);
$infer_test_data = $reflection->getMethod('infer_application_test_data');
$infer_test_data->setAccessible(true);
$resolve_test_data = $reflection->getMethod('resolve_application_test_data');
$resolve_test_data->setAccessible(true);
$payload_method = $reflection->getMethod('application_activity_alert_payload');
$payload_method->setAccessible(true);
$send = $reflection->getMethod('send_application_activity_alert');
$send->setAccessible(true);

$application = array(
	'id' => 'application-1',
	'referenceCode' => 'MC-10000001',
	'fullName' => 'Offline Applicant',
	'status' => 'review-pending',
	'isTestData' => 0,
);
$agent = array(
	'id' => 2,
	'name' => 'Agent Actor',
	'email' => 'actor@example.test',
	'roles' => array('mc_agent'),
);
$dual_role_internal = array(
	'id' => 7,
	'name' => 'Dual Role Internal',
	'email' => 'dual-role@example.test',
	'roles' => array('mc_agent', 'admissions-officer'),
);
$internal_user = array(
	'id' => 4,
	'name' => 'Administrator',
	'email' => 'admin@example.test',
	'roles' => array('administrator'),
);

$preparation_application = array_merge($application, array('status' => 'profile-preparation'));
alert_assert_same(true, $review_gate->invoke($plugin, $application, $agent, true), 'An authoritative agent submission in review must alert.');
alert_assert_same(false, $review_gate->invoke($plugin, $application, $internal_user, true), 'An internal submission must not masquerade as an incoming agent application.');
alert_assert_same(false, $review_gate->invoke($plugin, $application, $dual_role_internal, true), 'A dual-role internal submission must not masquerade as an incoming agent application.');
alert_assert_same(false, $review_gate->invoke($plugin, array_merge($application, array('status' => 'profile-preparation')), $agent, true), 'A preparation-stage application must not emit a submission alert.');
alert_assert_same(false, $review_gate->invoke($plugin, array_merge($application, array('isTestData' => 1)), $agent, true), 'A test application submission must remain silent.');
alert_assert_same(false, $review_gate->invoke($plugin, $application, $agent, false), 'A normal edit must not emit a submission alert.');

alert_assert_same(true, $gate->invoke($plugin, $application, $agent), 'An agent upload after submission must alert.');
foreach (array('profile-preparation', 'Application in progress', 'Draft') as $preparation_status) {
	$preparation = array_merge($application, array('status' => $preparation_status));
	alert_assert_same(false, $gate->invoke($plugin, $preparation, $agent), $preparation_status . ' uploads must remain silent.');
}
alert_assert_same(false, $gate->invoke($plugin, $application, $internal_user), 'Internal staff uploads must not use the agent-update alert.');
alert_assert_same(false, $gate->invoke($plugin, $application, $dual_role_internal), 'Dual-role internal uploads must not use the agent-update alert.');
alert_assert_same(
	false,
	$gate->invoke($plugin, array_merge($application, array('isTestData' => 1)), $agent),
	'Test application uploads must remain silent.'
);

$live_draft = array(
	'fullName' => 'Live Applicant',
	'passportNumber' => 'P123456',
	'email' => 'applicant@college.invalid-real-domain.com',
	'agencyName' => 'Live Agency',
	'consultantName' => 'Live Consultant',
	'consultantEmail' => 'consultant@mesoyios.ac.cy',
);
$live_user = array(
	'id' => 22,
	'username' => 'live-agent',
	'name' => 'Live Agent',
	'email' => 'live-agent@mesoyios.ac.cy',
	'roles' => array('mc_agent'),
);
$live_admin = array(
	'id' => 23,
	'username' => 'live-administrator',
	'name' => 'Live Administrator',
	'email' => 'administrator@mesoyios.ac.cy',
	'roles' => array('administrator'),
);
alert_assert_same(false, $infer_test_data->invoke($plugin, $live_draft, $live_user), 'Ordinary live data must not be classified as test data.');
foreach (
	array(
		array('fullName', 'MOBILE TEST Applicant'),
		array('passportNumber', 'UAT-12345'),
		array('email', 'student@local.invalid'),
		array('agencyName', 'Codex Verification Agency'),
		array('consultantEmail', 'consultant@example.test'),
	) as $marker
) {
	$marked_draft = array_merge($live_draft, array($marker[0] => $marker[1]));
	alert_assert_same(true, $infer_test_data->invoke($plugin, $marked_draft, $live_user), $marker[1] . ' must be inferred as test data server-side.');
}
alert_assert_same(false, $resolve_test_data->invoke($plugin, $live_draft, $live_user, true, false), 'An agent must not suppress mandatory alerts with a client test-data flag.');
alert_assert_same(true, $resolve_test_data->invoke($plugin, $live_draft, $live_admin, true, false), 'An administrator may explicitly classify controlled harness data.');
alert_assert_same(true, $resolve_test_data->invoke($plugin, $live_draft, $live_user, false, true), 'An existing test record must remain test data.');
alert_assert_same(
	true,
	$resolve_test_data->invoke($plugin, array_merge($live_draft, array('fullName' => 'Smoke Applicant')), $live_user, false, false),
	'A false client flag must not override server-side test markers.'
);

$submission_payload = $payload_method->invoke($plugin, $application, $agent, 'new-application-submitted');
alert_assert_same(
	array('administrator', 'admissions-officer', 'immigration-officer'),
	$submission_payload['roles'],
	'Submission alerts must target exactly the baseline internal roles.'
);
alert_assert_same('president@mesoyios.ac.cy', $submission_payload['to'][0]['email'], 'The President recipient must be explicit.');

$bank_payload = $payload_method->invoke(
	$plugin,
	$application,
	$agent,
	'agent-document-uploaded',
	'bankTransactionConfirmation',
	'transaction.pdf'
);
alert_assert_same(
	array('administrator', 'admissions-officer', 'immigration-officer', 'finance-officer'),
	$bank_payload['roles'],
	'Bank confirmation alerts must add Finance to the same delivery.'
);

alert_reset_side_effects();
$submission_result = $send->invoke($plugin, $application, $agent, 'new-application-submitted');
$submission_addresses = alert_mail_addresses();
alert_assert_same(true, $submission_result['ok'], 'A fully delivered submission alert must succeed.');
alert_assert_same(4, count($submission_addresses), 'President plus matching role accounts must receive one message each.');
alert_assert_same(1, count(array_keys($submission_addresses, 'president@mesoyios.ac.cy', true)), 'President direct and administrator matches must deduplicate.');
alert_assert_same(false, in_array('actor@example.test', $submission_addresses, true), 'An external agent must not become an internal role recipient.');
alert_assert_same(1, count(array_keys($submission_addresses, 'admissions@example.test', true)), 'The Admissions Officer role must receive one deduplicated message.');
alert_assert_same(2, count($GLOBALS['wpdb']->insert_calls), 'Delivery must write one communication and one activity audit.');
alert_assert_contains(
	'Email delivery: sent to 4 recipient(s).',
	$GLOBALS['wpdb']->insert_calls[0]['data']['detail'],
	'The communication audit must record successful delivery.'
);

alert_reset_side_effects();
$document_result = $send->invoke(
	$plugin,
	$application,
	$agent,
	'agent-document-uploaded',
	'passport',
	'passport.pdf'
);
alert_assert_same(true, $document_result['ok'], 'A post-submission agent document alert must deliver.');
alert_assert_same(false, in_array('finance@example.test', alert_mail_addresses(), true), 'Non-bank document alerts must not add Finance.');

alert_reset_side_effects();
$bank_result = $send->invoke(
	$plugin,
	$application,
	$agent,
	'agent-document-uploaded',
	'bankTransactionConfirmation',
	'transaction.pdf'
);
alert_assert_same(true, $bank_result['ok'], 'The combined bank confirmation alert must deliver.');
alert_assert_same(5, count(alert_mail_addresses()), 'The bank alert must add one deduplicated Finance recipient.');
alert_assert_same(1, count(array_keys(alert_mail_addresses(), 'finance@example.test', true)), 'Finance must receive one message, not a second alert.');

alert_reset_side_effects();
$skipped = $send->invoke(
	$plugin,
	array_merge($application, array('isTestData' => 1)),
	$agent,
	'new-application-submitted'
);
alert_assert_same(true, $skipped['skipped'], 'Test application alerts must report a safe skip.');
alert_assert_same(array(), $GLOBALS['mc_alert_mail_calls'], 'Test application alerts must never call wp_mail.');
alert_assert_same(array(), $GLOBALS['wpdb']->insert_calls, 'Test application alerts must not create delivery audit noise.');

alert_reset_side_effects();
$GLOBALS['mc_alert_mail_exceptions'] = array('admissions@example.test');
$GLOBALS['mc_alert_mail_failures'] = array('immigration@example.test');
$partial = $send->invoke($plugin, $application, $agent, 'new-application-submitted');
alert_assert_same(false, $partial['ok'], 'Any failed recipient must make the delivery summary partial.');
alert_assert_same(4, count($GLOBALS['mc_alert_mail_calls']), 'A thrown wp_mail call must not stop later recipients.');
alert_assert_same(2, count($partial['sent']), 'Successful recipients must remain recorded after peer failures.');
alert_assert_same(2, count($partial['failed']), 'False and thrown deliveries must both be recorded as failed.');
alert_assert_contains(
	'Email delivery: partially sent to 2 recipient(s); 2 failed.',
	$GLOBALS['wpdb']->insert_calls[0]['data']['detail'],
	'Partial delivery must be captured in the audit communication.'
);

$rest_save_source = alert_method_source($reflection, 'rest_save_application');
$boot_source = alert_method_source($reflection, 'boot');
$activate_source = alert_method_source($reflection, 'activate');
$test_data_schema_source = alert_method_source($reflection, 'ensure_application_test_data_schema');
$save_source = alert_method_source($reflection, 'save_admission_application');
$upload_source = alert_method_source($reflection, 'upload_admission_document');
$operations_source = alert_method_source($reflection, 'update_admission_application_operations');
$delete_source = alert_method_source($reflection, 'delete_admission_document');
$rest_email_source = alert_method_source($reflection, 'rest_send_email');
alert_assert_contains("array_key_exists('isTestData', \$params)", $rest_save_source, 'The WordPress save route must preserve the test-data flag.');
alert_assert_contains('ensure_application_test_data_schema', $boot_source, 'Every plugin boot must run the backwards-compatible test-data schema guard.');
alert_assert_contains('isTestData BOOLEAN NOT NULL DEFAULT 0', $activate_source, 'Fresh installations must create the test-data classification column.');
alert_assert_contains('ADD COLUMN isTestData BOOLEAN NOT NULL DEFAULT 0', $test_data_schema_source, 'Existing installations must add the test-data column before requests use it.');
alert_assert_contains("'isTestData' => \$next_is_test_data ? 1 : 0", $save_source, 'Server-classified test applications must be persisted before alert evaluation.');
alert_assert_contains("'profile-preparation' === \$this->canonical_status_key", $save_source, 'Review submission must recognize every canonical preparation status.');
alert_assert_contains("false === \$wpdb->query('START TRANSACTION')", $save_source, 'The application save transaction start must be checked.');
alert_assert_contains('false === $updated', $save_source, 'The primary application update must be checked.');
alert_assert_contains('false === $inserted || 0 === $inserted', $save_source, 'The primary application insert must be checked.');
alert_assert_contains('false === $status_written || 0 === $status_written', $save_source, 'The preparation-to-review stage write must be checked.');
alert_assert_contains("false === \$wpdb->query('COMMIT')", $save_source, 'The application save COMMIT must be checked.');
alert_assert_same(
	true,
	strpos($save_source, '$wpdb->query(\'COMMIT\')') < strrpos($save_source, '$this->send_application_activity_alert('),
	'The review-submission email must be attempted only after the application transaction commits.'
);
alert_assert_same(
	true,
	strpos($save_source, '$wpdb->query(\'COMMIT\')') < strpos($save_source, "'new-application-submitted'"),
	'The review-submission email must be attempted only after the application transaction commits.'
);
alert_assert_contains("\$should_notify_review_submission = 'review' === \$mode && \$this->is_external_agent_user(\$user)", $save_source, 'Draft and internal creation must not set the submission alert gate.');
alert_assert_not_contains('$should_notify_draft_creation', $save_source, 'Draft creation must not set an email-alert gate.');
alert_assert_not_contains('should_send_draft_creation_alert', $save_source, 'Draft creation must not invoke a post-commit email gate.');
alert_assert_not_contains("'new-application-created'", $save_source, 'Draft creation must not emit an email event.');
alert_assert_same(
	true,
	strpos($upload_source, '$wpdb->query(\'COMMIT\')') < strpos($upload_source, '$this->send_application_activity_alert('),
	'Document email must be attempted only after the upload transaction commits.'
);
alert_assert_contains('should_send_post_submission_agent_document_alert', $upload_source, 'Document uploads must use the explicit agent/stage/test gate.');
alert_assert_same(2, substr_count($upload_source, 'should_send_post_submission_agent_document_alert'), 'Document alerts must be gated before mutation and against authoritative post-commit state.');
alert_assert_not_contains('send_application_activity_alert', $operations_source, 'General operational edits must not emit the urgent upload alert.');
alert_assert_not_contains('send_application_activity_alert', $delete_source, 'Document deletion must not emit the urgent upload alert.');
alert_assert_not_contains('send_application_activity_alert', $rest_email_source, 'The ordinary /email endpoint must remain independent.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
alert_assert_not_contains('should_send_draft_creation_alert', $plugin_source, 'The obsolete first-draft email gate must be absent from the plugin.');
alert_assert_not_contains("'new-application-created'", $plugin_source, 'The obsolete first-draft email event must be absent from the plugin.');
alert_assert_contains('Version: 0.2.55', $plugin_source, 'The plugin header must advertise version 0.2.55.');

echo 'Application activity alert tests passed.' . PHP_EOL;
