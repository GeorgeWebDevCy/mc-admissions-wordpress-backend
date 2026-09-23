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

function is_email($value) {
	return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL);
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

function document_application_base($wordpress_user_id = 1, array $overrides = array()) {
	if (is_array($wordpress_user_id)) {
		$overrides = $wordpress_user_id;
		$wordpress_user_id = 1;
	}

	return array_merge(
		array(
			'id' => 'application-1',
			'wordpressUserId' => $wordpress_user_id,
			'status' => 'review-pending',
			'updatedAt' => '2026-07-29 10:11:12.345',
		),
		$overrides
	);
}

function document_detailed_application(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'application-1',
			'referenceCode' => 'MC-DOC1',
			'wordpressUserId' => 0,
			'wordpressUsername' => null,
			'wordpressEmail' => null,
			'fullName' => 'Offline Applicant',
			'passportNumber' => 'OFFLINE',
			'email' => 'student@example.test',
			'phone' => '+357000000',
			'birthday' => '01/01/2000',
			'address' => 'Offline address',
			'city' => 'Nicosia',
			'postalCode' => '1000',
			'country' => 'Cyprus',
			'gender' => 'Other',
			'semester' => 'fall',
			'year' => '2026',
			'applicationRoute' => 'standard',
			'programmeCode' => 'english-foundation',
			'programmeLabel' => 'English Foundation Year',
			'agencyName' => 'Offline Agency',
			'consultantName' => 'Offline Consultant',
			'consultantEmail' => 'offline-agency@example.test',
			'consultantPhone' => '+357111111',
			'submissionDate' => '29/07/2026',
			'tuitionAcknowledged' => 1,
			'offerTermsAcknowledged' => 1,
			'gdprAcknowledged' => 1,
			'isTestData' => 1,
			'status' => 'review-pending',
			'workflowNote' => 'Under review',
			'reviewSummary' => null,
			'reviewerDecision' => 'academically-cleared',
			'decisionDueDate' => null,
			'offerIssuedDate' => null,
			'offerExpiryDate' => null,
			'offerConditionNote' => null,
			'classesStartDate' => '01/09/2026',
			'tuitionFeeFirstYear' => '7000.00',
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
			'lastUpdatedByName' => 'Offline User',
			'source' => 'offline',
			'createdAt' => '2026-07-29 09:00:00.000',
			'updatedAt' => '2026-07-29 10:11:13.345',
		),
		$overrides
	);
}

