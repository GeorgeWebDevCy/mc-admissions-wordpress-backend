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

final class WP_REST_Request implements ArrayAccess {
	private $route_params;
	private $json_params;
	private $body_params;
	private $file_params;

	public function __construct($route_params = array(), $json_params = array(), $body_params = array(), $file_params = array()) {
		$this->route_params = $route_params;
		$this->json_params = $json_params;
		$this->body_params = $body_params;
		$this->file_params = $file_params;
	}

	public function get_json_params() {
		return $this->json_params;
	}

	public function get_param($key) {
		return array_key_exists($key, $this->body_params) ? $this->body_params[$key] : null;
	}

	public function get_file_params() {
		return $this->file_params;
	}

	public function offsetExists(mixed $offset): bool {
		return array_key_exists((string) $offset, $this->route_params);
	}

	public function offsetGet(mixed $offset): mixed {
		return $this->route_params[(string) $offset] ?? null;
	}

	public function offsetSet(mixed $offset, mixed $value): void {
		$this->route_params[(string) $offset] = $value;
	}

	public function offsetUnset(mixed $offset): void {
		unset($this->route_params[(string) $offset]);
	}
}

final class MC_Document_Test_Role {
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

final class MC_Document_Test_Wpdb {
	public $row_results = array();
	public $rows_results = array();
	public $var_results = array();
	public $query_results = array();
	public $insert_result = 1;
	public $events = array();

	public function prepare($query, ...$args) {
		return $query;
	}

	public function get_row($query, $output = null) {
		$this->events[] = 'get_row:' . $query;
		return !empty($this->row_results) ? array_shift($this->row_results) : null;
	}

	public function get_results($query, $output = null) {
		$this->events[] = 'get_results:' . $query;
		return !empty($this->rows_results) ? array_shift($this->rows_results) : array();
	}

	public function get_var($query) {
		$this->events[] = 'get_var:' . $query;
		return !empty($this->var_results) ? array_shift($this->var_results) : null;
	}

	public function query($query) {
		$this->events[] = 'query:' . $query;
		return !empty($this->query_results) ? array_shift($this->query_results) : 1;
	}

	public function insert($table, $data, $format = null) {
		$this->events[] = 'insert:' . $table;
		return $this->insert_result;
	}
}

$GLOBALS['wpdb'] = new MC_Document_Test_Wpdb();
$GLOBALS['mc_document_roles'] = array();
$GLOBALS['mc_document_routes'] = array();
$GLOBALS['mc_document_remote_requests'] = array();
$GLOBALS['mc_document_uuid_counter'] = 0;
$GLOBALS['mc_document_current_user'] = null;

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_document_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Document_Test_Role($label);
	$GLOBALS['mc_document_roles'][$slug] = $role;
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
	$GLOBALS['mc_document_routes'][$namespace . $route] = $args;
	return true;
}

function wp_get_current_user() {
	return $GLOBALS['mc_document_current_user'];
}

function get_avatar_url($user_id, $args = array()) {
	return '';
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value) {
	return trim(strip_tags((string) $value));
}

function sanitize_file_name($value) {
	return preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $value);
}

function wp_generate_uuid4() {
	$GLOBALS['mc_document_uuid_counter']++;
	return 'offline-uuid-' . $GLOBALS['mc_document_uuid_counter'];
}

function wp_generate_password($length = 12, $special_chars = true, $extra_special_chars = false) {
	return substr('offlinepass', 0, $length);
}

function current_time($type, $gmt = false) {
	return '2026-07-29 12:00:00';
}

function rest_url($path = '') {
	return 'https://example.test/wp-json/' . ltrim((string) $path, '/');
}

function get_transient($key) {
	return array('access_token' => 'offline-token');
}

function set_transient($key, $value, $expiration) {
	return true;
}

function is_wp_error($value) {
	return false;
}

function wp_remote_request($url, $args = array()) {
	$method = isset($args['method']) ? (string) $args['method'] : 'GET';
	$GLOBALS['mc_document_remote_requests'][] = array('method' => $method, 'url' => (string) $url);

	if ('PUT' === $method) {
		return array(
			'response' => array('code' => 201),
			'body' => json_encode(array(
				'id' => 'new-storage-item',
				'name' => 'passport-offline.pdf',
				'webUrl' => 'https://example.test/new-storage-item',
				'parentReference' => array('path' => '/drive/root:/Admissions/application-1'),
			)),
		);
	}

	return array('response' => array('code' => 204), 'body' => '');
}

