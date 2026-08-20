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
	public function get_param($key) { return $this->json_params[$key] ?? null; }
	public function get_file_params() { return array(); }
	public function offsetExists($offset) { return array_key_exists($offset, $this->route_params); }
	public function offsetGet($offset) { return $this->route_params[$offset] ?? null; }
	public function offsetSet($offset, $value) { $this->route_params[$offset] = $value; }
	public function offsetUnset($offset) { unset($this->route_params[$offset]); }
}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Intake_Test_Role {
	public $name;
	public function __construct($name) { $this->name = $name; }
	public function has_cap($capability) { return true; }
	public function add_cap($capability) { return true; }
}

final class MC_Intake_Test_Wpdb {
	public $prefix = 'wp_';
	public $application;
	public $profile;
	public $documents = array();
	public $capacities = array();
	public $reservations = array();
	public $generatedLetters = array();
	public $historicalOfferCandidates = array();
	public $lockedHistoricalApplications = array();
	public $paymentTransactions = array();
	public $activities = array();
	public $events = array();
	public $fail_reservation_write = false;
	public $fail_activity_write = false;
	public $fail_post_commit_reload = false;
	private $snapshot = null;
	private $committed = false;
	private $version_tick = 0;

	public function __construct() {
		$this->application = intake_application();
		$this->profile = intake_profile();
	}

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

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

	private function capacity_key($semester, $year) {
		return strtolower((string) $semester) . ':' . (int) $year;
	}

	public function get_var($prepared) {
		$call = $this->unpack($prepared);
		if (false !== strpos($call['query'], 'SHOW TABLES LIKE')) {
			return isset($call['args'][0]) ? (string) $call['args'][0] : null;
		}
		if (false !== strpos($call['query'], 'FROM mc_admission_offer_reservations')) {
			$semester = strtolower((string) ($call['args'][0] ?? ''));
			$year = (int) ($call['args'][1] ?? 0);
			return count(array_filter($this->reservations, static function ($reservation) use ($semester, $year) {
				return strtolower((string) ($reservation['semester'] ?? '')) === $semester
					&& (int) ($reservation['intakeYear'] ?? 0) === $year
					&& 'active' === (string) ($reservation['status'] ?? '');
			}));
		}
		if (false !== strpos($call['query'], 'FROM mc_generated_letters')) {
			$application_id = (string) ($call['args'][0] ?? '');
			return count(array_filter($this->generatedLetters, static function ($letter) use ($application_id) {
				return (string) ($letter['applicationId'] ?? '') === $application_id
					&& in_array(
						(string) ($letter['templateId'] ?? ''),
						array('payment-receipt', 'acceptance-letter', 'letter-of-assurance'),
						true
					);
			}));
		}
		if (false !== strpos($call['query'], 'FROM mc_admission_payments')) {
			$application_id = (string) ($call['args'][0] ?? '');
			return count(array_filter($this->paymentTransactions, static function ($payment) use ($application_id) {
				return (string) ($payment['applicationId'] ?? '') === $application_id;
			}));
		}
		return null;
	}

	public function get_row($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		$args = $call['args'];
		$this->events[] = 'get_row:' . trim((string) preg_replace('/\s+/', ' ', $query));

		if (false !== strpos($query, 'FROM mc_admission_applications')) {
			if (false !== strpos($query, 'intake historical candidate lock')) {
				$application_id = (string) ($args[0] ?? '');
				if (isset($this->lockedHistoricalApplications[$application_id])) {
					return $this->lockedHistoricalApplications[$application_id];
				}
				foreach ($this->historicalOfferCandidates as $candidate) {
					if ((string) ($candidate['applicationId'] ?? '') === $application_id) {
						return $candidate;
					}
				}
				return null;
			}
			if ($this->fail_post_commit_reload && $this->committed && false === strpos($query, 'FOR UPDATE')) {
				throw new RuntimeException('Offline post-commit application reload failure.');
			}
			return $this->application;
		}
		if (false !== strpos($query, 'FROM mc_agency_profiles')) {
			return $this->profile;
		}
		if (false !== strpos($query, 'FROM mc_admission_documents')) {
			foreach ($this->documents as $document) {
				if ('bankTransactionConfirmation' === (string) ($document['type'] ?? '')) {
					return $document;
				}
			}
			return null;
		}
		if (false !== strpos($query, 'FROM mc_admission_intake_capacities')) {
			$key = $this->capacity_key($args[0] ?? '', $args[1] ?? 0);
			return $this->capacities[$key] ?? null;
		}
		if (false !== strpos($query, 'FROM mc_admission_offer_reservations')) {
			return $this->reservations[(string) ($args[0] ?? '')] ?? null;
		}
		if (
			false !== strpos($query, 'FROM mc_admission_migration_cases')
			|| false !== strpos($query, 'FROM mc_admission_immigration_cases')
			|| false !== strpos($query, 'FROM mc_admission_letter_drafts')
		) {
			return null;
		}

		return null;
	}

	public function get_results($prepared, $output = null) {
		$call = $this->unpack($prepared);
		$query = $call['query'];
		if (false !== strpos($query, 'INNER JOIN mc_generated_letters')) {
			return array_values(array_filter($this->historicalOfferCandidates, function ($candidate) {
				return !isset($this->reservations[(string) ($candidate['applicationId'] ?? '')]);
			}));
		}
		if (false !== strpos($query, 'FROM mc_admission_intake_capacities')) {
			return array_values($this->capacities);
		}
		if (false !== strpos($query, 'FROM mc_admission_documents')) {
			return array_values($this->documents);
		}
		if (false !== strpos($query, 'FROM mc_admission_activities')) {
			return array_reverse($this->activities);
		}

		return array();
	}

