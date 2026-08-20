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
		$this->status = (int) $status;
	}

	public function get_data() {
		return $this->data;
	}

	public function get_status() {
		return $this->status;
	}
}

final class WP_REST_Request {
	private $params;

	public function __construct($params = array()) {
		$this->params = (array) $params;
	}

	public function get_param($key) {
		return array_key_exists($key, $this->params) ? $this->params[$key] : null;
	}
}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Notification_Event_Test_Role {
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

final class MC_Notification_Event_Test_Wpdb {
	public $prepared_calls = array();
	public $get_results_calls = array();
	public $rows = array();
	public $insert_calls = array();
	public $insert_result = 1;

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) {
			$args = $args[0];
		}
		$this->prepared_calls[] = array(
			'query' => (string) $query,
			'args' => array_values($args),
		);

		return (string) $query;
	}

	public function get_var($query) {
		return null;
	}

	public function query($query) {
		return 1;
	}

	public function get_results($query, $output = null) {
		$this->get_results_calls[] = array(
			'query' => (string) $query,
			'output' => $output,
		);

		return $this->rows;
	}

	public function insert($table, $data, $format = null) {
		$this->insert_calls[] = array(
			'table' => $table,
			'data' => $data,
			'format' => $format,
		);

		return $this->insert_result;
	}

	public function reset_reads() {
		$this->prepared_calls = array();
		$this->get_results_calls = array();
	}
}

$GLOBALS['wpdb'] = new MC_Notification_Event_Test_Wpdb();
$GLOBALS['mc_notification_event_roles'] = array();
$GLOBALS['mc_notification_event_uuid'] = 0;
$GLOBALS['mc_notification_event_mail_calls'] = 0;
$GLOBALS['mc_notification_event_network_calls'] = 0;
$GLOBALS['mc_notification_event_user'] = (object) array(
	'ID' => 10,
	'user_login' => 'dual-role-staff',
	'display_name' => 'Dual Role Staff',
	'user_email' => 'dual-role@example.test',
	'roles' => array('mc_agent', 'admissions-officer'),
	'allcaps' => array('read' => true),
);

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return isset($GLOBALS['mc_notification_event_roles'][$slug])
		? $GLOBALS['mc_notification_event_roles'][$slug]
		: null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Notification_Event_Test_Role($label);
	$GLOBALS['mc_notification_event_roles'][$slug] = $role;

	return $role;
}