function wp_remote_retrieve_response_code($response) {
	return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response) {
	return isset($response['body']) ? (string) $response['body'] : '';
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function document_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}

function document_assert_contains($needle, $haystack, $message) {
	if (false === strpos($haystack, $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function document_assert_no_event_contains($needle, $events, $message) {
	foreach ($events as $event) {
		if (false !== strpos($event, $needle)) {
			throw new RuntimeException($message . ' Unexpected event: ' . $event);
		}
	}
}

function document_test_user($roles, $id = 1) {
	return (object) array(
		'ID' => $id,
		'user_login' => 'offline-user',
		'display_name' => 'Offline User',
		'user_email' => 'offline@example.test',
		'roles' => $roles,
		'allcaps' => array(),
	);
}

function document_application_base($wordpress_user_id = 1) {
	return array(
		'id' => 'application-1',
		'wordpressUserId' => $wordpress_user_id,
		'status' => 'review-pending',
	);
}

function document_event_index($events, $needle) {
	foreach ($events as $index => $event) {
		if (false !== strpos($event, $needle)) {
			return $index;
		}
	}

	return -1;
}

function document_method_source($reflection, $method_name) {
	$method = $reflection->getMethod($method_name);
	$lines = file($method->getFileName());
	return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
}

$plugin = mc_admissions_wordpress_backend();
$plugin->register_rest_routes();

$document_route_key = MC_Admissions_WordPress_Backend::API_NAMESPACE . '/applications/(?P<application_id>[A-Za-z0-9_-]+)/documents';
$document_routes = $GLOBALS['mc_document_routes'][$document_route_key] ?? null;
document_assert_same(true, is_array($document_routes), 'The shared document REST route must be registered.');
document_assert_same(3, count($document_routes), 'The document REST route must expose POST, PATCH, and DELETE callbacks.');
$callbacks_by_method = array();
foreach ($document_routes as $route) {
	$callbacks_by_method[$route['methods']] = $route['callback'][1];
}
document_assert_same('rest_upload_document', $callbacks_by_method['POST'] ?? null, 'POST must retain the upload callback.');
document_assert_same('rest_update_document_assessments', $callbacks_by_method['PATCH'] ?? null, 'PATCH must use the assessment callback.');
document_assert_same('rest_delete_document', $callbacks_by_method['DELETE'] ?? null, 'DELETE must use the removal callback.');

$missing_patch_version = $plugin->rest_update_document_assessments(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array('assessments' => array(array('documentType' => 'passport', 'assessmentStatus' => 'approved')))
));
document_assert_same(400, $missing_patch_version->get_status(), 'PATCH must require expectedUpdatedAt.');
document_assert_same('Application version is required.', $missing_patch_version->get_data()['error'], 'PATCH must explain the missing version.');

$missing_delete_version = $plugin->rest_delete_document(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array('documentType' => 'passport')
));
document_assert_same(400, $missing_delete_version->get_status(), 'DELETE must require expectedUpdatedAt.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('finance-officer'));
$finance_db = new MC_Document_Test_Wpdb();
$finance_db->row_results = array(document_application_base());
$GLOBALS['wpdb'] = $finance_db;
$finance_assessment = $plugin->rest_update_document_assessments(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'assessments' => array(array('documentType' => 'passport', 'assessmentStatus' => 'approved')),
		'expectedUpdatedAt' => '2026-07-29T10:11:12.345Z',
	)
));
document_assert_same(403, $finance_assessment->get_status(), 'Finance must not assess admission documents.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('administrator'));
$stale_assessment_db = new MC_Document_Test_Wpdb();
$stale_assessment_db->row_results = array(document_application_base());
$stale_assessment_db->rows_results = array(array(array(
	'id' => 'passport-document',
	'type' => 'passport',
	'label' => 'Copy of passport',
	'isReady' => 1,
	'assessmentStatus' => 'pending',
	'assessmentRemark' => null,
)));
$stale_assessment_db->query_results = array(1, 0, 1);
$GLOBALS['wpdb'] = $stale_assessment_db;
$stale_assessment = $plugin->rest_update_document_assessments(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'assessments' => array(array('documentType' => 'passport', 'assessmentStatus' => 'approved')),
		'expectedUpdatedAt' => '2026-07-29T10:11:12.345Z',
	)
));
document_assert_same(409, $stale_assessment->get_status(), 'A stale assessment PATCH must return HTTP 409.');
document_assert_same(MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR, $stale_assessment->get_data()['error'], 'PATCH must return the canonical stale message.');
document_assert_no_event_contains('INSERT INTO mc_admission_documents', $stale_assessment_db->events, 'A stale PATCH must not write a document assessment.');
document_assert_same(true, document_event_index($stale_assessment_db->events, 'AND updatedAt = %s') >= 0, 'PATCH must use an updatedAt CAS predicate.');
document_assert_same(true, document_event_index($stale_assessment_db->events, 'ROLLBACK') >= 0, 'A stale PATCH must roll back.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('mc_agent'));
$agent_delete_db = new MC_Document_Test_Wpdb();
$agent_delete_db->row_results = array(document_application_base());
$GLOBALS['wpdb'] = $agent_delete_db;
$forbidden_agent_delete = $plugin->rest_delete_document(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'documentType' => 'migrationSupportingDocuments',
		'expectedUpdatedAt' => '2026-07-29T10:11:12.345Z',
	)
));
document_assert_same(403, $forbidden_agent_delete->get_status(), 'Agents must not remove migration-side documents.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('administrator'));
$stale_delete_db = new MC_Document_Test_Wpdb();
$stale_delete_db->row_results = array(
	document_application_base(),
	array(
		'id' => 'passport-document',
		'type' => 'passport',
		'label' => 'Copy of passport',
		'originalName' => 'passport.pdf',
		'storageProvider' => 'microsoft-365',
		'storageDriveId' => 'drive-1',
		'storageItemId' => 'old-storage-item',
		'uploadedUrl' => '/document/passport',
	),
);
$stale_delete_db->query_results = array(1, 0, 1);
$GLOBALS['wpdb'] = $stale_delete_db;
$GLOBALS['mc_document_remote_requests'] = array();
$stale_delete = $plugin->rest_delete_document(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'documentType' => 'passport',
		'expectedUpdatedAt' => '2026-07-29T10:11:12.345Z',
	)
));
document_assert_same(409, $stale_delete->get_status(), 'A stale DELETE must return HTTP 409.');
document_assert_no_event_contains('UPDATE mc_admission_documents', $stale_delete_db->events, 'A stale DELETE must not clear document metadata.');
document_assert_same(array(), $GLOBALS['mc_document_remote_requests'], 'A stale DELETE must not remove any M365 object.');