	public function query($prepared) {
		$call = $this->unpack($prepared);
		$query = trim($call['query']);
		$args = $call['args'];
		$this->events[] = 'query:' . trim((string) preg_replace('/\s+/', ' ', $query));

		if ('START TRANSACTION' === $query) {
			$this->snapshot = array(
				'application' => $this->application,
				'capacities' => $this->capacities,
				'reservations' => $this->reservations,
				'activities' => $this->activities,
			);
			$this->committed = false;
			return 1;
		}
		if ('ROLLBACK' === $query) {
			if (is_array($this->snapshot)) {
				foreach ($this->snapshot as $field => $value) {
					$this->{$field} = $value;
				}
			}
			$this->snapshot = null;
			$this->committed = false;
			return 1;
		}
		if ('COMMIT' === $query) {
			$this->snapshot = null;
			$this->committed = true;
			return 1;
		}
		if (false !== strpos($query, 'INSERT INTO mc_admission_intake_capacities')) {
			$key = $this->capacity_key($args[0] ?? '', $args[1] ?? 0);
			if (isset($this->capacities[$key])) return false;
			$this->capacities[$key] = array(
				'semester' => (string) ($args[0] ?? ''),
				'intakeYear' => (int) ($args[1] ?? 0),
				'totalPlacements' => (int) ($args[2] ?? 0),
				'reservedPlacements' => (int) ($args[3] ?? 0),
				'updatedByName' => (string) ($args[4] ?? ''),
				'createdAt' => '2026-08-20 09:00:00.000',
				'updatedAt' => '2026-08-20 10:00:00.000',
			);
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_admission_intake_capacities') && false !== strpos($query, 'SET totalPlacements =')) {
			$key = $this->capacity_key($args[3] ?? '', $args[4] ?? 0);
			if (!isset($this->capacities[$key])) return 0;
			if ((string) ($this->capacities[$key]['updatedAt'] ?? '') !== (string) ($args[5] ?? '')) return 0;
			$this->capacities[$key]['totalPlacements'] = (int) ($args[0] ?? 0);
			$this->capacities[$key]['reservedPlacements'] = (int) ($args[1] ?? 0);
			$this->capacities[$key]['updatedByName'] = (string) ($args[2] ?? '');
			$this->capacities[$key]['updatedAt'] = '2026-08-20 10:00:00.000';
			return 1;
		}
		if (false !== strpos($query, 'reservedPlacements = reservedPlacements + 1')) {
			$key = $this->capacity_key($args[0] ?? '', $args[1] ?? 0);
			if (!isset($this->capacities[$key])) return 0;
			if ((int) $this->capacities[$key]['reservedPlacements'] >= (int) $this->capacities[$key]['totalPlacements']) return 0;
			$this->capacities[$key]['reservedPlacements']++;
			return 1;
		}
		if (false !== strpos($query, 'reservedPlacements = reservedPlacements - 1')) {
			$key = $this->capacity_key($args[0] ?? '', $args[1] ?? 0);
			if (!isset($this->capacities[$key]) || (int) $this->capacities[$key]['reservedPlacements'] <= 0) return 0;
			$this->capacities[$key]['reservedPlacements']--;
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_admission_applications') && false !== strpos($query, 'wordpressUsername = %s')) {
			$expected = isset($args[28]) ? (string) $args[28] : null;
			if (null === $expected || $expected !== (string) $this->application['updatedAt']) return 0;
			$this->version_tick++;
			$this->application = array_merge(
				$this->application,
				array(
					'wordpressUsername' => (string) ($args[0] ?? ''),
					'wordpressEmail' => (string) ($args[1] ?? ''),
					'fullName' => (string) ($args[2] ?? ''),
					'passportNumber' => (string) ($args[3] ?? ''),
					'email' => (string) ($args[4] ?? ''),
					'phone' => (string) ($args[5] ?? ''),
					'birthday' => (string) ($args[6] ?? ''),
					'address' => (string) ($args[7] ?? ''),
					'city' => (string) ($args[8] ?? ''),
					'postalCode' => (string) ($args[9] ?? ''),
					'country' => (string) ($args[10] ?? ''),
					'gender' => (string) ($args[11] ?? ''),
					'semester' => (string) ($args[12] ?? ''),
					'year' => (string) ($args[13] ?? ''),
					'applicationRoute' => (string) ($args[14] ?? ''),
					'programmeCode' => (string) ($args[15] ?? ''),
					'programmeLabel' => (string) ($args[16] ?? ''),
					'agencyName' => (string) ($args[17] ?? ''),
					'consultantName' => (string) ($args[18] ?? ''),
					'consultantEmail' => (string) ($args[19] ?? ''),
					'consultantPhone' => (string) ($args[20] ?? ''),
					'submissionDate' => $args[21] ?? null,
					'tuitionAcknowledged' => (int) ($args[22] ?? 0),
					'offerTermsAcknowledged' => (int) ($args[23] ?? 0),
					'gdprAcknowledged' => (int) ($args[24] ?? 0),
					'isTestData' => (int) ($args[25] ?? 0),
					'lastUpdatedByName' => (string) ($args[26] ?? ''),
					'updatedAt' => sprintf('2026-08-20 10:00:%02d.000', $this->version_tick),
				)
			);
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_admission_applications') && false !== strpos($query, 'status = %s')) {
			$expected = isset($args[4]) ? (string) $args[4] : null;
			if (null !== $expected && $expected !== (string) $this->application['updatedAt']) return 0;
			$this->version_tick++;
			$this->application['status'] = (string) ($args[0] ?? $this->application['status']);
			$this->application['workflowNote'] = (string) ($args[1] ?? $this->application['workflowNote']);
			$this->application['lastUpdatedByName'] = (string) ($args[2] ?? '');
			$this->application['updatedAt'] = sprintf('2026-08-20 10:00:%02d.000', $this->version_tick);
			return 1;
		}
		if (false !== strpos($query, 'UPDATE mc_admission_applications')) {
			$expected = isset($args[2]) ? (string) $args[2] : null;
			if (null !== $expected && $expected !== (string) $this->application['updatedAt']) {
				return 0;
			}
			$this->version_tick++;
			$this->application['lastUpdatedByName'] = (string) ($args[0] ?? '');
			$this->application['updatedAt'] = sprintf('2026-08-20 10:00:%02d.000', $this->version_tick);
			return 1;
		}

		return 1;
	}

	public function update($table, $data, $where, $format = null, $where_format = null) {
		if ('mc_admission_offer_reservations' === $table) {
			if ($this->fail_reservation_write) return false;
			$id = (string) ($where['applicationId'] ?? '');
			if (!isset($this->reservations[$id])) return 0;
			$this->reservations[$id] = array_merge($this->reservations[$id], $data);
			return 1;
		}
		return 1;
	}

	public function insert($table, $data, $format = null) {
		if ('mc_admission_offer_reservations' === $table) {
			if ($this->fail_reservation_write) return false;
			$id = (string) $data['applicationId'];
			if (isset($this->reservations[$id])) return false;
			$this->reservations[$id] = $data;
			return 1;
		}
		if ('mc_admission_activities' === $table) {
			if ($this->fail_activity_write) return false;
			$this->activities[] = $data;
			return 1;
		}
		return 1;
	}

	public function delete($table, $where, $where_format = null) {
		if ('mc_admission_intake_capacities' !== $table) return false;
		$key = $this->capacity_key($where['semester'] ?? '', $where['intakeYear'] ?? 0);
		if (!isset($this->capacities[$key])) return 0;
		unset($this->capacities[$key]);
		return 1;
	}

	public function reset_transaction_state() {
		$this->snapshot = null;
		$this->committed = false;
		$this->fail_reservation_write = false;
		$this->fail_activity_write = false;
		$this->fail_post_commit_reload = false;
	}
}

$GLOBALS['mc_intake_roles'] = array();
$GLOBALS['mc_intake_routes'] = array();
$GLOBALS['mc_intake_current_user'] = null;
$GLOBALS['mc_intake_uuid'] = 0;

function intake_application(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'application-1', 'referenceCode' => 'MC-INTAKE1', 'wordpressUserId' => 42,
			'wordpressUsername' => 'agency-owner', 'wordpressEmail' => 'agency@example.com',
			'fullName' => 'Applicant', 'passportNumber' => 'OFFLINE', 'email' => 'student@example.com',
			'phone' => '+357000000', 'birthday' => '01/01/2000', 'address' => 'Offline address',
			'city' => 'Nicosia', 'postalCode' => '1000', 'country' => 'Cyprus', 'gender' => 'Other',
			'semester' => 'fall', 'year' => '2026', 'applicationRoute' => 'standard',
			'programmeCode' => 'business-administration',
			'programmeLabel' => "Bachelor's degree in Business Administration",
			'agencyName' => 'Agency Owner', 'consultantName' => 'Consultant',
			'consultantEmail' => 'agency@example.com', 'consultantPhone' => '+357111111',
			'submissionDate' => '20/08/2026', 'tuitionAcknowledged' => 1,
			'offerTermsAcknowledged' => 1, 'gdprAcknowledged' => 1, 'isTestData' => 1,
			'status' => 'review-pending', 'workflowNote' => 'Under review',
			'reviewSummary' => null, 'reviewerDecision' => 'academically-cleared', 'decisionDueDate' => null,
			'offerIssuedDate' => null, 'offerExpiryDate' => null, 'offerConditionNote' => null,
			'classesStartDate' => '01/09/2026', 'tuitionFeeFirstYear' => '7000.00',
			'tuitionFeeFollowingYears' => '7000.00', 'termBalanceApplies' => 1,
			'paymentStatus' => 'awaiting-invoice', 'paymentAmount' => null, 'paymentCurrency' => 'EUR',
			'paymentReference' => null, 'paymentConfirmedDate' => null, 'financeNote' => null,
			'permitStatus' => 'not-started', 'permitReference' => null, 'permitSubmittedDate' => null,
			'permitDecisionDate' => null, 'permitNote' => null, 'arrivalStatus' => 'planning',
			'travelDate' => null, 'accommodationStatus' => null, 'enrollmentStatus' => 'pending',
			'orientationDate' => null, 'enrollmentNote' => null, 'lateArrivalReason' => null,
			'lastUpdatedByName' => 'Prior user', 'source' => 'offline',
			'createdAt' => '2026-08-20 09:00:00.000', 'updatedAt' => '2026-08-20 10:00:00.000',
		),
		$overrides
	);
}