function get_option($key, $fallback = false) {
	$values = array(
		'mc_admissions_notification_activity_schema_version' => '1',
		'mc_admissions_application_test_data_schema_version' => '1',
		'mc_admissions_resource_index_version' => '1',
		'mc_admissions_schema_version' => '0.2.14',
		'mc_admissions_offer_detail_schema_version' => '0.2.38',
		'mc_admissions_case_detail_schema_version' => '0.2.45',
		'mc_admissions_document_assessment_schema_version' => '1',
	);

	return array_key_exists($key, $values) ? $values[$key] : $fallback;
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

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function wp_get_current_user() {
	return $GLOBALS['mc_notification_event_user'];
}

function get_avatar_url($user_id, $args = array()) {
	return '';
}

function wp_generate_uuid4() {
	$GLOBALS['mc_notification_event_uuid']++;

	return 'offline-event-' . $GLOBALS['mc_notification_event_uuid'];
}

function wp_mail(...$args) {
	$GLOBALS['mc_notification_event_mail_calls']++;

	return false;
}

function wp_remote_request(...$args) {
	$GLOBALS['mc_notification_event_network_calls']++;
	throw new RuntimeException('Network access is forbidden in notification-event tests.');
}

function wp_remote_get(...$args) {
	$GLOBALS['mc_notification_event_network_calls']++;
	throw new RuntimeException('Network access is forbidden in notification-event tests.');
}

function wp_remote_post(...$args) {
	$GLOBALS['mc_notification_event_network_calls']++;
	throw new RuntimeException('Network access is forbidden in notification-event tests.');
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function notification_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function notification_assert_true($actual, $message) {
	notification_assert_same(true, (bool) $actual, $message);
}

function notification_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function notification_assert_not_contains($needle, $haystack, $message) {
	if (false !== strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function notification_event_row($number, $kind = 'application', $created_at = '2020-07-30 10:00:00.123') {
	return array(
		'id' => sprintf('event-%03d', $number),
		'applicationId' => 'application-' . $number,
		'kind' => $kind,
		'title' => 'Offline event ' . $number,
		'actorName' => 'Offline Agent',
		'createdAt' => $created_at,
		'referenceCode' => sprintf('MC-%08d', $number),
		'fullName' => 'Offline Applicant ' . $number,
	);
}

function notification_method_source($reflection, $method_name) {
	$method = $reflection->getMethod($method_name);
	$lines = file($method->getFileName());

	return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$wpdb = $GLOBALS['wpdb'];

$external_agent = array(
	'id' => 20,
	'username' => 'external-agent',
	'name' => 'External Agent',
	'email' => 'external-agent@example.test',
	'roles' => array('mc_agent'),
);
$dual_role_internal = array(
	'id' => 10,
	'username' => 'dual-role-staff',
	'name' => 'Dual Role Staff',
	'email' => 'dual-role@example.test',
	'roles' => array('mc_agent', 'admissions-officer'),
);

$is_external_agent = $reflection->getMethod('is_external_agent_user');
$is_external_agent->setAccessible(true);
notification_assert_same(true, $is_external_agent->invoke($plugin, $external_agent), 'A pure agent must be classified as external.');
notification_assert_same(false, $is_external_agent->invoke($plugin, $dual_role_internal), 'An internal staff role must win over an additional agent role.');

$wpdb->rows = array(notification_event_row(1, 'workflow'));
$wpdb->reset_reads();
$dual_response = $plugin->rest_notification_events(new WP_REST_Request(array('since' => '2020-07-30T09:00:00.000Z')));
notification_assert_same(200, $dual_response->get_status(), 'Dual-role internal staff must be allowed to poll.');
notification_assert_same(1, count($dual_response->get_data()['events']), 'Dual-role internal staff must receive agent events.');
notification_assert_same(1, count($wpdb->get_results_calls), 'Dual-role internal staff must execute the event query.');

$GLOBALS['mc_notification_event_user'] = (object) array(
	'ID' => 20,
	'user_login' => 'external-agent',
	'display_name' => 'External Agent',
	'user_email' => 'external-agent@example.test',
	'roles' => array('mc_agent'),
	'allcaps' => array('read' => true),
);
$wpdb->reset_reads();
$agent_response = $plugin->rest_notification_events(new WP_REST_Request(array('since' => '2020-07-30T09:00:00.000Z')));
notification_assert_same(200, $agent_response->get_status(), 'External agents must get a harmless empty polling response.');
notification_assert_same(array(), $agent_response->get_data()['events'], 'External agents must not receive internal notification events.');
notification_assert_same(0, count($wpdb->get_results_calls), 'External agents must not query the internal event feed.');

$GLOBALS['mc_notification_event_user'] = (object) array(
	'ID' => 10,
	'user_login' => 'dual-role-staff',
	'display_name' => 'Dual Role Staff',
	'user_email' => 'dual-role@example.test',
	'roles' => array('mc_agent', 'admissions-officer'),
	'allcaps' => array('read' => true),
);

$wpdb->rows = null;
$wpdb->reset_reads();
$failure_response = $plugin->rest_notification_events(new WP_REST_Request(array('since' => '2020-07-30T09:00:00.000Z')));
notification_assert_same(503, $failure_response->get_status(), 'A failed database read must be retryable, not a successful empty page.');
notification_assert_same(false, $failure_response->get_data()['ok'], 'A failed database read must return an error body.');
notification_assert_same(false, array_key_exists('cursor', $failure_response->get_data()), 'A failed database read must never advance the client cursor.');

$wpdb->rows = array();
$wpdb->reset_reads();
$invalid_response = $plugin->rest_notification_events(new WP_REST_Request(array('since' => 'not-a-cursor|bad value')));
notification_assert_same(400, $invalid_response->get_status(), 'Malformed cursors must fail closed.');
notification_assert_same(0, count($wpdb->get_results_calls), 'Malformed cursors must not reach the database.');

$wpdb->rows = array();
for ($index = 1; $index <= 50; $index++) {
	$wpdb->rows[] = notification_event_row($index);
}
$wpdb->reset_reads();
$first_page = $plugin->rest_notification_events(new WP_REST_Request(array('since' => '2020-07-30T09:00:00.000Z')));
$first_data = $first_page->get_data();
notification_assert_same(200, $first_page->get_status(), 'The first full notification page must load.');
notification_assert_same(50, count($first_data['events']), 'The endpoint must retain its 50-event page size.');
notification_assert_same(true, $first_data['hasMore'], 'A full page must advertise that the cursor is a continuation cursor.');
notification_assert_same('2020-07-30T10:00:00.123Z|event-050', $first_data['cursor'], 'A full page cursor must include the final timestamp and stable event ID.');

$first_query = $wpdb->prepared_calls[count($wpdb->prepared_calls) - 1];
notification_assert_contains('COALESCE(app.isTestData, 0) = 0', $first_query['query'], 'Test applications must be excluded in SQL before pagination.');
notification_assert_contains("'workflow', 'agent-document-upload'", $first_query['query'], 'Only submitted applications and durable post-submission agent uploads may enter the sound feed.');
notification_assert_not_contains("'application'", $first_query['query'], 'Agent draft creation and field corrections must stay out of the sound feed.');
notification_assert_not_contains("'communication'", $first_query['query'], 'Draft email audit rows must not create a duplicate sound notification.');
notification_assert_not_contains("'document'", $first_query['query'], 'Ordinary document timeline rows must stay out of the sound feed.');
notification_assert_not_contains('app.status', $first_query['query'], 'Polling must use the event-time marker, not the application current stage.');
notification_assert_contains('ORDER BY activity.createdAt ASC, activity.id ASC', $first_query['query'], 'Timestamp ties must have a deterministic ID order.');
notification_assert_contains('LIMIT %d', $first_query['query'], 'The page size must remain parameterized.');
notification_assert_same(50, $first_query['args'][count($first_query['args']) - 1], 'The query must bind the declared page size.');

$wpdb->rows = array(
	notification_event_row(51, 'agent-document-upload'),
	notification_event_row(52, 'application'),
);
$wpdb->reset_reads();
$second_page = $plugin->rest_notification_events(new WP_REST_Request(array('since' => $first_data['cursor'])));
$second_data = $second_page->get_data();
notification_assert_same(2, count($second_data['events']), 'The next poll must drain events after the 50th row.');
notification_assert_same('document-uploaded', $second_data['events'][0]['type'], 'The durable upload activity kind must map to the existing document event API type.');
notification_assert_same(false, $second_data['hasMore'], 'A short page must complete the current drain.');
notification_assert_true(str_ends_with($second_data['cursor'], '|'), 'A completed drain must advance to a safe upper-bound cursor with an inclusive timestamp boundary.');

$second_query = $wpdb->prepared_calls[count($wpdb->prepared_calls) - 1];
notification_assert_contains('activity.createdAt > %s OR (activity.createdAt = %s AND activity.id > %s)', $second_query['query'], 'Continuation queries must use the timestamp-and-ID tuple.');
notification_assert_same('2020-07-30 10:00:00.123', $second_query['args'][0], 'The continuation must preserve millisecond precision.');
notification_assert_same('2020-07-30 10:00:00.123', $second_query['args'][1], 'The timestamp tie branch must use the same instant.');
notification_assert_same('event-050', $second_query['args'][2], 'The continuation must start strictly after the last delivered ID.');

$map_activity = $reflection->getMethod('map_activity_entry');
$map_activity->setAccessible(true);
$timeline_entry = $map_activity->invoke(
	$plugin,
	array(
		'id' => 'timeline-upload',
		'kind' => 'agent-document-upload',
		'title' => 'Passport uploaded',
		'detail' => 'passport.pdf attached to the case file.',
		'actorName' => 'External Agent',
		'createdAt' => '2020-07-30 10:00:00.123',
	)
);
notification_assert_same('document', $timeline_entry['kind'], 'Sound-eligible uploads must remain ordinary Document entries in the visible case timeline.');

$create_activity = $reflection->getMethod('create_activity');
$create_activity->setAccessible(true);
$wpdb->insert_calls = array();
$wpdb->insert_result = 1;
$create_activity->invoke($plugin, 'application-1', $external_agent, 'application', 'Agent update', null);
$external_insert = $wpdb->insert_calls[count($wpdb->insert_calls) - 1]['data'];
notification_assert_same('agent', $external_insert['actorRole'], 'Pure agent activity must enter the notification feed.');
notification_assert_true(1 === preg_match('/\.\d{3}$/', $external_insert['createdAt']), 'Activity timestamps must retain milliseconds to reduce ties.');

$create_activity->invoke($plugin, 'application-1', $dual_role_internal, 'application', 'Internal update', null);
$internal_insert = $wpdb->insert_calls[count($wpdb->insert_calls) - 1]['data'];
notification_assert_same('internal', $internal_insert['actorRole'], 'Dual-role internal activity must not masquerade as an agent event.');

$required_activity = $reflection->getMethod('create_required_activity');
$required_activity->setAccessible(true);
$wpdb->insert_result = 0;
$required_failed = false;
try {
	$required_activity->invoke(
		$plugin,
		'application-1',
		$external_agent,
		'document',
		'Document uploaded',
		null,
		'Required activity failed.'
	);
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	$required_failed = 'Required activity failed.' === $error->getMessage();
}
notification_assert_same(true, $required_failed, 'A mutation must fail when its required event activity cannot be inserted.');

$save_source = notification_method_source($reflection, 'save_admission_application');
$upload_source = notification_method_source($reflection, 'upload_admission_document');
$delete_source = notification_method_source($reflection, 'clear_document_record_and_touch_application');
$authorized_source = notification_method_source($reflection, 'get_authorized_application_base');
notification_assert_same(2, substr_count($save_source, '$this->create_required_activity('), 'Both create and update application paths must require an activity row.');
notification_assert_same(1, substr_count($upload_source, '$this->create_required_activity('), 'A successful document upload must require its activity row.');
notification_assert_contains('$this->get_authorized_application_base($application_id, $user, true)', $upload_source, 'Upload eligibility must be classified from a row locked inside the mutation transaction.');
notification_assert_contains('$should_notify_agent_document_upload ? self::NOTIFICATION_DOCUMENT_ACTIVITY_KIND : \'document\'', $upload_source, 'The event-time eligibility result must be persisted in the activity kind.');
notification_assert_contains("\$lock_sql = \$for_update ? ' FOR UPDATE' : ''", $authorized_source, 'The event-time application snapshot must support a transactional row lock.');
notification_assert_not_contains('NOTIFICATION_DOCUMENT_ACTIVITY_KIND', $delete_source, 'Document removals must remain timeline-only activities.');
notification_assert_contains("'document'", $delete_source, 'Document removals must preserve their visible Document timeline kind.');
notification_assert_not_contains('$this->create_activity(', $save_source, 'Application mutations must not use unchecked event inserts.');
notification_assert_not_contains('$this->create_activity(', $upload_source, 'Document uploads must not use unchecked event inserts.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
notification_assert_contains('Version: 0.2.61', $plugin_source, 'The plugin header must advertise version 0.2.61.');
notification_assert_same(0, $GLOBALS['mc_notification_event_mail_calls'], 'Offline event tests must never call wp_mail.');
notification_assert_same(0, $GLOBALS['mc_notification_event_network_calls'], 'Offline event tests must never access the network.');

echo 'Notification event reliability tests passed.' . PHP_EOL;
