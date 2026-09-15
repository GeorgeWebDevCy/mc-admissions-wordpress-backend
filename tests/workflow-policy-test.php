<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class MC_Admissions_Test_Role {
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

final class MC_Admissions_Test_Wpdb {
	public $query_results = array();
	public $get_var_result = null;
	public $get_row_result = null;
	public $update_result = 1;
	public $insert_result = 1;
	public $migration_cases_table_available = true;
	public $entry_permit_expiry_column = true;
	public $events = array();
	public $updates = array();
	public $inserts = array();

	public function prepare($query, ...$args) {
		return $query;
	}

	public function get_var($query) {
		$this->events[] = 'get_var:' . $query;
		if (false !== strpos($query, 'SHOW TABLES LIKE')) {
			return $this->migration_cases_table_available ? 'mc_admission_migration_cases' : null;
		}
		if (false !== strpos($query, "SHOW COLUMNS FROM mc_admission_migration_cases LIKE 'entryPermitExpiryDate'")) {
			return $this->entry_permit_expiry_column ? 'entryPermitExpiryDate' : null;
		}
		return $this->get_var_result;
	}

	public function get_row($query, $output = null) {
		$this->events[] = 'get_row:' . $query;
		return $this->get_row_result;
	}

	public function query($query) {
		$this->events[] = 'query:' . $query;
		$result = !empty($this->query_results) ? array_shift($this->query_results) : 1;
		if (
			false !== $result
			&& false !== strpos($query, 'ALTER TABLE mc_admission_migration_cases ADD COLUMN entryPermitExpiryDate')
		) {
			$this->entry_permit_expiry_column = true;
		}
		return $result;
	}

	public function update($table, $data, $where) {
		$this->events[] = 'update:' . $table;
		$this->updates[] = array('table' => $table, 'data' => $data, 'where' => $where);
		return $this->update_result;
	}

	public function insert($table, $data) {
		$this->events[] = 'insert:' . $table;
		$this->inserts[] = array('table' => $table, 'data' => $data);
		return $this->insert_result;
	}
}

$GLOBALS['wpdb'] = new MC_Admissions_Test_Wpdb();
$GLOBALS['mc_admissions_test_roles'] = array();
$GLOBALS['mc_admissions_test_options'] = array(
	'mc_admissions_notification_activity_schema_version' => '1',
	'mc_admissions_application_test_data_schema_version' => '1',
	'mc_admissions_resource_index_version' => '1',
	'mc_admissions_schema_version' => '0.2.14',
	'mc_admissions_migration_case_schema_version' => '0.2.64',
	'mc_admissions_offer_detail_schema_version' => '0.2.38',
	'mc_admissions_case_detail_schema_version' => '0.2.45',
	'mc_admissions_document_assessment_schema_version' => '1',
	'mc_admissions_finance_workspace_schema_version' => '0.2.61',
	'mc_admissions_intake_capacity_schema_version' => '0.2.62',
);

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return isset($GLOBALS['mc_admissions_test_roles'][$slug])
		? $GLOBALS['mc_admissions_test_roles'][$slug]
		: null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Admissions_Test_Role($label);
	$GLOBALS['mc_admissions_test_roles'][$slug] = $role;

	return $role;
}

function get_option($key, $fallback = false) {
	return array_key_exists($key, $GLOBALS['mc_admissions_test_options'])
		? $GLOBALS['mc_admissions_test_options'][$key]
		: $fallback;
}