function intake_profile(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'profile-1', 'wordpressUserId' => 42, 'wordpressUsername' => 'agency-owner',
			'wordpressEmail' => 'agency@example.com', 'agencyName' => 'Agency Owner',
			'consultantName' => 'Consultant', 'consultantEmail' => 'agency@example.com',
			'consultantPhone' => '+357111111', 'agreementOnFile' => 1, 'authorizationOnFile' => 1,
		),
		$overrides
	);
}

function intake_draft(array $overrides = array()) {
	return array_merge(
		array(
			'fullName' => 'Applicant', 'passportNumber' => 'OFFLINE', 'email' => 'student@example.com',
			'phone' => '+357000000', 'birthday' => '01/01/2000', 'address' => 'Offline address',
			'city' => 'Nicosia', 'postalCode' => '1000', 'country' => 'Cyprus', 'gender' => 'Other',
			'programme' => 'business-administration', 'semester' => 'fall', 'year' => '2026',
			'submissionDate' => '20/08/2026', 'tuitionAcknowledged' => true,
			'offerTermsAcknowledged' => true, 'gdprAcknowledged' => true,
			'documents' => array(),
		),
		$overrides
	);
}

function intake_profile_identity($complete = true) {
	return array(
		'profileComplete' => $complete, 'agencyName' => 'Agency Owner',
		'consultantName' => 'Consultant', 'consultantEmail' => 'agency@example.com',
		'consultantPhone' => '+357111111',
	);
}

function intake_document($type, array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'doc-' . $type, 'applicationId' => 'application-1', 'type' => $type,
			'label' => $type, 'isReady' => 1, 'assessmentStatus' => 'pending',
			'uploadedUrl' => '/file/' . $type, 'storageItemId' => 'item-' . $type,
			'originalName' => $type . '.pdf', 'mimeType' => 'application/pdf',
			'createdAt' => '2026-08-20 09:00:00.000', 'updatedAt' => '2026-08-20 09:00:00.000',
		),
		$overrides
	);
}

function intake_user($roles, $id = 7) {
	return array('id' => $id, 'username' => 'staff', 'name' => 'Staff User', 'email' => 'staff@example.com', 'roles' => $roles);
}

function intake_wp_user($roles, $id = 7) {
	return (object) array(
		'ID' => $id, 'user_login' => 'staff', 'display_name' => 'Staff User',
		'user_email' => 'staff@example.com', 'roles' => $roles, 'allcaps' => array(),
	);
}