function document_uploaded_record(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'passport-document',
			'applicationId' => 'application-1',
			'type' => 'passport',
			'label' => 'Copy of passport',
			'isReady' => 1,
			'assessmentStatus' => 'pending',
			'assessmentRemark' => null,
			'assessedAt' => null,
			'assessedByName' => null,
			'uploadedUrl' => '/api/admissions/application-1/documents/passport-document/file',
			'storedFilename' => 'passport-offline.pdf',
			'storageProvider' => 'microsoft-365',
			'storageDriveId' => 'drive-1',
			'storageItemId' => 'new-storage-item',
			'storagePath' => '/drive/root:/Admissions/application-1',
			'storageWebUrl' => 'https://example.test/new-storage-item',
			'originalName' => 'passport.pdf',
			'mimeType' => 'application/pdf',
			'fileSizeBytes' => 12,
			'uploadedAt' => '2026-07-29T12:00:00+00:00',
			'uploadedByName' => 'Offline User',
			'createdAt' => '2026-07-29 12:00:00.000',
			'updatedAt' => '2026-07-29 12:00:00.000',
		),
		$overrides
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
	$GLOBALS['mc_document_remote_requests'] = array();
	$legacy_upload_db = new MC_Document_Test_Wpdb();
	$legacy_uploaded_document = document_uploaded_record();
	$legacy_upload_db->row_results = array(
		document_application_base(),
		null,
		document_application_base(),
		$legacy_uploaded_document,
		document_detailed_application(),
		null,
		null,
	);
	$legacy_upload_db->rows_results = array(
		array($legacy_uploaded_document),
		array(),
		array(),
	);
	$legacy_upload_db->var_results = array('tenant-1', 'client-1', 'secret-1', 'drive-1', 'Admissions');
	$legacy_upload_db->query_results = array(1, 1, 1, 1);
	$GLOBALS['wpdb'] = $legacy_upload_db;
	$GLOBALS['mc_document_current_user'] = document_test_user(array('administrator'));

	$legacy_upload = $plugin->rest_upload_document(new WP_REST_Request(
		array('application_id' => 'application-1'),
		array(),
		array('documentType' => 'passport'),
		array('file' => array(
			'tmp_name' => $temp_file,
			'name' => 'passport.pdf',
			'type' => 'application/pdf',
			'size' => filesize($temp_file),
		))
	));
	document_assert_same(200, $legacy_upload->get_status(), 'A legacy versionless upload must use a server-captured application revision.');
	document_assert_same(array('PUT'), array_column($GLOBALS['mc_document_remote_requests'], 'method'), 'A successful legacy upload must store exactly one M365 object.');
	document_assert_same(true, document_event_index($legacy_upload_db->events, 'AND updatedAt = %s') >= 0, 'A legacy upload must recheck the captured revision with a database CAS predicate.');
	document_assert_same('2026-07-29T10:11:13.345Z', $legacy_upload->get_data()['application']['updatedAt'], 'A legacy upload must return the committed authoritative revision.');
	document_assert_same('passport-document', $legacy_upload->get_data()['application']['documents'][0]['id'], 'A legacy upload response must expose the saved document.');

	$invalid_upload_db = new MC_Document_Test_Wpdb();
	$GLOBALS['wpdb'] = $invalid_upload_db;
	$GLOBALS['mc_document_remote_requests'] = array();
	$invalid_upload = $plugin->rest_upload_document(new WP_REST_Request(
		array('application_id' => 'application-1'),
		array(),
		array(
			'documentType' => 'passport',
			'expectedUpdatedAt' => 'not-a-valid-version',
		),
		array('file' => array(
			'tmp_name' => $temp_file,
			'name' => 'passport.pdf',
			'type' => 'application/pdf',
			'size' => filesize($temp_file),
		))
	));
	document_assert_same(400, $invalid_upload->get_status(), 'A supplied invalid upload revision must return HTTP 400.');
	document_assert_same('Invalid application version.', $invalid_upload->get_data()['error'], 'An invalid upload revision must return the canonical validation error.');
	document_assert_same(array(), $invalid_upload_db->events, 'An invalid upload revision must fail before database access.');
	document_assert_same(array(), $GLOBALS['mc_document_remote_requests'], 'An invalid upload revision must fail before M365 storage.');

	$legacy_race_db = new MC_Document_Test_Wpdb();
	$legacy_race_db->row_results = array(
		document_application_base(),
		array('id' => 'passport-document', 'storageDriveId' => 'drive-1', 'storageItemId' => 'old-storage-item'),
		document_application_base(array('updatedAt' => '2026-07-29 10:11:13.345')),
	);
	$legacy_race_db->var_results = array(
		'tenant-1', 'client-1', 'secret-1', 'drive-1', 'Admissions',
		'tenant-1', 'client-1', 'secret-1', 'drive-1', 'Admissions',
	);
	$legacy_race_db->query_results = array(1, 1);
	$GLOBALS['wpdb'] = $legacy_race_db;
	$GLOBALS['mc_document_remote_requests'] = array();
	$legacy_race_upload = $plugin->rest_upload_document(new WP_REST_Request(
		array('application_id' => 'application-1'),
		array(),
		array('documentType' => 'passport'),
		array('file' => array(
			'tmp_name' => $temp_file,
			'name' => 'passport.pdf',
			'type' => 'application/pdf',
			'size' => filesize($temp_file),
		))
	));
	document_assert_same(409, $legacy_race_upload->get_status(), 'A legacy upload must conflict when the captured revision changes during M365 storage.');
	document_assert_same(MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR, $legacy_race_upload->get_data()['error'], 'A legacy upload race must return the canonical stale error.');
	document_assert_no_event_contains('INSERT INTO mc_admission_documents', $legacy_race_db->events, 'A losing legacy upload must not replace document metadata.');
	document_assert_same(array('PUT', 'DELETE'), array_column($GLOBALS['mc_document_remote_requests'], 'method'), 'A losing legacy upload must clean up its newly stored M365 object.');
	document_assert_contains('new-storage-item', $GLOBALS['mc_document_remote_requests'][1]['url'], 'Legacy upload cleanup must delete the new object by exact item ID.');
	document_assert_same(false, false !== strpos($GLOBALS['mc_document_remote_requests'][1]['url'], 'old-storage-item'), 'Legacy upload cleanup must preserve the previously committed object.');

	$preflight_stale_db = new MC_Document_Test_Wpdb();
	$preflight_stale_db->row_results = array(document_application_base(array(
		'updatedAt' => '2026-07-29 10:11:13.345',
	)));
	$GLOBALS['wpdb'] = $preflight_stale_db;
	$GLOBALS['mc_document_remote_requests'] = array();
	$GLOBALS['mc_document_current_user'] = document_test_user(array('administrator'));
	$preflight_stale_upload = $plugin->rest_upload_document(new WP_REST_Request(
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
	document_assert_same(409, $preflight_stale_upload->get_status(), 'A supplied stale upload revision must be rejected before remote storage.');
	document_assert_same(array(), $GLOBALS['mc_document_remote_requests'], 'A preflight-stale upload must not create an M365 object.');

	$stale_upload_db = new MC_Document_Test_Wpdb();
	$stale_upload_db->row_results = array(
		document_application_base(),
		array('id' => 'passport-document', 'storageDriveId' => 'drive-1', 'storageItemId' => 'old-storage-item'),
		document_application_base(),
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
$filter_agent_case = $reflection->getMethod('application_case_response_for_user');
$filter_agent_case->setAccessible(true);
$filter_agent_board = $reflection->getMethod('application_board_response_for_user');
$filter_agent_board->setAccessible(true);
$get_document_download = $reflection->getMethod('get_admission_document_download');
$get_document_download->setAccessible(true);
$get_application_case_source = document_method_source($reflection, 'get_admission_application_case');
document_assert_contains(
	'$this->get_authorized_application_base($application_id, $user)',
	$get_application_case_source,
	'Opening a case must authorize the application owner before loading its detail.'
);
document_assert_contains(
	'$this->application_case_response_for_user($case, $user)',
	$get_application_case_source,
	'Opening a case must apply the role-specific response boundary.'
);

$agent_user = array('id' => 42, 'name' => 'Offline Agent', 'roles' => array('mc_agent'));
$staff_user = array('id' => 7, 'name' => 'Offline Admin', 'roles' => array('administrator'));
$agent_case_fixture = array_merge(
	document_detailed_application(array(
		'wordpressUsername' => 'offline-agent',
		'wordpressEmail' => 'offline-agent@example.test',
		'permitStatus' => 'approved',
	)),
	array(
		'recordId' => 'application-1',
		'studentName' => 'Offline Applicant',
		'agentName' => 'Offline Agency',
		'programme' => 'English Foundation Year',
		'semesterCode' => 'fall',
		'stage' => 'Migration documents',
		'stageKey' => 'migration-documents',
		'lane' => 'migration',
		'progress' => 72,
		'missingDocs' => 1,
		'readyDocuments' => 3,
		'totalIntakeDocuments' => 6,
		'intakeMissingDocs' => 0,
		'intakeReadyDocuments' => 6,
		'totalMigrationDocuments' => 4,
		'migrationMissingDocs' => 1,
		'migrationReadyDocuments' => 3,
		'totalImmigrationDocuments' => 10,
		'immigrationMissingDocs' => 10,
		'immigrationReadyDocuments' => 0,
		'activeDocumentPack' => 'migration',
		'documents' => array(array(
			'id' => 'passport-document',
			'type' => 'passport',
			'label' => 'Copy of passport',
			'isReady' => true,
			'assessmentStatus' => 'approved',
			'assessmentRemark' => 'Readable copy.',
			'assessedAt' => '2026-07-29T12:30:00.000Z',
			'assessedByName' => 'Internal Reviewer',
			'uploadedUrl' => '/api/admissions/application-1/documents/passport-document/file',
			'originalName' => 'passport.pdf',
			'mimeType' => 'application/pdf',
			'fileSizeBytes' => 12,
			'uploadedAt' => '2026-07-29T12:00:00.000Z',
			'uploadedByName' => 'Internal Staff',
			'storageItemId' => 'private-storage-id',
		), array(
			'id' => 'medical-document',
			'type' => 'medicalCertificate',
			'label' => 'Medical certificate',
			'isReady' => true,
			'uploadedUrl' => '/api/admissions/application-1/documents/medical-document/file',
			'originalName' => 'internal-medical.pdf',
			'mimeType' => 'application/pdf',
			'fileSizeBytes' => 24,
			'uploadedAt' => '2026-07-30T12:00:00.000Z',
			'uploadedByName' => 'Internal Immigration',
		)),
		'letters' => array(array(
			'id' => 'letter-1',
			'templateId' => 'acceptance-letter',
			'templateLabel' => 'Acceptance letter',
			'templateVersion' => '2026-07',
			'stageKey' => 'acceptance-issued',
			'fileName' => 'acceptance.pdf',
			'outputFormat' => 'pdf',
			'outputUrl' => '/api/admissions/application-1/letters/letter-1/file',
			'generatedAt' => '2026-07-29T13:00:00.000Z',
			'generatedByName' => 'Internal Admissions',
		)),
		'assessmentMessageHistory' => array(array(
			'id' => 'internal-assessment-message',
			'message' => 'Internal assessment history marker.',
		)),
		'reviewSummary' => 'Internal academic assessment.',
		'decisionDueDate' => '2026-08-15',
		'workflowNote' => 'Internal workflow note.',
		'financeNote' => 'Internal finance note.',
		'paymentStatus' => 'cleared',
		'paymentAmount' => '4000.00',
		'paymentReference' => 'PRIVATE-PAYMENT-REFERENCE',
		'paymentTransactions' => array(array('id' => 'payment-1', 'note' => 'Internal payment note.')),
		'permitReference' => 'MP-PRIVATE',
		'permitNote' => 'Internal permit note.',
		'migrationCase' => array('id' => 'migration-1', 'note' => 'Internal migration note.'),
		'immigrationCase' => array('id' => 'immigration-1', 'note' => 'Internal immigration note.'),
		'commissions' => array(array('id' => 'commission-1')),
		'refunds' => array(array('id' => 'refund-1')),
		'letterDrafts' => array(array('id' => 'draft-1', 'body' => 'Internal draft.')),
		'communications' => array(array('id' => 'communication-1', 'detail' => 'Internal email audit.')),
		'activity' => array(array('id' => 'activity-1', 'detail' => 'Internal activity.')),
	)
);

$filtered_agent_case = $filter_agent_case->invoke($plugin, $agent_case_fixture, $agent_user);
foreach (array(
	'fullName', 'passportNumber', 'email', 'phone', 'birthday', 'address', 'city',
	'postalCode', 'country', 'gender', 'semesterCode', 'year', 'programmeCode',
	'consultantName', 'consultantEmail', 'consultantPhone', 'submissionDate',
	'tuitionAcknowledged', 'offerTermsAcknowledged', 'gdprAcknowledged',
) as $submitted_field) {
	document_assert_same(
		$agent_case_fixture[$submitted_field],
		$filtered_agent_case[$submitted_field] ?? null,
		'Agent case responses must retain submitted field ' . $submitted_field . '.'
	);
}
document_assert_same('approved', $filtered_agent_case['permitStatus'], 'Agent case responses must expose the current permit status.');
document_assert_same(1, count($filtered_agent_case['documents']), 'Agent case responses must retain submitted document metadata.');
document_assert_same(
	'/api/admissions/application-1/documents/passport-document/file',
	$filtered_agent_case['documents'][0]['uploadedUrl'],
	'Agent document metadata must retain the authenticated download URL.'
);
$agent_case_json = json_encode($filtered_agent_case);
document_assert_same(false, false !== strpos((string) $agent_case_json, 'medical-document'), 'Agent case responses must exclude staff-side migration and immigration documents.');
document_assert_same(false, false !== strpos((string) $agent_case_json, 'internal-medical.pdf'), 'Agent case responses must exclude staff-side document metadata and URLs.');
document_assert_same(false, array_key_exists('assessmentMessageHistory', $filtered_agent_case), 'Agent mutation responses must not carry assessment-message history through the external response sanitizer.');
document_assert_same(false, array_key_exists('storageItemId', $filtered_agent_case['documents'][0]), 'Agent document metadata must not expose storage identifiers.');
document_assert_same(false, array_key_exists('assessedByName', $filtered_agent_case['documents'][0]), 'Agent document metadata must not expose internal reviewer identity.');
document_assert_same(false, array_key_exists('assessmentStatus', $filtered_agent_case['documents'][0]), 'Agent document metadata must not expose internal assessment status.');
document_assert_same(false, array_key_exists('assessmentRemark', $filtered_agent_case['documents'][0]), 'Agent document metadata must not expose internal assessment remarks.');
document_assert_same(false, array_key_exists('assessedAt', $filtered_agent_case['documents'][0]), 'Agent document metadata must not expose internal assessment timestamps.');
document_assert_same(1, count($filtered_agent_case['letters']), 'Agent case responses must retain safe generated-letter metadata.');
document_assert_same(
	'/api/admissions/application-1/letters/letter-1/file',
	$filtered_agent_case['letters'][0]['outputUrl'],
	'Agent generated-letter metadata must retain the authenticated download URL.'
);
document_assert_same(false, array_key_exists('generatedByName', $filtered_agent_case['letters'][0]), 'Agent generated-letter metadata must not expose internal generator identity.');
foreach (array('generatedByName', 'assessmentStatus', 'assessmentRemark', 'assessedAt') as $internal_marker) {
	document_assert_same(
		false,
		false !== strpos((string) $agent_case_json, $internal_marker),
		'Agent case responses must not expose internal marker ' . $internal_marker . '.'
	);
}
$safe_placeholders = array(
	'activity' => array(),
	'communications' => array(),
	'letterDrafts' => array(),
	'paymentTransactions' => array(),
	'commissions' => array(),
	'refunds' => array(),
	'workflowNote' => null,
	'reviewerDecision' => 'pending',
	'reviewSummary' => null,
	'decisionDueDate' => null,
	'offerIssuedDate' => null,
	'offerExpiryDate' => null,
	'offerConditionNote' => null,
	'classesStartDate' => null,
	'tuitionFeeFirstYear' => null,
	'tuitionFeeFollowingYears' => null,
	'termBalanceApplies' => false,
	'paymentStatus' => 'awaiting-invoice',
	'paymentAmount' => null,
	'paymentCurrency' => 'EUR',
	'paymentReference' => null,
	'paymentConfirmedDate' => null,
	'financeNote' => null,
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
	'migrationCase' => null,
	'immigrationCase' => null,
);
foreach ($safe_placeholders as $field => $expected_value) {
	document_assert_same(true, array_key_exists($field, $filtered_agent_case), 'Agent case responses must remain shape-complete for ' . $field . '.');
	document_assert_same($expected_value, $filtered_agent_case[$field], 'Agent case responses must neutralize hidden field ' . $field . '.');
}
document_assert_same($agent_case_fixture, $filter_agent_case->invoke($plugin, $agent_case_fixture, $staff_user), 'Internal staff case responses must remain unchanged.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('mc_agent'), 42);
$agent_library_db = new MC_Document_Test_Wpdb();
$agent_library_db->rows_results = array(
	array(array(
		'id' => 'application-1',
		'referenceCode' => 'MC-DOC1',
		'fullName' => 'Offline Applicant',
		'agencyName' => 'Offline Agency',
	)),
	array(array(
		'id' => 'letter-1',
		'applicationId' => 'application-1',
		'templateLabel' => 'Acceptance letter',
		'fileName' => 'acceptance.pdf',
		'createdAt' => '2026-07-29 13:00:00.000',
		'generatedByName' => 'Internal Admissions',
	)),
	array(
		array(
			'id' => 'passport-document',
			'type' => 'passport',
			'label' => 'Copy of passport',
			'originalName' => 'passport.pdf',
			'uploadedByName' => 'Internal Staff',
			'uploadedAt' => '2026-07-29 12:00:00.000',
			'createdAt' => '2026-07-29 12:00:00.000',
			'mimeType' => 'application/pdf',
			'uploadedUrl' => '/api/admissions/application-1/documents/passport-document/file',
		),
		array(
			'id' => 'medical-document',
			'type' => 'medicalCertificate',
			'label' => 'Medical certificate',
			'originalName' => 'internal-medical.pdf',
			'uploadedByName' => 'Internal Immigration',
			'uploadedAt' => '2026-07-30 12:00:00.000',
			'createdAt' => '2026-07-30 12:00:00.000',
			'mimeType' => 'application/pdf',
			'uploadedUrl' => '/api/admissions/application-1/documents/medical-document/file',
		),
	),
);
$agent_library_db->var_results = array('mc_generated_letters');
$GLOBALS['wpdb'] = $agent_library_db;
$agent_library = $plugin->rest_get_document_library();
document_assert_same(200, $agent_library->get_status(), 'Agents must retain their scoped document library.');
$agent_library_application = $agent_library->get_data()['applications'][0];
document_assert_same(1, count($agent_library_application['documents']), 'The agent document library must exclude staff-side migration and immigration documents.');
document_assert_same('passport', $agent_library_application['documents'][0]['type'], 'The agent document library must retain intake-side documents.');
document_assert_same(false, array_key_exists('uploadedByName', $agent_library_application['documents'][0]), 'The agent document library must not expose internal uploader identity.');
document_assert_same(false, array_key_exists('generatedByName', $agent_library_application['generatedLetters'][0]), 'The agent document library must not expose internal letter generator identity.');
$agent_library_events = implode("\n", $agent_library_db->events);
document_assert_contains('type IN (%s', $agent_library_events, 'The agent document library must restrict intake document types in SQL before applying its limit.');
document_assert_same(
	true,
	strpos($agent_library_events, 'type IN (%s') < strpos($agent_library_events, 'ORDER BY updatedAt DESC, createdAt DESC LIMIT 12'),
	'The intake-document SQL predicate must be applied before ORDER BY and LIMIT.'
);

$agent_board_fixture = array(
	'recordId' => 'application-1',
	'id' => 'MC-DOC1',
	'studentName' => 'Offline Applicant',
	'passportNumber' => 'OFFLINE',
	'agentName' => 'Offline Agency',
	'programme' => 'English Foundation Year',
	'semester' => 'fall 2026',
	'stage' => 'Migration documents',
	'stageKey' => 'migration-documents',
	'reviewerDecision' => 'academically-cleared',
	'permitStatus' => 'approved',
	'lane' => 'migration',
	'progress' => 72,
	'updatedAt' => '2026-07-29T12:00:00.000Z',
	'isLive' => true,
	'permitReference' => 'MP-PRIVATE',
	'commissionStatus' => 'payable',
	'refundStatus' => 'requested',
	'workflowNote' => 'Internal workflow note.',
	'updatedByName' => 'Internal Staff',
);
$filtered_agent_board = $filter_agent_board->invoke($plugin, $agent_board_fixture, $agent_user);
document_assert_same('OFFLINE', $filtered_agent_board['passportNumber'], 'Agent board responses must retain passport-number search data.');
document_assert_same('approved', $filtered_agent_board['permitStatus'], 'Agent board responses must retain the current permit status.');
foreach (array('reviewerDecision', 'permitReference', 'commissionStatus', 'refundStatus', 'workflowNote', 'updatedByName') as $internal_field) {
	document_assert_same(false, array_key_exists($internal_field, $filtered_agent_board), 'Agent board responses must not expose internal field ' . $internal_field . '.');
}

$owner_download_db = new MC_Document_Test_Wpdb();
$owner_download_db->row_results = array(
	document_application_base(42, array('status' => 'migration-documents')),
	array(
		'type' => 'passport',
		'label' => 'Copy of passport',
		'originalName' => 'passport.pdf',
		'mimeType' => 'application/pdf',
		'storageDriveId' => 'drive-1',
		'storageItemId' => 'item-1',
	),
);
$GLOBALS['wpdb'] = $owner_download_db;
$owner_download = $get_document_download->invoke($plugin, array(
	'applicationId' => 'application-1',
	'documentId' => 'passport-document',
	'user' => $agent_user,
));
document_assert_same('item-1', $owner_download['storageItemId'], 'The owning agent must retain document streaming access after submission.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('mc_agent'), 42);
$internal_download_db = new MC_Document_Test_Wpdb();
$internal_download_db->row_results = array(
	document_application_base(42, array('status' => 'migration-documents')),
	array(
		'type' => 'medicalCertificate',
		'label' => 'Medical certificate',
		'originalName' => 'internal-medical.pdf',
		'mimeType' => 'application/pdf',
		'storageDriveId' => 'drive-1',
		'storageItemId' => 'internal-item-1',
	),
);
$GLOBALS['wpdb'] = $internal_download_db;
$internal_download = $plugin->rest_download_document_file(new WP_REST_Request(array(
	'application_id' => 'application-1',
	'document_id' => 'medical-document',
)));
document_assert_same(403, $internal_download->get_status(), 'An owning agent must not stream staff-side migration or immigration documents.');

$staff_internal_download_db = new MC_Document_Test_Wpdb();
$staff_internal_download_db->row_results = array(
	document_application_base(42, array('status' => 'migration-documents')),
	array(
		'type' => 'medicalCertificate',
		'label' => 'Medical certificate',
		'originalName' => 'internal-medical.pdf',
		'mimeType' => 'application/pdf',
		'storageDriveId' => 'drive-1',
		'storageItemId' => 'internal-item-1',
	),
);
$GLOBALS['wpdb'] = $staff_internal_download_db;
$staff_internal_download = $get_document_download->invoke($plugin, array(
	'applicationId' => 'application-1',
	'documentId' => 'medical-document',
	'user' => $staff_user,
));
document_assert_same('internal-item-1', $staff_internal_download['storageItemId'], 'Internal staff must retain staff-side document access.');

$foreign_download_db = new MC_Document_Test_Wpdb();
$foreign_download_db->row_results = array(document_application_base(99, array('status' => 'migration-documents')));
$GLOBALS['wpdb'] = $foreign_download_db;
$foreign_download_error = null;
try {
	$get_document_download->invoke($plugin, array(
		'applicationId' => 'application-1',
		'documentId' => 'passport-document',
		'user' => $agent_user,
	));
} catch (Throwable $error) {
	$foreign_download_error = $error->getMessage();
}
document_assert_same('You are not allowed to access this application.', $foreign_download_error, 'An agent must not stream another agency\'s document.');
document_assert_same(1, count($foreign_download_db->events), 'A rejected cross-agency download must stop before document metadata is queried.');

$GLOBALS['mc_document_current_user'] = document_test_user(array('mc_agent'), 42);
$restricted_panel_db = new MC_Document_Test_Wpdb();
$GLOBALS['wpdb'] = $restricted_panel_db;
document_assert_same(403, $plugin->rest_list_payments(new WP_REST_Request(array('application_id' => 'application-1')))->get_status(), 'Agents must not read internal payment transactions directly.');
document_assert_same(403, $plugin->rest_get_migration_case(new WP_REST_Request(array('application_id' => 'application-1')))->get_status(), 'Agents must not read internal migration case details directly.');
document_assert_same(403, $plugin->rest_get_immigration_case(new WP_REST_Request(array('application_id' => 'application-1')))->get_status(), 'Agents must not read internal immigration case details directly.');
document_assert_same(array(), $restricted_panel_db->events, 'Restricted agent sub-panel reads must fail before database access.');

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