$temp_file = tempnam(sys_get_temp_dir(), 'mc-document-route-');
if (false === $temp_file || false === file_put_contents($temp_file, '%PDF-offline')) {
	throw new RuntimeException('Unable to create the offline upload fixture.');
}

try {
	$stale_upload_db = new MC_Document_Test_Wpdb();
	$stale_upload_db->row_results = array(
		document_application_base(),
		array('id' => 'passport-document', 'storageDriveId' => 'drive-1', 'storageItemId' => 'old-storage-item'),
	);
	$stale_upload_db->var_results = array(
		'tenant-1', 'client-1', 'secret-1', 'drive-1', 'Admissions',
		'tenant-1', 'client-1', 'secret-1', 'drive-1', 'Admissions',
	);
	$stale_upload_db->query_results = array(1, 0, 1);
	$GLOBALS['wpdb'] = $stale_upload_db;
	$GLOBALS['mc_document_remote_requests'] = array();
	$GLOBALS['mc_document_current_user'] = document_test_user(array('administrator'));

	$stale_upload = $plugin->rest_upload_document(new WP_REST_Request(
		array('application_id' => 'application-1'),
		array(),
		array(
			'documentType' => 'passport',
			'expectedUpdatedAt' => '2026-07-29T10:11:12.345Z',
		),
		array('file' => array(
			'tmp_name' => $temp_file,
			'name' => 'passport.pdf',
			'type' => 'application/pdf',
			'size' => filesize($temp_file),
		))
	));

	document_assert_same(409, $stale_upload->get_status(), 'A versioned stale upload must return HTTP 409.');
	document_assert_no_event_contains('INSERT INTO mc_admission_documents', $stale_upload_db->events, 'A stale upload must not replace document metadata.');
	document_assert_same(array('PUT', 'DELETE'), array_column($GLOBALS['mc_document_remote_requests'], 'method'), 'A stale upload must clean up the newly stored M365 object.');
	document_assert_contains('new-storage-item', $GLOBALS['mc_document_remote_requests'][1]['url'], 'Upload rollback must delete the new object by exact item ID.');
	document_assert_same(false, false !== strpos($GLOBALS['mc_document_remote_requests'][1]['url'], 'old-storage-item'), 'Upload rollback must preserve the previously committed object.');
} finally {
	@unlink($temp_file);
}