function __($text, $domain = null) { return $text; }
function get_role($slug) { return $GLOBALS['mc_intake_roles'][$slug] ?? null; }
function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Intake_Test_Role($label);
	$GLOBALS['mc_intake_roles'][$slug] = $role;
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
		'mc_admissions_finance_workspace_schema_version' => '0.2.61',
		'mc_admissions_intake_capacity_schema_version' => '0.2.62',
	);
	return array_key_exists($key, $versions) ? $versions[$key] : $fallback;
}
function update_option($key, $value, $autoload = null) { return true; }
function add_filter(...$args) { return true; }
function add_action(...$args) { return true; }
function register_activation_hook(...$args) { return true; }
function register_rest_route($namespace, $route, $args = array(), $override = false) {
	$GLOBALS['mc_intake_routes'][] = compact('namespace', 'route', 'args');
	return true;
}
function is_email($value) { return false !== filter_var((string) $value, FILTER_VALIDATE_EMAIL); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_textarea_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_file_name($value) { return basename((string) $value); }
function sanitize_email($value) { return trim((string) $value); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value) { return json_encode($value); }
function wp_get_current_user() { return $GLOBALS['mc_intake_current_user']; }
function get_avatar_url($user_id, $args = array()) { return ''; }
function get_userdata($user_id) {
	if (42 !== (int) $user_id) return false;
	return (object) array(
		'ID' => 42, 'user_login' => 'agency-owner', 'display_name' => 'Agency Owner',
		'user_email' => 'agency@example.com', 'roles' => array('mc_agent'), 'allcaps' => array(),
	);
}
function wp_generate_uuid4() { $GLOBALS['mc_intake_uuid']++; return 'intake-uuid-' . $GLOBALS['mc_intake_uuid']; }
function current_time($type, $gmt = false) { return '2026-08-20 10:00:00'; }
function wp_date($format) { return '2026-08-20'; }
function rest_url($path = '') { return '/wp-json/' . ltrim((string) $path, '/'); }

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function intake_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
	}
}
function intake_assert_true($actual, $message) {
	if (!$actual) throw new RuntimeException($message);
}
function intake_assert_throws_contains($needle, $callback, $message) {
	try {
		$callback();
	} catch (Throwable $error) {
		if (false === strpos($error->getMessage(), $needle)) {
			throw new RuntimeException($message . ' Wrong error: ' . $error->getMessage());
		}
		return;
	}
	throw new RuntimeException($message . ' No exception was thrown.');
}
function intake_private_method($reflection, $name) {
	$method = $reflection->getMethod($name);
	$method->setAccessible(true);
	return $method;
}
function intake_seed_core_documents() {
	$GLOBALS['wpdb']->documents = array();
	foreach (array('passport', 'secondaryMarksheet', 'higherSecondaryMarksheet', 'englishCertificate', 'studentSignature', 'consultantSignature') as $type) {
		$GLOBALS['wpdb']->documents[$type] = intake_document($type);
	}
}
function intake_capacity($total, $reserved = 0, $semester = 'fall', $year = 2026) {
	return array(
		'semester' => $semester, 'intakeYear' => $year, 'totalPlacements' => $total,
		'reservedPlacements' => $reserved, 'updatedByName' => 'Administrator',
		'createdAt' => '2026-08-20 09:00:00.000', 'updatedAt' => '2026-08-20 10:00:00.000',
	);
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$normalize_draft = intake_private_method($reflection, 'normalize_application_intake_draft');
$assert_submission = intake_private_method($reflection, 'assert_review_submission_complete');
$reserve_offer = intake_private_method($reflection, 'reserve_offer_placement_for_generated_letter');
$cancel_offer = intake_private_method($reflection, 'cancel_offer_placement_reservation');
$bank_pdf = intake_private_method($reflection, 'assert_bank_transaction_confirmation_pdf');
$bank_ready = intake_private_method($reflection, 'bank_transaction_confirmation_ready');
$bank_removable = intake_private_method($reflection, 'assert_bank_transaction_confirmation_removable');
$assert_letter_available = intake_private_method($reflection, 'assert_admission_letter_generation_available');
$assert_reservation_intake = intake_private_method($reflection, 'assert_active_offer_reservation_intake_unchanged');
$persist_letter = intake_private_method($reflection, 'persist_generated_admission_letter');
$to_case = intake_private_method($reflection, 'to_admission_case');
$capacity_snapshot = intake_private_method($reflection, 'application_intake_capacity_snapshot');
$update_operations = intake_private_method($reflection, 'update_admission_application_operations');
$update_workflow = intake_private_method($reflection, 'update_admission_application_workflow');
$clear_document = intake_private_method($reflection, 'clear_document_record_and_touch_application');

// Canonical intake contract remains compatible with old HTML date values.
$normalized = $normalize_draft->invoke($plugin, intake_draft(array(
	'programme' => "Business Administration (Master's)",
	'semester' => 'Fall Semester',
	'submissionDate' => '2026-08-20T00:00:00.000Z',
)), true);
intake_assert_same('business-administration-masters', $normalized['programme'], 'Legacy MBA values must normalize to the canonical code.');
intake_assert_same('fall', $normalized['semester'], 'Semester intake must be canonical lowercase.');
intake_assert_same('2026-08-20', $normalized['submissionDate'], 'Submission dates must use the canonical YYYY-MM-DD wire/storage value.');
$day_first_normalized = $normalize_draft->invoke($plugin, intake_draft(array('submissionDate' => '20/08/2026')), true);
intake_assert_same('2026-08-20', $day_first_normalized['submissionDate'], 'Day-first display values must normalize without breaking older clients.');
intake_assert_same('postgraduate', $normalized['applicationRoute'], 'MBA must derive the postgraduate route.');
intake_assert_throws_contains('valid Programme', static function () use ($normalize_draft, $plugin) {
	$normalize_draft->invoke($plugin, intake_draft(array('programme' => 'invented-programme')), true);
}, 'Unknown programmes must be rejected by the command layer.');
intake_assert_throws_contains('dd/mm/yyyy', static function () use ($normalize_draft, $plugin) {
	$normalize_draft->invoke($plugin, intake_draft(array('submissionDate' => '08/20/2026')), true);
}, 'Ambiguous US submission dates must be rejected.');

// Existing application saves require explicit optimistic concurrency. New
// application creation remains compatible without an expected revision.
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$save_body = array(
	'applicationId' => 'application-1',
	'mode' => 'draft',
	'draft' => intake_draft(array('fullName' => 'CAS Updated Applicant')),
);
$missing_save_version = $plugin->rest_save_application(new WP_REST_Request(array(), $save_body));
intake_assert_same(400, $missing_save_version->get_status(), 'Existing application saves must reject a missing expectedUpdatedAt.');
intake_assert_true(false !== strpos($missing_save_version->get_data()['error'], 'version is required'), 'Missing application CAS must return an actionable error.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$invalid_save_version = $plugin->rest_save_application(new WP_REST_Request(array(), array_merge(
	$save_body,
	array('expectedUpdatedAt' => 'not-a-valid-version')
)));
intake_assert_same(400, $invalid_save_version->get_status(), 'Existing application saves must reject an invalid expectedUpdatedAt.');
intake_assert_same(array(), $GLOBALS['wpdb']->events, 'An invalid application version must fail before opening a write transaction.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$stale_save = $plugin->rest_save_application(new WP_REST_Request(array(), array_merge(
	$save_body,
	array('expectedUpdatedAt' => '2026-08-20T09:59:59.000Z')
)));
intake_assert_same(409, $stale_save->get_status(), 'A stale existing-application save must return a conflict.');
intake_assert_same(false, false !== strpos(implode("\n", $GLOBALS['wpdb']->events), 'wordpressUsername = %s'), 'A stale save must not execute the application data UPDATE.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$valid_save = $plugin->rest_save_application(new WP_REST_Request(array(), array_merge(
	$save_body,
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
)));
intake_assert_same(200, $valid_save->get_status(), 'A matching existing-application revision must save successfully.');
intake_assert_same('CAS Updated Applicant', $valid_save->get_data()['caseRecord']['fullName'], 'A valid CAS save must return the committed application data.');
intake_assert_true(false !== strpos(implode("\n", $GLOBALS['wpdb']->events), 'AND updatedAt = %s'), 'Every existing-application UPDATE must include its CAS predicate.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$create_without_version = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'mode' => 'draft',
	'draft' => intake_draft(),
	'assignedAgentId' => 42,
	'isTestData' => true,
)));
intake_assert_same(200, $create_without_version->get_status(), 'Creating a new application must remain compatible without expectedUpdatedAt.');
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();

// Authoritative field, declaration, owner-profile, and attachment validation.
intake_seed_core_documents();
$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(), 42, 'application-1');
intake_assert_throws_contains('Phone number', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(array('phone' => '')), intake_profile_identity(), 42, 'application-1');
}, 'Required scalar fields must not be bypassable.');
intake_assert_throws_contains('declarations', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(array('gdprAcknowledged' => false)), intake_profile_identity(), 42, 'application-1');
}, 'Required declarations must not be bypassable.');
intake_assert_throws_contains('Agency Profile', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(false), 42, 'application-1');
}, 'An incomplete owning profile must block review submission.');

// Boolean checklist claims alone do not satisfy an upload requirement.
$GLOBALS['wpdb']->documents = array();
intake_assert_throws_contains('Copy of passport', static function () use ($assert_submission, $plugin) {
	$claimed = intake_draft(array('documents' => array('passport' => true, 'secondaryMarksheet' => true)));
	$assert_submission->invoke($plugin, $claimed, intake_profile_identity(), 42, 'application-1');
}, 'A forged document checklist must not bypass uploaded attachment checks.');

intake_seed_core_documents();
$mba = intake_draft(array('programme' => 'business-administration-masters'));
intake_assert_throws_contains('Bachelor diploma', static function () use ($assert_submission, $plugin, $mba) {
	$assert_submission->invoke($plugin, $mba, intake_profile_identity(), 42, 'application-1');
}, 'MBA must require Bachelor diploma and transcripts.');
$GLOBALS['wpdb']->documents['bachelorDiploma'] = intake_document('bachelorDiploma');
$GLOBALS['wpdb']->documents['bachelorTranscript'] = intake_document('bachelorTranscript');
$assert_submission->invoke($plugin, $mba, intake_profile_identity(), 42, 'application-1');
$assert_submission->invoke($plugin, intake_draft(array('programme' => 'english-foundation')), intake_profile_identity(), 42, 'application-1');

// Transaction Confirmation is PDF-only and readiness reflects a real PDF record.
$valid_pdf = tempnam(sys_get_temp_dir(), 'mc-intake-pdf-');
$invalid_pdf = tempnam(sys_get_temp_dir(), 'mc-intake-not-pdf-');
file_put_contents($valid_pdf, "%PDF-1.7\n");
file_put_contents($invalid_pdf, "plain text\n");
$bank_pdf->invoke($plugin, 'confirmation.pdf', 'application/pdf', $valid_pdf);
$bank_pdf->invoke($plugin, 'confirmation.pdf', '', $valid_pdf);
$bank_pdf->invoke($plugin, 'confirmation.pdf', 'application/octet-stream', $valid_pdf);
intake_assert_throws_contains('valid PDF', static function () use ($bank_pdf, $plugin, $invalid_pdf) {
	$bank_pdf->invoke($plugin, 'confirmation.pdf', 'application/pdf', $invalid_pdf);
}, 'Spoofed Transaction Confirmation files must be rejected.');
intake_assert_same(true, $bank_ready->invoke($plugin, array(intake_document('bankTransactionConfirmation'))), 'A real PDF confirmation must be exposed as ready.');
intake_assert_same(true, $bank_ready->invoke($plugin, array(intake_document('bankTransactionConfirmation', array('mimeType' => '')))), 'A valid browser upload with blank MIME must remain ready.');
intake_assert_same(true, $bank_ready->invoke($plugin, array(intake_document('bankTransactionConfirmation', array('mimeType' => 'application/octet-stream')))), 'A valid generic-binary browser upload must remain ready.');
intake_assert_same(false, $bank_ready->invoke($plugin, array(intake_document('bankTransactionConfirmation', array('mimeType' => 'image/png')))), 'A non-PDF confirmation must not be exposed as ready.');
@unlink($valid_pdf);
@unlink($invalid_pdf);