function update_option($key, $value, $autoload = null) {
	$GLOBALS['mc_admissions_test_options'][$key] = $value;
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

function plugin_basename($file) {
	return 'mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php';
}

function trailingslashit($path) {
	return rtrim((string) $path, '/\\') . '/';
}

function is_email($email) {
	return false !== filter_var((string) $email, FILTER_VALIDATE_EMAIL);
}

function get_userdata($user_id) {
	return false;
}

function sanitize_text_field($value) {
	return trim(strip_tags((string) $value));
}

function sanitize_textarea_field($value) {
	return trim(strip_tags((string) $value));
}

function current_time($type, $gmt = false) {
	return '2026-09-15 10:00:00';
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$stale_policy = $reflection->getMethod('should_apply_stale_workflow_target');
$stale_policy->setAccessible(true);
$document_pack = $reflection->getMethod('active_document_pack_for_status');
$document_pack->setAccessible(true);
$allowed_operations = $reflection->getMethod('allowed_operations_fields_for_user');
$allowed_operations->setAccessible(true);
$workflow_permission = $reflection->getMethod('can_manage_workflow_status');
$workflow_permission->setAccessible(true);
$update_workflow = $reflection->getMethod('update_admission_application_workflow');
$update_workflow->setAccessible(true);
$normalize_operations = $reflection->getMethod('normalize_operations_draft');
$normalize_operations->setAccessible(true);
$authorize_operations = $reflection->getMethod('assert_operations_patch_authorized');
$authorize_operations->setAccessible(true);
$document_upload_policy = $reflection->getMethod('can_upload_admission_document');
$document_upload_policy->setAccessible(true);
$case_record_upsert = $reflection->getMethod('upsert_case_record_and_touch_application');
$case_record_upsert->setAccessible(true);
$mutation_error_status = $reflection->getMethod('mutation_error_status');
$mutation_error_status->setAccessible(true);
$ensure_migration_case_columns = $reflection->getMethod('ensure_migration_case_columns');
$ensure_migration_case_columns->setAccessible(true);
$migration_case_update_data = $reflection->getMethod('migration_case_update_data');
$migration_case_update_data->setAccessible(true);
$map_migration_case = $reflection->getMethod('map_migration_case');
$map_migration_case->setAccessible(true);
$programme_label_from_code = $reflection->getMethod('programme_label_from_code');
$programme_label_from_code->setAccessible(true);
$resolve_programme_label = $reflection->getMethod('resolve_programme_label');
$resolve_programme_label->setAccessible(true);
$to_board_application = $reflection->getMethod('to_board_application');
$to_board_application->setAccessible(true);

function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function assert_contains($needle, $haystack, $message) {
	if (!in_array($needle, $haystack, true)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function assert_not_contains($needle, $haystack, $message) {
	if (in_array($needle, $haystack, true)) {
		throw new RuntimeException($message . ' Unexpected ' . var_export($needle, true) . '.');
	}
}

function assert_throws($callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		return;
	}

	throw new RuntimeException($message);
}

assert_same(
	"Master's degree in Business Administration (MBA)",
	$programme_label_from_code->invoke($plugin, 'business-administration-masters'),
	'The WordPress backend must recognize the canonical Master\'s programme code.'
);
assert_same(
	"Master's degree in Business Administration (MBA)",
	$resolve_programme_label->invoke(
		$plugin,
		array(
			'programmeCode' => 'business-administration-masters',
			'programmeLabel' => 'Programme not selected',
		)
	),
	'A stale fallback label must be repaired from a recognized programme code.'
);
assert_same(
	'Legacy custom programme label',
	$resolve_programme_label->invoke(
		$plugin,
		array(
			'programmeCode' => 'business-administration-masters',
			'programmeLabel' => 'Legacy custom programme label',
		)
	),
	'A meaningful stored programme label must not be overwritten.'
);

$masters_board = $to_board_application->invoke(
	$plugin,
	array(
		'id' => 'offline-master-record',
		'referenceCode' => 'MC-OFFLINE',
		'fullName' => 'Offline Master Applicant',
		'agencyName' => 'Offline Agency',
		'applicationRoute' => 'postgraduate',
		'programmeCode' => 'business-administration-masters',
		'programmeLabel' => 'Programme not selected',
		'semester' => 'fall',
		'year' => '2026',
		'status' => 'prepayment-pending',
		'reviewerDecision' => 'pending',
		'paymentStatus' => 'cleared',
		'permitExpiryDate' => '2027-08-31',
		'updatedAt' => '2026-08-12 07:00:00.000',
	)
);
assert_same(
	"Master's degree in Business Administration (MBA)",
	$masters_board['programme'],
	'Board and case responses must expose the repaired Master\'s programme label.'
);
assert_same(
	'pending',
	$masters_board['reviewerDecision'],
	'Board responses must expose reviewerDecision so New and Pending can be separated reliably.'
);
assert_same(
	'2027-08-31',
	$masters_board['permitExpiryDate'],
	'Board responses must expose the flattened entry permit expiry date.'
);

$held_review_board = $to_board_application->invoke(
	$plugin,
	array(
		'id' => 'offline-held-record',
		'referenceCode' => 'MC-OFFLINE-HOLD',
		'fullName' => 'Offline Held Applicant',
		'agencyName' => 'Offline Agency',
		'applicationRoute' => 'bachelor-foundation',
		'programmeCode' => 'business-administration',
		'programmeLabel' => 'Business Administration',
		'semester' => 'fall',
		'year' => '2026',
		'status' => 'review-pending',
		'reviewerDecision' => 'hold',
		'paymentStatus' => 'awaiting-invoice',
		'updatedAt' => '2026-08-12 07:00:00.000',
	)
);
assert_same('hold', $held_review_board['reviewerDecision'], 'A held review must remain identifiable on the board.');
assert_same(
	'Wait for the agency response to the Pending review message, then reassess the case.',
	$held_review_board['nextAction'],
	'A held review must describe the actual pending action instead of asking staff to issue an offer.'
);

function assert_throws_message($expected, $callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		assert_same($expected, $error->getMessage(), $message);
		return;
	}

	throw new RuntimeException($message . ' No exception was thrown.');
}

function assert_string_contains($needle, $haystack, $message) {
	if (false === strpos($haystack, $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

$allowed_transitions = array(
	array('prepayment-pending', 'acceptance-issued'),
	array('acceptance-issued', 'migration-documents'),
	array('migration-documents', 'entry-permit-processing'),
	array('Payment pending', 'Acceptance confirmed'),
);

foreach ($allowed_transitions as $transition) {
	assert_same(
		false,
		$stale_policy->invoke($plugin, $transition[0], $transition[1]),
		'Stale exact-next forward transition must never be replayed.'
	);
}

$blocked_transitions = array(
	array('acceptance-issued', 'prepayment-pending'),
	array('prepayment-pending', 'migration-documents'),
	array('prepayment-pending', 'prepayment-pending'),
	array('trashed', 'review-pending'),
	array('migration-documents', 'rejected'),
	array('migration-documents', 'trashed'),
);

foreach ($blocked_transitions as $transition) {
	assert_same(
		false,
		$stale_policy->invoke($plugin, $transition[0], $transition[1]),
		'Stale backward, skipped, duplicate, or terminal transition should be ignored.'
	);
}

$users = array(
	'admin' => array('roles' => array('administrator')),
	'admissions' => array('roles' => array('admissions-officer')),
	'finance' => array('roles' => array('finance-officer')),
	'migration' => array('roles' => array('migration-officer')),
	'immigration' => array('roles' => array('immigration-officer')),
	'registrar' => array('roles' => array('registrar')),
	'agent' => array('roles' => array('mc_agent')),
);

$admissions_fields = $allowed_operations->invoke($plugin, $users['admissions']);
assert_contains('reviewSummary', $admissions_fields, 'Admissions must be able to update review fields.');
assert_contains('offerIssuedDate', $admissions_fields, 'Admissions must be able to update offer fields.');
assert_not_contains('paymentStatus', $admissions_fields, 'Admissions must not overwrite finance fields.');

$finance_fields = $allowed_operations->invoke($plugin, $users['finance']);
assert_contains('paymentStatus', $finance_fields, 'Finance must be able to update payment fields.');
assert_contains('commissionStatus', $finance_fields, 'Finance must be able to update commission fields.');
assert_contains('refundStatus', $finance_fields, 'Finance must be able to update refund fields.');
assert_not_contains('reviewSummary', $finance_fields, 'Finance must not overwrite admissions review fields.');

$migration_fields = $allowed_operations->invoke($plugin, $users['migration']);
$immigration_fields = $allowed_operations->invoke($plugin, $users['immigration']);
assert_contains('permitStatus', $migration_fields, 'Migration must be able to update permit fields.');
assert_contains('lateArrivalReason', $migration_fields, 'Migration must be able to update arrival fields.');
assert_same($migration_fields, $immigration_fields, 'Migration and immigration share case-record permissions.');
assert_not_contains('paymentStatus', $migration_fields, 'Migration must not overwrite finance fields.');

$registrar_fields = $allowed_operations->invoke($plugin, $users['registrar']);
assert_contains('enrollmentStatus', $registrar_fields, 'Registrar must be able to update enrollment fields.');
assert_not_contains('permitStatus', $registrar_fields, 'Registrar must not overwrite permit fields.');
assert_same(array(), $allowed_operations->invoke($plugin, $users['agent']), 'Agents must not receive operational field permissions.');

foreach (array('admin', 'admissions', 'finance', 'migration', 'immigration', 'registrar') as $internal_role) {
	assert_contains(
		'workflowNote',
		$allowed_operations->invoke($plugin, $users[$internal_role]),
		$internal_role . ' must be able to update the shared workflow note.'
	);
}
assert_not_contains(
	'workflowNote',
	$allowed_operations->invoke($plugin, $users['agent']),
	'Agents must not be able to update the internal workflow note.'
);

$admin_fields = $allowed_operations->invoke($plugin, $users['admin']);
assert_contains('reviewSummary', $admin_fields, 'Administrator must have admissions fields.');
assert_contains('paymentStatus', $admin_fields, 'Administrator must have finance fields.');
assert_contains('permitStatus', $admin_fields, 'Administrator must have permit fields.');
assert_contains('enrollmentStatus', $admin_fields, 'Administrator must have enrollment fields.');

$workflow_targets = array(
	'profile-preparation', 'review-pending', 'offer-issued', 'prepayment-pending',
	'acceptance-issued', 'migration-documents', 'entry-permit-processing',
	'arrival-immigration', 'enrollment-complete', 'rejected', 'trashed',
);
$workflow_matrix = array(
	'admin' => $workflow_targets,
	'admissions' => array(
		'profile-preparation', 'review-pending', 'offer-issued', 'prepayment-pending',
		'acceptance-issued', 'migration-documents', 'rejected',
	),
	'finance' => array(),
	'migration' => array(
		'acceptance-issued', 'migration-documents', 'entry-permit-processing',
		'arrival-immigration', 'enrollment-complete',
	),
	'immigration' => array(
		'acceptance-issued', 'migration-documents', 'entry-permit-processing',
		'arrival-immigration', 'enrollment-complete',
	),
	'registrar' => array('arrival-immigration', 'enrollment-complete'),
	'agent' => array(),
);

foreach ($workflow_matrix as $role => $allowed_targets) {
	foreach ($workflow_targets as $target) {
		assert_same(
			in_array($target, $allowed_targets, true),
			$workflow_permission->invoke($plugin, $users[$role], $target),
			$role . ' workflow permission mismatch for ' . $target . '.'
		);
	}
}

$GLOBALS['wpdb']->get_row_result = array(
	'id' => 'application-review-pending',
	'wordpressUserId' => 42,
	'status' => 'review-pending',
	'workflowNote' => 'Awaiting review.',
	'updatedAt' => '2026-08-13 08:00:00.000',
);
$GLOBALS['wpdb']->events = array();
assert_throws_message(
	'Use the Rejected assessment action and enter the required standalone rejection reason.',
	function () use ($update_workflow, $plugin, $users) {
		$update_workflow->invoke(
			$plugin,
			array(
				'applicationId' => 'application-review-pending',
				'expectedUpdatedAt' => '2026-08-13T08:00:00.000Z',
				'status' => 'rejected',
				'note' => 'Legacy generic rejection.',
				'user' => array_merge(array('id' => 7, 'name' => 'Admissions Officer'), $users['admissions']),
			)
		);
	},
	'Generic workflow updates must not bypass the standalone rejection reason.'
);
assert_same(1, count($GLOBALS['wpdb']->events), 'A blocked generic workflow rejection must stop after its read and before mutation.');

$review_patch = $normalize_operations->invoke($plugin, array('reviewSummary' => 'Updated review'), 'offer-issued');
assert_same(array('reviewSummary'), array_keys($review_patch), 'A partial review patch must not synthesize omitted finance or workflow fields.');
assert_same('Updated review', $review_patch['reviewSummary'], 'The supplied review value should be normalized.');

$boolean_patch = $normalize_operations->invoke($plugin, array('termBalanceApplies' => false), 'offer-issued');
assert_same(array('termBalanceApplies' => 0), $boolean_patch, 'An explicit false boolean must remain false.');

$authorize_operations->invoke($plugin, array('reviewSummary' => 'Allowed'), $users['admissions']);
assert_throws(
	function () use ($authorize_operations, $plugin, $users) {
		$authorize_operations->invoke($plugin, array('paymentStatus' => 'cleared'), $users['admissions']);
	},
	'Admissions must be denied finance fields.'
);
assert_throws(
	function () use ($authorize_operations, $plugin, $users) {
		$authorize_operations->invoke($plugin, array('reviewSummary' => 'Denied'), $users['agent']);
	},
	'Agents must be denied operational patches.'
);

$all_document_ids = array(
	'passport', 'secondaryMarksheet', 'higherSecondaryMarksheet', 'englishCertificate',
	'studentSignature', 'consultantSignature', 'agencyAgreement', 'authorizationCertificate',
	'bachelorDiploma', 'bachelorTranscript', 'bankTransactionConfirmation',
	'migrationSupportingDocuments', 'entryPermitPaymentReceipt', 'entryPermitRecord',
	'courierReceipt', 'afterArrivalPaymentReceipt', 'enrollmentAgreement', 'bankStatement',
	'rentalAgreement', 'medicalCertificate', 'xRayRecord', 'immigrationAppointmentRecord',
	'immigrationPaymentReceipt', 'pinkCardRecord', 'insuranceCopy',
);
$agent_intake_document_ids = array(
	'passport', 'secondaryMarksheet', 'higherSecondaryMarksheet', 'englishCertificate',
	'studentSignature', 'consultantSignature', 'agencyAgreement', 'authorizationCertificate',
	'bachelorDiploma', 'bachelorTranscript', 'bankTransactionConfirmation',
);

foreach ($all_document_ids as $document_id) {
	assert_same(
		true,
		$document_upload_policy->invoke($plugin, $users['finance'], $document_id),
		'Internal staff must be able to upload known document type ' . $document_id . '.'
	);
	assert_same(
		in_array($document_id, $agent_intake_document_ids, true),
		$document_upload_policy->invoke($plugin, $users['agent'], $document_id),
		'Agent upload policy mismatch for ' . $document_id . '.'
	);
}
assert_same(false, $document_upload_policy->invoke($plugin, $users['admin'], 'unknownDocument'), 'Unknown document types must be denied even for administrators.');
assert_same(false, $document_upload_policy->invoke($plugin, array('roles' => array('subscriber-only')), 'passport'), 'Non-agent external users must be denied uploads.');

$migration_user = array('name' => 'Migration Officer');
$legacy_migration_data = $migration_case_update_data->invoke(
	$plugin,
	array(
		'decisionDate' => '2026-08-26',
		'permitReference' => 'MP-100',
	),
	$migration_user
);
assert_same(
	false,
	array_key_exists('entryPermitExpiryDate', $legacy_migration_data),
	'An older client that omits entryPermitExpiryDate must not clear a stored expiry date.'
);

$valid_migration_data = $migration_case_update_data->invoke(
	$plugin,
	array('entryPermitExpiryDate' => '2027-08-31'),
	$migration_user
);
assert_same(
	'2027-08-31',
	$valid_migration_data['entryPermitExpiryDate'],
	'A real ISO entry permit expiry date must be persisted unchanged.'
);

$cleared_migration_data = $migration_case_update_data->invoke(
	$plugin,
	array('entryPermitExpiryDate' => null),
	$migration_user
);
assert_same(
	null,
	$cleared_migration_data['entryPermitExpiryDate'],
	'An explicit blank entry permit expiry date must clear the stored value to NULL.'
);

assert_throws_message(
	'Entry permit expiry date must use YYYY-MM-DD.',
	function () use ($migration_case_update_data, $plugin, $migration_user) {
		$migration_case_update_data->invoke(
			$plugin,
			array('entryPermitExpiryDate' => '2026-02-30'),
			$migration_user
		);
	},
	'An impossible entry permit expiry calendar date must be rejected.'
);

assert_throws_message(
	'Entry permit expiry date must use YYYY-MM-DD.',
	function () use ($migration_case_update_data, $plugin, $migration_user) {
		$migration_case_update_data->invoke(
			$plugin,
			array('entryPermitExpiryDate' => 20270831),
			$migration_user
		);
	},
	'A non-string entry permit expiry value must be rejected instead of treated as blank.'
);

$mapped_migration_case = $map_migration_case->invoke(
	$plugin,
	array(
		'id' => 'migration-case-1',
		'packPreparedDate' => null,
		'packSubmittedDate' => null,
		'paymentReference' => null,
		'paymentDate' => null,
		'decisionDate' => '2026-08-26',
		'entryPermitExpiryDate' => '2027-08-31',
		'permitReference' => 'MP-100',
		'note' => null,
		'recordedByName' => 'Migration Officer',
		'updatedAt' => '2026-09-15 10:00:00.000',
	)
);
assert_same(
	'2027-08-31',
	$mapped_migration_case['entryPermitExpiryDate'],
	'Detailed application responses must map entryPermitExpiryDate inside migrationCase.'
);

$mapped_legacy_migration_case = $map_migration_case->invoke(
	$plugin,
	array(
		'id' => 'migration-case-legacy',
		'recordedByName' => 'Migration Officer',
		'updatedAt' => '2026-09-15 10:00:00.000',
	)
);
assert_same(
	null,
	$mapped_legacy_migration_case['entryPermitExpiryDate'],
	'A legacy migration row without the new value must map it as NULL.'
);

$expected_case_version = '2026-07-29T10:11:12.345Z';
$case_user = array('name' => 'Offline concurrency test');
$stale_db = new MC_Admissions_Test_Wpdb();
$stale_db->query_results = array(1, 0, 1);
$GLOBALS['wpdb'] = $stale_db;

assert_throws_message(
	MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR,
	function () use ($case_record_upsert, $plugin, $case_user, $expected_case_version) {
		$case_record_upsert->invoke(
			$plugin,
			'mc_admission_migration_cases',
			'application-1',
			array('note' => 'must not be written'),
			$case_user,
			'migration',
			$expected_case_version
		);
	},
	'A stale parent application version must reject a child-record upsert with the canonical message.'
);
assert_same(3, count($stale_db->events), 'A stale child-record upsert should only start, attempt the parent CAS, and roll back.');
assert_same('query:START TRANSACTION', $stale_db->events[0], 'The child-record upsert must start a transaction.');
assert_string_contains('UPDATE mc_admission_applications', $stale_db->events[1], 'The parent application CAS must run before any child lookup.');
assert_string_contains('AND updatedAt = %s', $stale_db->events[1], 'The parent application update must include the expected version predicate.');
assert_same('query:ROLLBACK', $stale_db->events[2], 'A stale parent version must roll back the transaction.');
assert_same(array(), $stale_db->updates, 'A stale parent version must not update a child row.');
assert_same(array(), $stale_db->inserts, 'A stale parent version must not insert a child row.');

$success_db = new MC_Admissions_Test_Wpdb();
$success_db->query_results = array(1, 1, 1);
$success_db->get_var_result = 'immigration-child-1';
$GLOBALS['wpdb'] = $success_db;
$case_record_upsert->invoke(
	$plugin,
	'mc_admission_immigration_cases',
	'application-1',
	array('note' => 'saved after parent CAS'),
	$case_user,
	'immigration',
	$expected_case_version
);
assert_same('query:START TRANSACTION', $success_db->events[0], 'Successful child update must start a transaction.');
assert_string_contains('UPDATE mc_admission_applications', $success_db->events[1], 'Successful child update must touch the parent first.');
assert_string_contains('get_var:SELECT id FROM mc_admission_immigration_cases', $success_db->events[2], 'Child lookup must occur after the parent CAS.');
assert_same('update:mc_admission_immigration_cases', $success_db->events[3], 'Existing immigration row should update only after the parent CAS succeeds.');
assert_same('query:COMMIT', $success_db->events[4], 'Successful parent and child writes must commit together.');

assert_same(409, $mutation_error_status->invoke($plugin, new Exception(MC_Admissions_WordPress_Backend::STALE_APPLICATION_ERROR)), 'Canonical stale errors must map to HTTP 409.');
assert_same(400, $mutation_error_status->invoke($plugin, new Exception('Other write failure.')), 'Non-stale write errors should remain HTTP 400.');

$plugin_source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
assert_string_contains(' * Version: 0.2.67', $plugin_source, 'The plugin release header must be bumped for updater detection.');
assert_string_contains('$this->ensure_migration_case_columns();', $plugin_source, 'The migration schema upgrader must run during plugin boot.');
assert_string_contains("? 'migration.entryPermitExpiryDate'", $plugin_source, 'The board query must select the flattened permit expiry date after schema verification.');
assert_string_contains("\t\t\t\t: 'NULL';", $plugin_source, 'The board query must safely return a null expiry when the schema upgrade is unavailable.');
assert_same(true, substr_count($plugin_source, '$expected_updated_at = isset($params[' . "'expectedUpdatedAt'" . '])') >= 2, 'Migration and immigration REST upserts must accept expectedUpdatedAt.');
assert_same(2, substr_count($plugin_source, '$this->mutation_error_status($error)'), 'Both migration and immigration REST upserts must use stale-aware HTTP status mapping.');

unset($GLOBALS['mc_admissions_test_options']['mc_admissions_migration_case_schema_version']);
$failed_schema_db = new MC_Admissions_Test_Wpdb();
$failed_schema_db->entry_permit_expiry_column = false;
$failed_schema_db->query_results = array(false);
$GLOBALS['wpdb'] = $failed_schema_db;
$ensure_migration_case_columns->invoke($plugin);
assert_same(
	false,
	array_key_exists('mc_admissions_migration_case_schema_version', $GLOBALS['mc_admissions_test_options']),
	'A failed ALTER TABLE must not record the migration-case schema version.'
);
assert_same(
	false,
	$failed_schema_db->entry_permit_expiry_column,
	'A failed migration must leave the expiry column absent.'
);

$successful_schema_db = new MC_Admissions_Test_Wpdb();
$successful_schema_db->entry_permit_expiry_column = false;
$GLOBALS['wpdb'] = $successful_schema_db;
$ensure_migration_case_columns->invoke($plugin);
assert_same(true, $successful_schema_db->entry_permit_expiry_column, 'The migration upgrader must add the expiry column.');
assert_same(
	'0.2.64',
	$GLOBALS['mc_admissions_test_options']['mc_admissions_migration_case_schema_version'],
	'The migration-case schema version must be recorded only after the column is verified.'
);
assert_same(
	4,
	count($successful_schema_db->events),
	'The migration upgrader must check the table, check the column, alter it, and verify it.'
);

$updater_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mc-admissions-updater-' . bin2hex(random_bytes(8));
$canonical_package = $updater_root . DIRECTORY_SEPARATOR . 'mc-admissions-wordpress-backend';
if (!mkdir($canonical_package, 0777, true) && !is_dir($canonical_package)) {
	throw new RuntimeException('Could not create the updater regression fixture.');
}
$updater_marker = $canonical_package . DIRECTORY_SEPARATOR . 'mc-admissions-wordpress-backend.php';
try {
	file_put_contents($updater_marker, 'updater-regression-marker');
	$updater_remote_source = trailingslashit(str_replace('\\', '/', $updater_root));
	$canonical_source = trailingslashit(str_replace('\\', '/', $canonical_package));
	$puc_corrected_source = $updater_remote_source . 'mc-admissions-wordpress-backend/';
	$unscoped_source = rtrim($canonical_source, '/\\');
	assert_same(
		$unscoped_source,
		$plugin->normalize_update_package_paths($unscoped_source, $updater_remote_source, null, null),
		'Updater source normalization must leave unscoped filter calls byte-for-byte unchanged.'
	);
	assert_same(
		$unscoped_source,
		$plugin->normalize_update_package_paths(
			$unscoped_source,
			$updater_remote_source,
			null,
			array(
				'action' => 'update',
				'plugin' => 'another-plugin/another-plugin.php',
			)
		),
		'Updater source normalization must not alter another plugin package.'
	);
	assert_same(
		$unscoped_source,
		$plugin->normalize_update_package_paths(
			$unscoped_source,
			$updater_remote_source,
			null,
			array(
				'action' => 'install',
				'plugin' => 'mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php',
			)
		),
		'Updater source normalization must not alter manual plugin-install packages.'
	);
	$normalized_source = $plugin->normalize_update_package_paths(
		$canonical_source,
		$updater_remote_source,
		null,
		array(
			'action' => 'update',
			'plugin' => 'mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php',
		)
	);
	assert_same(
		$puc_corrected_source,
		$normalized_source,
		'Canonical updater sources must exactly match PUC, including the trailing slash, to avoid a self-rename.'
	);
	assert_same(
		$canonical_source,
		$plugin->normalize_update_package_paths(
			$canonical_source,
			$updater_remote_source,
			null,
			array('plugin' => 'mc-admissions-wordpress-backend/mc-admissions-wordpress-backend.php')
		),
		'Bulk per-plugin update hook data must retain the canonical trailing slash.'
	);
	assert_same(
		'updater-regression-marker',
		file_get_contents($updater_marker),
		'Normalizing a canonical updater source must leave the extracted plugin files intact.'
	);
} finally {
	if (is_file($updater_marker)) {
		unlink($updater_marker);
	}
	if (is_dir($canonical_package)) {
		rmdir($canonical_package);
	}
	if (is_dir($updater_root)) {
		rmdir($updater_root);
	}
}

$pack_expectations = array(
	'prepayment-pending' => 'intake',
	'acceptance-issued' => 'migration',
	'migration-documents' => 'migration',
	'entry-permit-processing' => 'migration',
	'arrival-immigration' => 'immigration',
	'enrollment-complete' => 'immigration',
);

foreach ($pack_expectations as $status => $expected_pack) {
	assert_same(
		$expected_pack,
		$document_pack->invoke($plugin, $status),
		'Workflow status should select the expected document pack.'
	);
}

echo "Workflow policy tests passed.\n";