$reflection = new ReflectionClass($plugin);
$can_assess_documents = $reflection->getMethod('can_assess_admission_documents');
$can_assess_documents->setAccessible(true);
$persist_assessments = $reflection->getMethod('persist_document_assessments');
$persist_assessments->setAccessible(true);
$clear_document = $reflection->getMethod('clear_document_record_and_touch_application');
$clear_document->setAccessible(true);

foreach (array('update_admission_document_assessments', 'delete_admission_document') as $method_name) {
	document_assert_contains(
		'return $this->to_admission_case($this->get_detailed_application_record($application_id))',
		document_method_source($reflection, $method_name),
		$method_name . ' must return a complete authoritative application.'
	);
}
$upload_method_source = document_method_source($reflection, 'upload_admission_document');
document_assert_contains(
	'$application = $this->get_detailed_application_record($application_id)',
	$upload_method_source,
	'upload_admission_document must reload the authoritative application after commit.'
);
document_assert_contains(
	'return $this->to_admission_case($application)',
	$upload_method_source,
	'upload_admission_document must return the reloaded authoritative application.'
);
$delete_method_source = document_method_source($reflection, 'delete_admission_document');
document_assert_same(
	true,
	strpos($delete_method_source, '$this->clear_document_record_and_touch_application(') < strpos($delete_method_source, '$this->delete_document_file('),
	'M365 deletion must occur only after the database removal transaction returns successfully.'
);
document_assert_same(
	true,
	strpos($upload_method_source, '$wpdb->query(\'COMMIT\')') < strpos($upload_method_source, '$this->delete_document_file($existing[\'storageDriveId\']'),
	'The previous M365 upload must be deleted only after the replacement transaction commits.'
);

document_assert_same(true, $can_assess_documents->invoke($plugin, array('roles' => array('administrator'))), 'Administrators must be able to assess documents.');
document_assert_same(true, $can_assess_documents->invoke($plugin, array('roles' => array('admissions-officer'))), 'Admissions officers must be able to assess documents.');
foreach (array('finance-officer', 'migration-officer', 'immigration-officer', 'registrar', 'mc_agent') as $role) {
	document_assert_same(false, $can_assess_documents->invoke($plugin, array('roles' => array($role))), $role . ' must not be able to assess documents.');
}

$assessment_success_db = new MC_Document_Test_Wpdb();
$assessment_success_db->query_results = array(1, 1, 1, 1);
$GLOBALS['wpdb'] = $assessment_success_db;
$persist_assessments->invoke(
	$plugin,
	'application-1',
	array(array(
		'documentType' => 'passport',
		'label' => 'Copy of passport',
		'assessmentStatus' => 'approved',
		'assessmentRemark' => null,
	)),
	array('passport' => array('id' => 'passport-document', 'isReady' => 1)),
	'2026-07-29 10:11:12.345',
	array('name' => 'Offline Admin', 'roles' => array('administrator'))
);
$assessment_parent_index = document_event_index($assessment_success_db->events, 'UPDATE mc_admission_applications');
$assessment_child_index = document_event_index($assessment_success_db->events, 'INSERT INTO mc_admission_documents');
document_assert_same(true, $assessment_parent_index >= 0 && $assessment_parent_index < $assessment_child_index, 'Assessment CAS must occur before child assessment writes.');
document_assert_same(true, document_event_index($assessment_success_db->events, 'COMMIT') > $assessment_child_index, 'Assessment parent and child writes must commit together.');

$delete_success_db = new MC_Document_Test_Wpdb();
$delete_success_db->query_results = array(1, 1, 1, 1);
$GLOBALS['wpdb'] = $delete_success_db;
$clear_document->invoke(
	$plugin,
	'application-1',
	array(
		'id' => 'passport-document',
		'type' => 'passport',
		'label' => 'Copy of passport',
		'originalName' => 'passport.pdf',
	),
	'2026-07-29 10:11:12.345',
	array('name' => 'Offline Admin', 'roles' => array('administrator'))
);
$delete_parent_index = document_event_index($delete_success_db->events, 'UPDATE mc_admission_applications');
$delete_child_index = document_event_index($delete_success_db->events, 'UPDATE mc_admission_documents');
document_assert_same(true, $delete_parent_index >= 0 && $delete_parent_index < $delete_child_index, 'DELETE CAS must occur before clearing document metadata.');
document_assert_same(true, document_event_index($delete_success_db->events, 'COMMIT') > $delete_child_index, 'DELETE parent and document changes must commit together.');

echo "Document route tests passed.\n";