// Once payment/acceptance state or a protected official letter relies on the
// confirmation, deletion is rejected under the application lock and rolled
// back. Replacement uploads remain a separate allowed operation.
$GLOBALS['wpdb']->application = intake_application(array('paymentStatus' => 'cleared'));
$version_before_guard = $GLOBALS['wpdb']->application['updatedAt'];
intake_assert_throws_contains('cannot be removed', static function () use ($clear_document, $plugin) {
	$clear_document->invoke(
		$plugin,
		'application-1',
		intake_document('bankTransactionConfirmation'),
		'2026-08-20 10:00:00.000',
		intake_user(array('finance-officer'))
	);
}, 'Cleared payment must prevent removal of Transaction Confirmation.');
intake_assert_same($version_before_guard, $GLOBALS['wpdb']->application['updatedAt'], 'A rejected evidence deletion must roll back the application revision.');

$GLOBALS['wpdb']->application = intake_application(array('status' => 'acceptance-issued'));
intake_assert_throws_contains('cannot be removed', static function () use ($bank_removable, $plugin) {
	$bank_removable->invoke($plugin, 'application-1');
}, 'Acceptance-stage handoff must rely on Transaction Confirmation.');
$GLOBALS['wpdb']->application = intake_application(array('paymentStatus' => 'awaiting-payment'));
$GLOBALS['wpdb']->generatedLetters = array(array(
	'applicationId' => 'application-1', 'templateId' => 'payment-receipt',
));
intake_assert_throws_contains('cannot be removed', static function () use ($bank_removable, $plugin) {
	$bank_removable->invoke($plugin, 'application-1');
}, 'A generated payment/acceptance/assurance letter must rely on Transaction Confirmation.');
$GLOBALS['wpdb']->generatedLetters = array();
$GLOBALS['wpdb']->paymentTransactions = array(array(
	'applicationId' => 'application-1', 'amount' => '7000.00',
));
intake_assert_throws_contains('cannot be removed', static function () use ($bank_removable, $plugin) {
	$bank_removable->invoke($plugin, 'application-1');
}, 'An immutable payment ledger row must block evidence deletion even after paymentStatus is reset.');
$GLOBALS['wpdb']->paymentTransactions = array();
$bank_removable->invoke($plugin, 'application-1');
intake_assert_throws_contains('changed since you opened it', static function () use ($clear_document, $plugin) {
	$clear_document->invoke(
		$plugin,
		'application-1',
		intake_document('bankTransactionConfirmation'),
		'2026-08-20 09:59:59.000',
		intake_user(array('finance-officer'))
	);
}, 'Transaction Confirmation deletion must retain application CAS protection.');

// Payment state and official payment/acceptance documents are also guarded
// server-side; hiding the field in a client cannot bypass these commands.
$letter_application = array_merge(
	intake_application(array('paymentStatus' => 'cleared', 'paymentAmount' => '7000.00')),
	array(
		'documents' => array(), 'activities' => array(), 'communications' => array(),
		'generatedLetters' => array(), 'letterDrafts' => array(), 'commissionRecords' => array(),
		'refundRecords' => array(), 'paymentTransactions' => array(), 'migrationCase' => null,
		'immigrationCase' => null, 'bankTransactionConfirmationReady' => false,
		'intakeCapacity' => null, 'offerPlacementReservation' => null,
	)
);
$legacy_date_case = $to_case->invoke($plugin, array_merge($letter_application, array('submissionDate' => '20/08/2026')), false);
intake_assert_same('2026-08-20', $legacy_date_case['submissionDate'], 'Reloading a legacy day-first date must hydrate the canonical application draft value.');
foreach (array('payment-receipt', 'acceptance-letter', 'letter-of-assurance') as $template_id) {
	intake_assert_throws_contains('Transaction Confirmation PDF', static function () use ($assert_letter_available, $plugin, $letter_application, $template_id) {
		$assert_letter_available->invoke($plugin, $letter_application, $template_id);
	}, $template_id . ' must require Transaction Confirmation PDF.');
}
$letter_application['documents'][] = intake_document('bankTransactionConfirmation');
$assert_letter_available->invoke($plugin, $letter_application, 'payment-receipt');
$assert_letter_available->invoke($plugin, $letter_application, 'acceptance-letter');
$assert_letter_available->invoke($plugin, $letter_application, 'letter-of-assurance');

$GLOBALS['wpdb']->application = intake_application(array('paymentStatus' => 'awaiting-payment'));
intake_seed_core_documents();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('admissions-officer'));
$missing_workflow_version = $plugin->rest_update_workflow(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'status' => 'acceptance-issued',
)));
intake_assert_same(400, $missing_workflow_version->get_status(), 'Workflow transitions must require application CAS at the REST boundary.');
intake_assert_throws_contains('Transaction Confirmation PDF', static function () use ($update_operations, $plugin) {
	$update_operations->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'draft' => array('paymentStatus' => 'cleared'),
		'user' => intake_user(array('finance-officer')),
	));
}, 'Application-level payment clearance must require Transaction Confirmation PDF.');
intake_assert_throws_contains('Transaction Confirmation PDF', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'acceptance-issued', 'note' => null,
		'user' => intake_user(array('admissions-officer')),
	));
}, 'Acceptance-issued workflow transition must require Transaction Confirmation PDF.');
$GLOBALS['wpdb']->documents['bankTransactionConfirmation'] = intake_document('bankTransactionConfirmation');
$GLOBALS['wpdb']->events = array();
$accepted = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'acceptance-issued', 'note' => null,
	'user' => intake_user(array('admissions-officer')),
));
intake_assert_same(true, $accepted['stageChanged'], 'Acceptance transition must succeed when locked payment evidence exists.');
intake_assert_same('acceptance-issued', $GLOBALS['wpdb']->application['status'], 'Acceptance transition must persist the canonical stage.');
$workflow_events = implode("\n", $GLOBALS['wpdb']->events);
intake_assert_true(false !== strpos($workflow_events, 'START TRANSACTION'), 'Acceptance transition must run in a database transaction.');
intake_assert_true(false !== strpos($workflow_events, 'FROM mc_admission_applications') && false !== strpos($workflow_events, 'FOR UPDATE'), 'Acceptance transition must lock the application row before relying on payment evidence.');
intake_assert_true(false !== strpos($workflow_events, 'FROM mc_admission_documents') && false !== strpos($workflow_events, 'FOR UPDATE'), 'Acceptance transition must inspect Transaction Confirmation under the same lock.');
$GLOBALS['wpdb']->documents = array();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$payment_response = $plugin->rest_create_payment(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array('draft' => array('amount' => '7000.00', 'currency' => 'EUR'))
));
intake_assert_same(400, $payment_response->get_status(), 'Recording a cleared payment transaction must require Transaction Confirmation PDF.');
intake_assert_true(false !== strpos($payment_response->get_data()['error'], 'Transaction Confirmation PDF'), 'Payment guard must return an actionable error.');

// REST contracts and role policy: agents may read aggregate availability;
// only Administrators may mutate it.
$plugin->register_rest_routes();
$capacity_routes = array_values(array_filter($GLOBALS['mc_intake_routes'], static function ($route) {
	return '/intake-capacities' === $route['route'];
}));
intake_assert_same(1, count($capacity_routes), 'Capacity collection route must be registered once.');
intake_assert_same(2, count($capacity_routes[0]['args']), 'Capacity collection route must expose GET and PUT handlers.');

$GLOBALS['wpdb']->capacities = array('fall:2026' => intake_capacity(10, 2));
$GLOBALS['wpdb']->reservations = array(
	'fall-active-1' => array('applicationId' => 'fall-active-1', 'semester' => 'fall', 'intakeYear' => 2026, 'status' => 'active'),
	'fall-active-2' => array('applicationId' => 'fall-active-2', 'semester' => 'fall', 'intakeYear' => 2026, 'status' => 'active'),
);
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('mc_agent'), 42);
$response = $plugin->rest_list_intake_capacities();
intake_assert_same(200, $response->get_status(), 'External agents must be allowed to read aggregate capacity.');
intake_assert_same(8, $response->get_data()['capacities'][0]['availablePlacements'], 'Capacity response must compute available placements.');
$forbidden = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => 7,
)));
intake_assert_same(403, $forbidden->get_status(), 'External agents must not configure capacity.');
$forbidden_cancellation = $plugin->rest_cancel_offer_placement(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'reason' => 'Agent cannot cancel an issued offer.',
	)
));
intake_assert_same(403, $forbidden_cancellation->get_status(), 'External agents must not cancel an offer placement.');

$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$missing_capacity_version = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => 7,
)));
intake_assert_same(400, $missing_capacity_version->get_status(), 'Updating an existing intake must require its current version.');
$stale_capacity = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => 7,
	'expectedUpdatedAt' => '2026-08-20T09:59:59.000Z',
)));
intake_assert_same(409, $stale_capacity->get_status(), 'A stale placement update must return a conflict.');
$saved = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => 7,
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
intake_assert_same(200, $saved->get_status(), 'Administrator must configure capacity.');
intake_assert_same(2, $saved->get_data()['capacity']['reservedPlacements'], 'Existing capacity edits must reconcile active reservations.');
intake_assert_same(9, $saved->get_data()['capacity']['totalPlacements'], 'Existing edits must store desired availability plus active reservations.');
intake_assert_same(7, $saved->get_data()['capacity']['availablePlacements'], 'Existing capacity edits must preserve the administrator-entered current availability.');
$negative = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => -1,
)));
intake_assert_same(400, $negative->get_status(), 'Negative capacity must be rejected.');
$overflow_capacity = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2026, 'availablePlacements' => 1000000,
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
intake_assert_same(400, $overflow_capacity->get_status(), 'Available plus reserved placements must not overflow the supported total.');
$delete_reserved = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'fall', 'intake_year' => '2026'),
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
));
intake_assert_same(409, $delete_reserved->get_status(), 'An intake with active reservations must not be deleted.');

// First configuration takes a CURRENT AVAILABLE baseline. Existing Bachelor
// offers are lazily reserved for this intake, while the visible availability
// remains exactly what the administrator entered.
$GLOBALS['wpdb']->reservations = array(
	'legacy-released' => array(
		'applicationId' => 'legacy-released', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'intakeYear' => 2027, 'status' => 'released',
	),
);
$GLOBALS['wpdb']->historicalOfferCandidates = array(
	array(
		'applicationId' => 'legacy-offer-1', 'programmeCode' => 'business-administration',
		'semester' => 'Summer Semester', 'year' => '2027', 'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 0,
		'inputSnapshot' => json_encode(array('programmeCode' => 'business-administration', 'intakeLabel' => 'Summer 2027')),
		'generatedLetterId' => 'legacy-letter-1',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-01 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-offer-2', 'programmeCode' => 'hotel-casino-resort-management',
		'semester' => 'summer', 'year' => '2027', 'status' => 'prepayment-pending',
		'reviewerDecision' => 'conditional-offer', 'isTestData' => 0, 'inputSnapshot' => null,
		'generatedLetterId' => 'legacy-letter-2',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-02 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-review-pending-offer', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'Under review',
		'reviewerDecision' => 'pending', 'isTestData' => 0,
		'inputSnapshot' => json_encode(array('programmeCode' => 'business-administration', 'intakeLabel' => 'Summer 2027')),
		'generatedLetterId' => 'legacy-letter-review-pending',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-02 10:00:00.000',
	),
	array(
		'applicationId' => 'legacy-released', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'acceptance-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 0, 'inputSnapshot' => null,
		'generatedLetterId' => 'legacy-letter-released',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-03 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-test-data', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 1, 'inputSnapshot' => null,
		'generatedLetterId' => 'legacy-letter-test',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-04 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-rejected', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'rejected',
		'reviewerDecision' => 'Rejected', 'isTestData' => 0, 'inputSnapshot' => null,
		'generatedLetterId' => 'legacy-letter-rejected',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-05 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-snapshot-mismatch', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 0,
		'inputSnapshot' => json_encode(array('programmeCode' => 'business-administration', 'intakeLabel' => 'Fall 2027')),
		'generatedLetterId' => 'legacy-letter-mismatch',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-06 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-workbook-semester-mismatch', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 0,
		'inputSnapshot' => json_encode(array('programmeCode' => 'business-administration', 'workbookSemester' => 'fall')),
		'generatedLetterId' => 'legacy-letter-workbook-mismatch',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-07 09:00:00.000',
	),
	array(
		'applicationId' => 'legacy-concurrent-intake-change', 'programmeCode' => 'business-administration',
		'semester' => 'summer', 'year' => '2027', 'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared', 'isTestData' => 0, 'inputSnapshot' => null,
		'generatedLetterId' => 'legacy-letter-concurrent-change',
		'generatedByName' => 'Legacy Staff', 'createdAt' => '2026-08-08 09:00:00.000',
	),
);
$GLOBALS['wpdb']->lockedHistoricalApplications['legacy-concurrent-intake-change'] = array(
	'applicationId' => 'legacy-concurrent-intake-change', 'programmeCode' => 'business-administration',
	'semester' => 'fall', 'year' => '2027', 'status' => 'offer-issued',
	'reviewerDecision' => 'academically-cleared', 'isTestData' => 0,
);
$baseline_event_start = count($GLOBALS['wpdb']->events);
$baseline = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'summer', 'intakeYear' => 2027, 'availablePlacements' => 10,
)));
intake_assert_same(200, $baseline->get_status(), 'A new intake may be configured without a prior version.');
intake_assert_same(3, $baseline->get_data()['capacity']['reservedPlacements'], 'First configuration must reserve each pre-feature Bachelor offer exactly once, including offers still in review-pending.');
intake_assert_same(13, $baseline->get_data()['capacity']['totalPlacements'], 'Stored total must include current availability plus historical active offers.');
intake_assert_same(10, $baseline->get_data()['capacity']['availablePlacements'], 'Historical baseline reservations must not reduce the administrator-entered current availability.');
$baseline_events = array_slice($GLOBALS['wpdb']->events, $baseline_event_start);
$historical_lock_index = null;
$capacity_lock_index = null;
foreach ($baseline_events as $index => $event) {
	if (null === $historical_lock_index && false !== strpos($event, 'intake historical candidate lock')) {
		$historical_lock_index = $index;
	}
	if (null === $capacity_lock_index && false !== strpos($event, 'FROM mc_admission_intake_capacities') && false !== strpos($event, 'FOR UPDATE')) {
		$capacity_lock_index = $index;
	}
}
intake_assert_same(true, is_int($historical_lock_index), 'Historical baseline must lock candidate applications.');
intake_assert_same(true, is_int($capacity_lock_index), 'Historical baseline must lock the intake capacity row.');
intake_assert_same(true, $historical_lock_index < $capacity_lock_index, 'Historical application locks must precede the capacity lock to match offer/application lock ordering.');
intake_assert_same('released', $GLOBALS['wpdb']->reservations['legacy-released']['status'], 'A released reservation must never be reactivated by baseline creation.');
intake_assert_same('active', $GLOBALS['wpdb']->reservations['legacy-review-pending-offer']['status'], 'A historical Bachelor offer in legacy Under review status must be baselined as canonical review-pending.');
intake_assert_same('legacy-letter-review-pending', $GLOBALS['wpdb']->reservations['legacy-review-pending-offer']['generatedLetterId'], 'The review-pending baseline must retain its issued offer metadata without requiring a cleared review decision.');
foreach (array('legacy-test-data', 'legacy-rejected', 'legacy-snapshot-mismatch', 'legacy-workbook-semester-mismatch', 'legacy-concurrent-intake-change') as $excluded_application_id) {
	intake_assert_same(false, isset($GLOBALS['wpdb']->reservations[$excluded_application_id]), 'Ineligible historical offer ' . $excluded_application_id . ' must not enter the active baseline.');
}

$baseline_staff = intake_user(array('admissions-officer'));
$historical_application = intake_application(array(
	'id' => 'legacy-offer-1', 'semester' => 'summer', 'year' => '2027', 'isTestData' => 0,
));
intake_assert_same(false, $reserve_offer->invoke($plugin, $historical_application, 'legacy-letter-reissued', $baseline_staff), 'Reissuing a historical offer must reuse its lazy baseline reservation.');
intake_assert_same(3, $GLOBALS['wpdb']->capacities['summer:2027']['reservedPlacements'], 'Historical reissue must not deduct twice.');

$GLOBALS['wpdb']->application = $historical_application;
$historical_cancel = $cancel_offer->invoke(
	$plugin,
	'legacy-offer-1',
	'2026-08-20T10:00:00.000Z',
	'Historical offer explicitly cancelled.',
	$baseline_staff
);
intake_assert_same(2, $GLOBALS['wpdb']->capacities['summer:2027']['reservedPlacements'], 'Cancelling a historical offer must restore exactly one place.');
intake_assert_same(11, $historical_cancel['intakeCapacity']['availablePlacements'], 'Cancelling a historical offer must increase visible availability by one.');

// Use an unrelated empty intake for delete CAS behavior.
$GLOBALS['wpdb']->historicalOfferCandidates = array();
$GLOBALS['wpdb']->lockedHistoricalApplications = array();
$delete_fixture = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2027, 'availablePlacements' => 6,
)));
intake_assert_same(200, $delete_fixture->get_status(), 'Delete CAS test requires an empty configured intake.');
$missing_delete_version = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'fall', 'intake_year' => '2027')
));
intake_assert_same(400, $missing_delete_version->get_status(), 'Deleting an intake must require its current version.');
$stale_delete = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'fall', 'intake_year' => '2027'),
	array('expectedUpdatedAt' => '2026-08-20T09:59:59.000Z')
));
intake_assert_same(409, $stale_delete->get_status(), 'A stale placement deletion must return a conflict.');
$deleted_baseline = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'fall', 'intake_year' => '2027'),
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
));
intake_assert_same(200, $deleted_baseline->get_status(), 'An unchanged intake without reservations may be deleted.');
$duplicate_delete = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'fall', 'intake_year' => '2027'),
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
));
intake_assert_same(409, $duplicate_delete->get_status(), 'A duplicate/concurrent delete using a prior revision must return a stale conflict.');
$stale_recreate = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'fall', 'intakeYear' => 2027, 'availablePlacements' => 7,
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
)));
intake_assert_same(409, $stale_recreate->get_status(), 'A client holding a deleted row revision must not recreate it as an unversioned update.');

$inconsistent_capacity = $plugin->rest_save_intake_capacity(new WP_REST_Request(array(), array(
	'semester' => 'spring', 'intakeYear' => 2028, 'availablePlacements' => 4,
)));
intake_assert_same(200, $inconsistent_capacity->get_status(), 'Defensive reservation-row deletion test requires a capacity record.');
$GLOBALS['wpdb']->reservations['legacy-active'] = array(
	'applicationId' => 'legacy-active', 'programmeCode' => 'business-administration',
	'semester' => 'spring', 'intakeYear' => 2028, 'status' => 'active',
);
$delete_inconsistent = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'spring', 'intake_year' => '2028'),
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
));
intake_assert_same(409, $delete_inconsistent->get_status(), 'An active reservation row must block deletion even when the stored counter is zero.');
$GLOBALS['wpdb']->reservations['legacy-active']['status'] = 'released';
$delete_reconciled = $plugin->rest_delete_intake_capacity(new WP_REST_Request(
	array('semester' => 'spring', 'intake_year' => '2028'),
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
));
intake_assert_same(200, $delete_reconciled->get_status(), 'Released reservation rows must not block capacity deletion.');
$GLOBALS['wpdb']->reservations = array();

// Exact-once reservation, zero-capacity/concurrency protection, unlimited
// programmes, explicit release, reissue, and transactional rollback.
$GLOBALS['wpdb']->reset_transaction_state();
$GLOBALS['wpdb']->reservations = array(
	'application-1' => array(
		'applicationId' => 'application-1', 'programmeCode' => 'business-administration',
		'semester' => 'fall', 'intakeYear' => 2026, 'status' => 'active',
		'generatedLetterId' => 'letter-existing', 'reservedAt' => '2026-08-20 09:00:00.000',
		'reservedByName' => 'Staff User', 'releasedAt' => null, 'releasedByName' => null,
		'cancellationReason' => null, 'updatedAt' => '2026-08-20 09:00:00.000',
	),
);
$assert_reservation_intake->invoke($plugin, 'application-1', intake_draft());
foreach (
	array(
		intake_draft(array('programme' => 'business-administration-masters')),
		intake_draft(array('semester' => 'spring')),
		intake_draft(array('year' => '2027')),
	) as $changed_intake
) {
	intake_assert_throws_contains('Cancel the active offer', static function () use ($assert_reservation_intake, $plugin, $changed_intake) {
		$assert_reservation_intake->invoke($plugin, 'application-1', $changed_intake);
	}, 'Programme and intake fields must be immutable while an offer reservation is active.');
}
$GLOBALS['wpdb']->reservations['application-1']['status'] = 'released';
$assert_reservation_intake->invoke(
	$plugin,
	'application-1',
	intake_draft(array('programme' => 'business-administration-masters', 'semester' => 'spring', 'year' => '2027'))
);

$GLOBALS['wpdb']->reservations = array();
$GLOBALS['wpdb']->capacities = array();
$staff = intake_user(array('admissions-officer'));
$application = intake_application(array('isTestData' => 0));
intake_assert_throws_contains('has not been configured', static function () use ($reserve_offer, $plugin, $application, $staff) {
	$reserve_offer->invoke($plugin, $application, 'letter-without-capacity', $staff);
}, 'A production Bachelor offer must be blocked until its exact intake is configured.');
intake_assert_same(array(), $GLOBALS['wpdb']->reservations, 'A missing intake configuration must not create a reservation.');

$GLOBALS['wpdb']->capacities = array('fall:2026' => intake_capacity(1, 0));
intake_assert_same(true, $reserve_offer->invoke($plugin, $application, 'letter-1', $staff), 'First Bachelor offer must reserve one placement.');
intake_assert_same(1, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'First offer must increment the counter exactly once.');
intake_assert_same(false, $reserve_offer->invoke($plugin, $application, 'letter-2', $staff), 'Reissuing an active offer must reuse its reservation.');
intake_assert_same(1, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Reissue must not double-deduct.');
intake_assert_same('letter-2', $GLOBALS['wpdb']->reservations['application-1']['generatedLetterId'], 'Reissue must update the reservation audit pointer.');
intake_assert_throws_contains('No bachelor placements', static function () use ($reserve_offer, $plugin, $staff) {
	$second = intake_application(array('id' => 'application-2', 'isTestData' => 0));
	$reserve_offer->invoke($plugin, $second, 'letter-3', $staff);
}, 'A second concurrent-equivalent issuance must be blocked at capacity zero.');

$counter_before_unlimited = $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'];
intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('programmeCode' => 'business-administration-masters')), 'letter-mba', $staff), 'MBA must be unlimited.');
intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('programmeCode' => 'english-foundation')), 'letter-foundation', $staff), 'Foundation must be unlimited.');
intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('isTestData' => 1)), 'letter-test-bachelor', $staff), 'Test-data Bachelor offers must be capacity-exempt.');
intake_assert_same($counter_before_unlimited, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Unlimited programmes must never change Bachelor capacity.');
$test_capacity_snapshot = $capacity_snapshot->invoke($plugin, intake_application(array('isTestData' => 1)));
intake_assert_same(false, $test_capacity_snapshot['limited'], 'Test-data Bachelor cases must expose capacity as not applicable.');
intake_assert_same(false, $test_capacity_snapshot['configured'], 'Capacity-exempt test cases must match unlimited-programme response parity.');

// Cancellation is the only release command. It decrements once, is audited,
// and returns a complete response even if the post-commit reload fails.
$GLOBALS['wpdb']->application = intake_application(array('isTestData' => 0));
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$cancelled_case = $cancel_offer->invoke(
	$plugin,
	'application-1',
	'2026-08-20T10:00:00.000Z',
	'Applicant withdrew before accepting the offer.',
	$staff
);
intake_assert_same(0, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Explicit cancellation must restore exactly one placement.');
intake_assert_same('released', $GLOBALS['wpdb']->reservations['application-1']['status'], 'Cancellation must mark the reservation released.');
intake_assert_same('released', $cancelled_case['offerPlacementReservation']['status'], 'Cancellation response must expose released reservation metadata.');
intake_assert_same(1, $cancelled_case['intakeCapacity']['availablePlacements'], 'Cancellation response must expose restored availability.');
$GLOBALS['wpdb']->fail_post_commit_reload = false;
$cancelled_version = $cancelled_case['updatedAt'];
intake_assert_throws_contains('no active offer', static function () use ($cancel_offer, $plugin, $staff, $cancelled_version) {
	$cancel_offer->invoke($plugin, 'application-1', $cancelled_version, 'Duplicate cancellation.', $staff);
}, 'A duplicate cancellation must not release another placement.');
intake_assert_same(0, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Duplicate cancellation must leave the counter unchanged.');

intake_assert_same(true, $reserve_offer->invoke($plugin, $GLOBALS['wpdb']->application, 'letter-reissued', $staff), 'Reissuing after explicit cancellation must reserve again.');
intake_assert_same(1, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Reissue after cancellation must deduct exactly once.');

// Cancellation reconciles the reservation's original intake even if legacy or
// out-of-band data already changed the current application to an unlimited programme.
$GLOBALS['wpdb']->application['programmeCode'] = 'business-administration-masters';
$GLOBALS['wpdb']->application['programmeLabel'] = "Master's degree in Business Administration (MBA)";
$cancel_offer->invoke(
	$plugin,
	'application-1',
	$cancelled_version,
	'Correcting a legacy programme change after offer issue.',
	$staff
);
intake_assert_same(0, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Cancellation must release the original reservation even if the current programme no longer matches.');
intake_assert_same('released', $GLOBALS['wpdb']->reservations['application-1']['status'], 'Legacy mismatches must not strand an active reservation.');

$GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'] = 0;
$GLOBALS['wpdb']->reservations = array();
$GLOBALS['wpdb']->fail_reservation_write = true;
$GLOBALS['wpdb']->query('START TRANSACTION');
try {
	$reserve_offer->invoke($plugin, intake_application(array('isTestData' => 0)), 'letter-failing', $staff);
	throw new RuntimeException('Reservation write failure was expected.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	$GLOBALS['wpdb']->query('ROLLBACK');
}
intake_assert_same(0, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'A failed reservation write must roll back its capacity increment.');
intake_assert_same(array(), $GLOBALS['wpdb']->reservations, 'A failed reservation write must not leave a reservation.');

// A generated-letter write must return its committed application CAS token
// even when both post-commit rich and base reloads fail.
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'programmeCode' => 'english-foundation',
	'programmeLabel' => 'English Foundation Year',
));
intake_seed_core_documents();
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$letter_result = $persist_letter->invoke(
	$plugin,
	'application-1',
	array(
		'templateId' => 'offer-letter',
		'templateVersion' => 'offline-v1',
		'fileName' => 'offer-letter.pdf',
		'outputFormat' => 'pdf',
		'contentBase64' => base64_encode("%PDF-1.7\n"),
		'inputSnapshot' => array('source' => 'offline-test'),
	),
	$staff,
	'2026-08-20T10:00:00.000Z'
);
intake_assert_same('2026-08-20T10:00:01.000Z', $letter_result['application']['updatedAt'], 'Generated-letter fallback must return the committed application revision after double reload failure.');
intake_assert_same('offer-letter', $letter_result['letter']['templateId'], 'Generated-letter fallback must return the committed letter contract.');

// Workflow/rejection/trash code must not contain an implicit release path.
$source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
$workflow_start = strpos($source, 'private function update_admission_application_workflow');
$workflow_end = strpos($source, 'private function update_admission_application_operations', $workflow_start);
$workflow_source = substr($source, $workflow_start, $workflow_end - $workflow_start);
intake_assert_same(false, false !== strpos($workflow_source, 'reservedPlacements = reservedPlacements - 1'), 'Workflow/rejected/trashed transitions must never restore capacity implicitly.');
intake_assert_true(false !== strpos($source, 'FOR UPDATE'), 'Capacity reservations must use database row locks.');
intake_assert_true(substr_count($source, 'ENGINE=InnoDB') >= 2, 'Capacity and reservation tables must explicitly support transactions and row locks.');
intake_assert_true(false !== strpos($source, 'reservedPlacements < totalPlacements'), 'Capacity increments must include an atomic zero-capacity guard.');
intake_assert_true(false !== strpos($source, '$this->assert_active_offer_reservation_intake_unchanged($record_id, $draft);'), 'Application save must enforce active-reservation intake immutability.');
intake_assert_true(false !== strpos($source, "\$mime_type = 'application/pdf';"), 'Validated browser PDFs must be stored with the canonical MIME type.');
intake_assert_true(false !== strpos($source, '$this->assert_bank_transaction_confirmation_removable($application_id);'), 'Document deletion must enforce the payment-evidence invariant inside its transaction.');
intake_assert_true(false !== strpos($source, "'bankTransactionConfirmationReady'"), 'Detailed case response must expose Transaction Confirmation readiness.');
intake_assert_true(false !== strpos($source, "'offerPlacementReservation'"), 'Detailed case response must expose reservation metadata.');

echo "Intake capacity and authoritative submission tests passed.\n";
