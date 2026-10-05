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
	public $boardApplications = array();
	public $profile;
	public $documents = array();
	public $capacities = array();
	public $reservations = array();
	public $generatedLetters = array();
	public $annualSeatApplications = array();
	public $historicalOfferCandidates = array();
	public $lockedHistoricalApplications = array();
	public $paymentTransactions = array();
	public $activities = array();
	public $events = array();
	public $fail_reservation_write = false;
	public $fail_activity_write = false;
	public $fail_post_commit_reload = false;
	public $failAnnualSeatQuery = false;
	public $last_error = '';
	public $missingTables = array();
	private $snapshot = null;
	private $committed = false;
	private $version_tick = 0;

	public function __construct() {
		$this->application = intake_application();
		$this->boardApplications = array($this->application);
		$this->profile = intake_profile();
	}

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

	public function prepare($query, ...$args) {
		if (1 === count($args) && is_array($args[0])) {
			$args = array_values($args[0]);
		}
		return array('query' => (string) $query, 'args' => array_values($args));
	}

	public function esc_like($value) {
		return addcslashes((string) $value, '_%\\');
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
			$table = isset($call['args'][0]) ? (string) $call['args'][0] : '';
			return in_array($table, $this->missingTables, true) ? null : $table;
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
			$template_id = isset($call['args'][1]) ? (string) $call['args'][1] : null;
			return count(array_filter($this->generatedLetters, static function ($letter) use ($application_id, $template_id) {
				return (string) ($letter['applicationId'] ?? '') === $application_id
					&& (null !== $template_id
						? (string) ($letter['templateId'] ?? '') === $template_id
						: in_array(
						(string) ($letter['templateId'] ?? ''),
						array('payment-receipt', 'acceptance-letter', 'letter-of-assurance'),
						true
					));
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
		$args = $call['args'];
		if (false !== strpos($query, 'SELECT year, programmeCode, status, reviewerDecision, isTestData')) {
			if ($this->failAnnualSeatQuery) {
				$this->last_error = 'Offline annual seat query failure.';
				return array();
			}
			$this->last_error = '';
			return array_values($this->annualSeatApplications);
		}
		if (false !== strpos($query, 'INNER JOIN mc_generated_letters')) {
			return array_values(array_filter($this->historicalOfferCandidates, function ($candidate) {
				return !isset($this->reservations[(string) ($candidate['applicationId'] ?? '')]);
			}));
		}
		if (false !== strpos($query, 'FROM mc_admission_applications app')) {
			$applications = array_values($this->boardApplications);
			if (false !== strpos($query, 'WHERE app.wordpressUserId = %d')) {
				$owner_id = (int) ($args[0] ?? 0);
				$applications = array_values(array_filter($applications, static function ($application) use ($owner_id) {
					return (int) ($application['wordpressUserId'] ?? 0) === $owner_id;
				}));
			}
			if (false !== strpos($query, 'AS acceptanceLetterCount')) {
				$applications = array_map(function ($application) {
					$application['acceptanceLetterCount'] = count(array_filter(
						$this->generatedLetters,
						static function ($letter) use ($application) {
							return (string) ($letter['applicationId'] ?? '') === (string) ($application['id'] ?? '')
								&& 'acceptance-letter' === (string) ($letter['templateId'] ?? '');
						}
					));
					return $application;
				}, $applications);
			}
			return $applications;
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
		if (false !== strpos($query, 'FROM mc_generated_letters')) {
			$application_id = (string) ($args[0] ?? '');
			return array_values(array_filter($this->generatedLetters, static function ($letter) use ($application_id) {
				return (string) ($letter['applicationId'] ?? '') === $application_id;
			}));
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
				'generatedLetters' => $this->generatedLetters,
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
		if (false !== strpos($query, "SET status = 'acceptance-issued'")) {
			$source_statuses = array('offer-issued', 'prepayment-pending', 'Offer letter issued', 'Payment pending');
			if (false !== strpos($query, "'Payment pending', 'acceptance-issued', 'Acceptance confirmed'")) {
				$source_statuses[] = 'acceptance-issued';
				$source_statuses[] = 'Acceptance confirmed';
			}
			if (!in_array((string) $this->application['status'], $source_statuses, true)) {
				return 0;
			}
			$this->version_tick++;
			$this->application['status'] = 'acceptance-issued';
			$this->application['workflowNote'] = (string) ($args[0] ?? '');
			$this->application['lastUpdatedByName'] = (string) ($args[1] ?? '');
			$this->application['updatedAt'] = sprintf('2026-08-20 10:00:%02d.000', $this->version_tick);
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
		if ('mc_admission_applications' === $table) {
			$this->version_tick++;
			$this->application = array_merge(
				$this->application,
				$data,
				array('updatedAt' => sprintf('2026-08-20 10:00:%02d.000', $this->version_tick))
			);
			return 1;
		}
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
		if ('mc_admission_applications' === $table) {
			$this->application = array_merge(intake_application(), $data);
			$this->boardApplications = array($this->application);
			return 1;
		}
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
		if ('mc_generated_letters' === $table) {
			if (!isset($data['createdAt'])) {
				$data['createdAt'] = '2026-08-20 10:00:00.000';
			}
			$this->generatedLetters[] = $data;
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
		$this->failAnnualSeatQuery = false;
		$this->last_error = '';
		$this->missingTables = array();
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
		'mc_admissions_migration_case_schema_version' => '0.2.64',
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
	if (42 === (int) $user_id) {
		return (object) array(
			'ID' => 42, 'user_login' => 'agency-owner', 'display_name' => 'Agency Owner',
			'user_email' => 'agency@example.com', 'roles' => array('mc_agent'), 'allcaps' => array(),
		);
	}
	if (84 === (int) $user_id) {
		return (object) array(
			'ID' => 84, 'user_login' => 'MC-ADMISSIONS-DPT', 'display_name' => 'MC Admissions Department',
			'user_email' => 'admissions@example.com', 'roles' => array('mc_agent'), 'allcaps' => array(),
		);
	}
	return false;
}
function get_user_by($field, $value) {
	return 'login' === (string) $field && 'mc-admissions-dpt' === strtolower((string) $value)
		? get_userdata(84)
		: false;
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
$agency_profile_cache = $reflection->getProperty('agency_profile_cache');
$agency_profile_cache->setAccessible(true);
$normalize_draft = intake_private_method($reflection, 'normalize_application_intake_draft');
$assert_submission = intake_private_method($reflection, 'assert_review_submission_complete');
$reserve_offer = intake_private_method($reflection, 'reserve_offer_placement_for_generated_letter');
$cancel_offer = intake_private_method($reflection, 'cancel_offer_placement_reservation');
$bank_pdf = intake_private_method($reflection, 'assert_bank_transaction_confirmation_pdf');
$bank_ready = intake_private_method($reflection, 'bank_transaction_confirmation_ready');
$bank_removable = intake_private_method($reflection, 'assert_bank_transaction_confirmation_removable');
$assert_letter_available = intake_private_method($reflection, 'assert_admission_letter_generation_available');
$can_generate_letter = intake_private_method($reflection, 'can_generate_admission_letter');
$resolve_application_owner = intake_private_method($reflection, 'resolve_application_owner');
$persist_letter = intake_private_method($reflection, 'persist_generated_admission_letter');
$to_case = intake_private_method($reflection, 'to_admission_case');
$filter_case_for_user = intake_private_method($reflection, 'application_case_response_for_user');
$capacity_snapshot = intake_private_method($reflection, 'application_intake_capacity_snapshot');
$build_annual_seat_summary = intake_private_method($reflection, 'build_annual_bachelor_seat_summary');
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
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('finance-officer'), 13);
$normal_unauthorized_save = $plugin->rest_save_application(new WP_REST_Request(array(), array_merge(
	$save_body,
	array('expectedUpdatedAt' => '2026-08-20T10:00:00.000Z')
)));
intake_assert_same(400, $normal_unauthorized_save->get_status(), 'Foundation-specific forbidden mapping must not change the legacy normal-application save response.');
intake_assert_true(false !== stripos((string) $normal_unauthorized_save->get_data()['error'], 'permission'), 'A rejected normal save must retain its actionable authorization error.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));

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
$required_submission_fields = array(
	'fullName' => 'Full name',
	'passportNumber' => 'Passport number',
	'email' => 'Applicant email',
	'phone' => 'Phone number',
	'birthday' => 'Birthday',
	'address' => 'Home address',
	'city' => 'City',
	'postalCode' => 'Postal code',
	'country' => 'Country',
	'gender' => 'Gender',
	'programme' => 'Programme',
	'semester' => 'Semester intake',
	'year' => 'Intake year',
	'submissionDate' => 'Date of submission',
);
foreach ($required_submission_fields as $field => $label) {
	intake_assert_throws_contains($label, static function () use ($assert_submission, $plugin, $field) {
		$assert_submission->invoke(
			$plugin,
			intake_draft(array($field => '   ')),
			intake_profile_identity(),
			42,
			'application-1'
		);
	}, 'Ordinary review submission must require the ' . $label . ' field.');
}
$required_submission_declarations = array(
	'tuitionAcknowledged' => 'Tuition fee policy acknowledged',
	'offerTermsAcknowledged' => 'Offer letter terms accepted',
	'gdprAcknowledged' => 'GDPR note reviewed',
);
foreach ($required_submission_declarations as $field => $label) {
	intake_assert_throws_contains($label, static function () use ($assert_submission, $plugin, $field) {
		$assert_submission->invoke(
			$plugin,
			intake_draft(array($field => false)),
			intake_profile_identity(),
			42,
			'application-1'
		);
	}, 'Ordinary review submission must require the ' . $label . ' declaration.');
}
$required_owner_profile_fields = array('agencyName', 'consultantName', 'consultantEmail', 'consultantPhone');
foreach ($required_owner_profile_fields as $profile_field) {
	intake_assert_throws_contains('Agency Profile', static function () use ($assert_submission, $plugin, $profile_field) {
		$owner_identity = array_merge(intake_profile_identity(), array($profile_field => ''));
		$assert_submission->invoke($plugin, intake_draft(), $owner_identity, 42, 'application-1');
	}, 'An ordinary submission must require owning profile field ' . $profile_field . '.');
}
intake_assert_throws_contains('Agency Profile', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(false), 42, 'application-1');
}, 'An explicitly incomplete owning profile must block review submission.');

// Every ordinary intake upload must be a genuine stored attachment. Boolean
// checklist claims alone do not satisfy an upload requirement.
$ordinary_required_documents = array(
	'passport' => 'Copy of passport',
	'secondaryMarksheet' => 'Copy of Secondary School (10th grade) marksheet',
	'higherSecondaryMarksheet' => 'Copy of Higher Secondary School (12th grade) marksheet',
	'englishCertificate' => 'English proficiency certificate',
	'studentSignature' => 'Student signature',
	'consultantSignature' => 'Agent / consultant signature',
);
foreach ($ordinary_required_documents as $document_type => $label) {
	intake_seed_core_documents();
	unset($GLOBALS['wpdb']->documents[$document_type]);
	intake_assert_throws_contains($label, static function () use ($assert_submission, $plugin) {
		$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(), 42, 'application-1');
	}, 'Ordinary review submission must require a stored ' . $label . ' attachment.');
}

$GLOBALS['wpdb']->documents = array();
intake_assert_throws_contains('Copy of passport', static function () use ($assert_submission, $plugin) {
	$claimed = intake_draft(array('documents' => array('passport' => true, 'secondaryMarksheet' => true)));
	$assert_submission->invoke($plugin, $claimed, intake_profile_identity(), 42, 'application-1');
}, 'A forged document checklist must not bypass uploaded attachment checks.');

// Agency onboarding documents are conditionally required when the authoritative
// owner profile does not already hold them.
$GLOBALS['wpdb']->profile = intake_profile(array('agreementOnFile' => 0, 'authorizationOnFile' => 0));
$agency_profile_cache->setValue($plugin, array());
intake_seed_core_documents();
intake_assert_throws_contains('Agency agreement', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(), 42, 'application-1');
}, 'An ordinary submission must require the Agency agreement when it is not on file.');
$GLOBALS['wpdb']->documents['agencyAgreement'] = intake_document('agencyAgreement');
intake_assert_throws_contains('Authorization certificate', static function () use ($assert_submission, $plugin) {
	$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(), 42, 'application-1');
}, 'An ordinary submission must require the Authorization certificate when it is not on file.');
$GLOBALS['wpdb']->documents['authorizationCertificate'] = intake_document('authorizationCertificate');
$assert_submission->invoke($plugin, intake_draft(), intake_profile_identity(), 42, 'application-1');
$GLOBALS['wpdb']->profile = intake_profile();
$agency_profile_cache->setValue($plugin, array());

intake_seed_core_documents();
$mba = intake_draft(array('programme' => 'business-administration-masters'));
intake_assert_throws_contains('Bachelor diploma', static function () use ($assert_submission, $plugin, $mba) {
	$assert_submission->invoke($plugin, $mba, intake_profile_identity(), 42, 'application-1');
}, 'MBA must require Bachelor diploma and transcripts.');
$GLOBALS['wpdb']->documents['bachelorDiploma'] = intake_document('bachelorDiploma');
intake_assert_throws_contains('Bachelor transcripts', static function () use ($assert_submission, $plugin, $mba) {
	$assert_submission->invoke($plugin, $mba, intake_profile_identity(), 42, 'application-1');
}, 'MBA must independently require Bachelor transcripts.');
$GLOBALS['wpdb']->documents['bachelorTranscript'] = intake_document('bachelorTranscript');
$assert_submission->invoke($plugin, $mba, intake_profile_identity(), 42, 'application-1');
$assert_submission->invoke($plugin, intake_draft(array('programme' => 'english-foundation')), intake_profile_identity(), 42, 'application-1');

// The public REST command must actually invoke the complete validator for an
// ordinary agent's draft-to-review transition. Draft saves remain intentionally
// incomplete so the wizard can be resumed later.
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'status' => 'Application in progress',
	'reviewerDecision' => 'pending',
));
intake_seed_core_documents();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('mc_agent'), 42);
$missing_address_submission = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'mode' => 'review',
	'draft' => intake_draft(array('address' => '')),
)));
intake_assert_same(400, $missing_address_submission->get_status(), 'An ordinary agent must not submit an application without a Home address.');
intake_assert_true(false !== strpos((string) $missing_address_submission->get_data()['error'], 'Home address'), 'The rejected Home address submission must identify the missing field.');
intake_assert_same('Application in progress', $GLOBALS['wpdb']->application['status'], 'Rejected ordinary submissions must remain in preparation.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'status' => 'Application in progress',
	'reviewerDecision' => 'pending',
));
$GLOBALS['wpdb']->documents = array();
$missing_upload_submission = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'mode' => 'review',
	'draft' => intake_draft(),
)));
intake_assert_same(400, $missing_upload_submission->get_status(), 'An ordinary agent must not submit without every required stored intake upload.');
intake_assert_true(false !== strpos((string) $missing_upload_submission->get_data()['error'], 'Copy of passport'), 'The rejected ordinary submission must identify its missing upload pack.');
intake_assert_same('Application in progress', $GLOBALS['wpdb']->application['status'], 'A missing upload pack must not advance the ordinary case.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'status' => 'Application in progress',
	'reviewerDecision' => 'pending',
));
$ordinary_incomplete_draft = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'mode' => 'draft',
	'draft' => intake_draft(array('address' => '')),
)));
intake_assert_same(200, $ordinary_incomplete_draft->get_status(), 'Ordinary agents must still be able to save an incomplete resumable draft.');
intake_assert_same('Application in progress', $GLOBALS['wpdb']->application['status'], 'Saving an incomplete ordinary draft must not submit it for review.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));

// Foundation advancement keeps every scalar field and declaration required,
// but reuses the student's existing College file instead of requiring duplicate
// intake uploads. Ownership and access are locked to the dedicated department.
$advancement_code = 'foundation-advancement-business-administration';
$advancement_draft = intake_draft(array('programme' => $advancement_code));
$GLOBALS['wpdb']->documents = array();
$assert_submission->invoke($plugin, $advancement_draft, intake_profile_identity(), 84, null);
intake_assert_throws_contains('Agency Profile', static function () use ($assert_submission, $plugin, $advancement_draft) {
	$assert_submission->invoke($plugin, $advancement_draft, intake_profile_identity(false), 84, null);
}, 'Foundation advancement must still require the dedicated owner account to have a complete Agency Profile.');
intake_assert_throws_contains('Phone number', static function () use ($assert_submission, $plugin, $advancement_draft) {
	$assert_submission->invoke(
		$plugin,
		array_merge($advancement_draft, array('phone' => '')),
		intake_profile_identity(),
		84,
		null
	);
}, 'Foundation advancement must still require every non-document field.');
intake_assert_throws_contains('declarations', static function () use ($assert_submission, $plugin, $advancement_draft) {
	$assert_submission->invoke(
		$plugin,
		array_merge($advancement_draft, array('gdprAcknowledged' => false)),
		intake_profile_identity(),
		84,
		null
	);
}, 'Foundation advancement must still require every declaration.');

$migration_user = intake_user(array('migration-officer'), 11);
$advancement_owner = $resolve_application_owner->invoke($plugin, $migration_user, 999, $advancement_code);
intake_assert_same(84, $advancement_owner['id'], 'Foundation advancement ownership must be forced to MC-ADMISSIONS-DPT.');
intake_assert_throws_contains('permission', static function () use ($resolve_application_owner, $plugin, $advancement_code) {
	$resolve_application_owner->invoke($plugin, intake_user(array('mc_agent')), 0, $advancement_code);
}, 'Ordinary agents must not create Foundation advancement applications.');

$advancement_case = array_merge(
	intake_application(array(
		'programmeCode' => $advancement_code,
		'programmeLabel' => 'Advancement from English Foundation Year to Bachelor’s degree in Business Administration',
		'status' => 'acceptance-issued',
		'classesStartDate' => null,
		'paymentStatus' => 'awaiting-invoice',
		'paymentAmount' => null,
	)),
	array(
		'documents' => array(), 'activities' => array(), 'communications' => array(),
		'generatedLetters' => array(), 'letterDrafts' => array(), 'commissionRecords' => array(),
		'refundRecords' => array(), 'paymentTransactions' => array(), 'migrationCase' => null,
		'immigrationCase' => null, 'bankTransactionConfirmationReady' => false,
		'intakeCapacity' => null, 'offerPlacementReservation' => null,
	)
);
$advancement_preparation_case = $to_case->invoke(
	$plugin,
	array_merge(
		$advancement_case,
		array('status' => 'profile-preparation', 'reviewerDecision' => 'pending')
	),
	false
);
intake_assert_same(0, $advancement_preparation_case['totalIntakeDocuments'], 'Foundation advancement preparation must expose no required intake-document slots.');
intake_assert_same(0, $advancement_preparation_case['intakeMissingDocs'], 'Optional Foundation advancement intake uploads must not be reported as missing.');
intake_assert_same(0, $advancement_preparation_case['missingDocs'], 'The active preparation pack must not claim missing intake documents for Foundation advancement.');
$advancement_migration_pack = $to_case->invoke($plugin, $advancement_case, false);
intake_assert_same(4, $advancement_migration_pack['totalMigrationDocuments'], 'Foundation advancement must retain the ordinary Migration pack after submission.');
intake_assert_same(4, $advancement_migration_pack['migrationMissingDocs'], 'Missing Migration documents must remain visible after Foundation advancement submission.');
intake_assert_same(4, $advancement_migration_pack['missingDocs'], 'The submitted Foundation advancement active pack must use Migration requirements.');
$advancement_immigration_pack = $to_case->invoke(
	$plugin,
	array_merge($advancement_case, array('status' => 'arrival-immigration')),
	false
);
intake_assert_same(10, $advancement_immigration_pack['totalImmigrationDocuments'], 'Foundation advancement must retain the ordinary Immigration pack at the arrival stage.');
intake_assert_same(10, $advancement_immigration_pack['immigrationMissingDocs'], 'Missing Immigration documents must remain visible for Foundation advancement.');
intake_assert_same(10, $advancement_immigration_pack['missingDocs'], 'The Foundation advancement arrival pack must use normal Immigration requirements.');
$advancement_acceptance_letter_record = array(
	'id' => 'foundation-detail-acceptance-1',
	'applicationId' => 'application-1',
	'templateId' => 'acceptance-letter',
	'templateLabel' => 'Acceptance letter',
	'templateVersion' => 'foundation-offline-v1',
	'stageKeySnapshot' => 'acceptance-issued',
	'fileName' => 'foundation-acceptance-letter.pdf',
	'outputFormat' => 'pdf',
	'generatedByName' => 'Migration Officer',
	'createdAt' => '2026-08-20 10:00:00.000',
);
$advancement_issued_case = $to_case->invoke(
	$plugin,
	array_merge($advancement_case, array('generatedLetters' => array($advancement_acceptance_letter_record))),
	false
);
intake_assert_same(1, $advancement_issued_case['acceptanceLetterCount'], 'Detailed special cases must expose their per-case Acceptance Letter count.');
$dpt_special_case = $to_case->invoke(
	$plugin,
	array_merge(
		$advancement_case,
		array(
			'wordpressUsername' => 'MC-ADMISSIONS-DPT',
			'classesStartDate' => '15/09/2026',
			'tuitionFeeFirstYear' => '4100.00',
			'tuitionFeeFollowingYears' => '3900.00',
		)
	),
	false
);
$dpt_projection_user = array(
	'id' => 84,
	'username' => 'MC-ADMISSIONS-DPT',
	'name' => 'MC Admissions Department',
	'email' => 'admissions@example.com',
	'roles' => array('mc_agent'),
);
$dpt_special_projection = $filter_case_for_user->invoke($plugin, $dpt_special_case, $dpt_projection_user);
intake_assert_same('15/09/2026', $dpt_special_projection['classesStartDate'], 'Exact DPT special-case reads must preserve the stored classes start date used to render Acceptance.');
intake_assert_same('4100.00', $dpt_special_projection['tuitionFeeFirstYear'], 'Exact DPT special-case reads must preserve the first-year tuition override.');
intake_assert_same('3900.00', $dpt_special_projection['tuitionFeeFollowingYears'], 'Exact DPT special-case reads must preserve the following-year tuition override.');
$ordinary_dpt_case = array_merge($dpt_special_case, array('programmeCode' => 'business-administration'));
$ordinary_dpt_projection = $filter_case_for_user->invoke($plugin, $ordinary_dpt_case, $dpt_projection_user);
intake_assert_same(null, $ordinary_dpt_projection['classesStartDate'], 'Exact DPT reads must not expose operations fields for ordinary programmes.');
$other_agent_projection = $filter_case_for_user->invoke(
	$plugin,
	$dpt_special_case,
	array('id' => 42, 'username' => 'agency-owner', 'name' => 'Agent', 'email' => 'agent@example.com', 'roles' => array('mc_agent'))
);
intake_assert_same(null, $other_agent_projection['tuitionFeeFirstYear'], 'The special tuition projection must not broaden to other external agents.');
$assert_letter_available->invoke($plugin, $advancement_case, 'acceptance-letter');
$advancement_before_acceptance = array_merge($advancement_case, array('status' => 'Application in progress'));
intake_assert_throws_contains('Acceptance Letter section first', static function () use ($assert_letter_available, $plugin, $advancement_before_acceptance) {
	$assert_letter_available->invoke($plugin, $advancement_before_acceptance, 'acceptance-letter');
}, 'A special draft must not generate its Acceptance Letter before final submission.');
$rejected_advancement_case = array_merge($advancement_case, array('status' => 'rejected'));
intake_assert_throws_contains('Acceptance Letter section first', static function () use ($assert_letter_available, $plugin, $rejected_advancement_case) {
	$assert_letter_available->invoke($plugin, $rejected_advancement_case, 'acceptance-letter');
}, 'A rejected Foundation advancement case must not generate an Acceptance Letter.');
intake_assert_throws_contains('do not use an Offer Letter', static function () use ($assert_letter_available, $plugin, $advancement_case) {
	$assert_letter_available->invoke($plugin, $advancement_case, 'offer-letter');
}, 'Foundation advancement must never expose Offer Letter generation.');
intake_assert_throws_contains('do not use a Payment Receipt', static function () use ($assert_letter_available, $plugin, $advancement_case) {
	$assert_letter_available->invoke($plugin, $advancement_case, 'payment-receipt');
}, 'Foundation advancement must never expose Payment Receipt generation.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, $migration_user, 'acceptance-letter', $advancement_case), 'Migration may issue the special Acceptance Letter.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, intake_user(array('administrator')), 'acceptance-letter', $advancement_case), 'Administrators may issue the special Acceptance Letter.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, intake_user(array('admissions-officer')), 'acceptance-letter', $advancement_case), 'Admissions may issue the special Acceptance Letter.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, array('id' => 84, 'username' => 'MC-ADMISSIONS-DPT', 'name' => 'MC Admissions Department', 'email' => 'admissions@example.com', 'roles' => array('mc_agent')), 'acceptance-letter', $advancement_case), 'MC-ADMISSIONS-DPT may issue its special Acceptance Letter.');
intake_assert_same(false, $can_generate_letter->invoke($plugin, intake_user(array('immigration-officer')), 'acceptance-letter', $advancement_case), 'Immigration must not issue the special Acceptance Letter.');
intake_assert_same(false, $can_generate_letter->invoke($plugin, intake_user(array('administrator')), 'offer-letter', $advancement_case), 'Even administrators must not issue an Offer Letter for Foundation advancement.');
intake_assert_same(false, $can_generate_letter->invoke($plugin, intake_user(array('finance-officer')), 'payment-receipt', $advancement_case), 'No authorized role may issue a Payment Receipt for Foundation advancement.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, intake_user(array('administrator')), 'letter-of-assurance', $advancement_case), 'Administrators must retain later-stage Letter of Assurance access for Foundation advancement.');
intake_assert_same(true, $can_generate_letter->invoke($plugin, intake_user(array('immigration-officer')), 'late-arrival-affirmation-letter', $advancement_case), 'Immigration must retain later-stage letter access after Foundation advancement reaches the ordinary migration workflow.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'wordpressUserId' => 84,
	'wordpressUsername' => 'MC-ADMISSIONS-DPT',
	'programmeCode' => $advancement_code,
	'status' => 'acceptance-issued',
));
$GLOBALS['mc_intake_current_user'] = (object) array(
	'ID' => 84,
	'user_login' => 'MC-ADMISSIONS-DPT',
	'display_name' => 'MC Admissions Department',
	'user_email' => 'admissions@example.com',
	'roles' => array('mc_agent'),
	'allcaps' => array(),
);
$forged_dpt_draft_approval = $plugin->rest_update_admission_letter_draft(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'templateId' => 'acceptance-letter',
		'action' => 'approve',
		'body' => 'Forged approval body.',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	)
));
intake_assert_same(403, $forged_dpt_draft_approval->get_status(), 'Exact DPT issuance authority must not grant crafted letter-draft approval permission.');
intake_assert_same(false, false !== strpos(implode("\n", $GLOBALS['wpdb']->events), 'START TRANSACTION'), 'A forbidden DPT draft action must fail before any write transaction.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'wordpressUserId' => 84,
	'wordpressUsername' => 'MC-ADMISSIONS-DPT',
	'wordpressEmail' => 'admissions@example.com',
	'agencyName' => 'MC Admissions Department',
	'programmeCode' => $advancement_code,
	'programmeLabel' => 'Advancement from English Foundation Year to Bachelor’s degree in Business Administration',
	'status' => 'acceptance-issued',
	'workflowNote' => 'Issue the Acceptance Letter.',
	'classesStartDate' => null,
	'paymentStatus' => 'awaiting-invoice',
	'paymentAmount' => null,
	'isTestData' => 0,
));
$advancement_acceptance_payload = array(
	'templateId' => 'acceptance-letter',
	'templateVersion' => 'foundation-offline-v1',
	'fileName' => 'foundation-acceptance-letter.pdf',
	'outputFormat' => 'pdf',
	'contentBase64' => base64_encode("%PDF-1.7\n"),
	'inputSnapshot' => array('source' => 'foundation-offline-test'),
);
$first_advancement_acceptance = $persist_letter->invoke(
	$plugin,
	'application-1',
	$advancement_acceptance_payload,
	$migration_user,
	'2026-08-20T10:00:00.000Z'
);
intake_assert_same('acceptance-issued', $first_advancement_acceptance['application']['stageKey'], 'First special Acceptance issuance must keep the directly accepted case at Acceptance.');
$issued_advancement_note = (string) $GLOBALS['wpdb']->application['workflowNote'];
intake_assert_true(false !== strpos($issued_advancement_note, 'Acceptance Letter was issued'), 'First special Acceptance issuance must persist an issued-letter workflow note under the application lock.');
intake_assert_true(false !== strpos($issued_advancement_note, 'Migration handoff'), 'The issued special workflow note must describe the next Migration handoff.');
intake_assert_same(false, false !== stripos($issued_advancement_note, 'Payment Receipt'), 'The post-issuance special workflow note must not retain the pending-letter Payment Receipt wording.');
intake_assert_same($issued_advancement_note, $first_advancement_acceptance['application']['workflowNote'], 'The generated-letter response must return the committed special issuance note.');
$special_acceptance_activities = array_values(array_filter($GLOBALS['wpdb']->activities, static function ($activity) {
	return 'Foundation advancement acceptance letter issued' === (string) ($activity['title'] ?? '');
}));
intake_assert_same(1, count($special_acceptance_activities), 'First special Acceptance issuance must record exactly one workflow handoff activity.');
$acceptance_handoff_audits = array_values(array_filter($GLOBALS['wpdb']->activities, static function ($activity) {
	return 0 === strpos((string) ($activity['title'] ?? ''), 'Workflow handoff: Acceptance package issued');
}));
intake_assert_same(1, count($acceptance_handoff_audits), 'First special Acceptance issuance must trigger the ordinary Acceptance-to-Migration role handoff.');

$second_expected_version = str_replace(' ', 'T', (string) $GLOBALS['wpdb']->application['updatedAt']) . 'Z';
$persist_letter->invoke(
	$plugin,
	'application-1',
	array_merge($advancement_acceptance_payload, array('fileName' => 'foundation-acceptance-letter-reissued.pdf')),
	$migration_user,
	$second_expected_version
);
$special_acceptance_activities = array_values(array_filter($GLOBALS['wpdb']->activities, static function ($activity) {
	return 'Foundation advancement acceptance letter issued' === (string) ($activity['title'] ?? '');
}));
$acceptance_handoff_audits = array_values(array_filter($GLOBALS['wpdb']->activities, static function ($activity) {
	return 0 === strpos((string) ($activity['title'] ?? ''), 'Workflow handoff: Acceptance package issued');
}));
intake_assert_same(1, count($special_acceptance_activities), 'Reissuing the special Acceptance Letter must not duplicate the workflow handoff activity.');
intake_assert_same(1, count($acceptance_handoff_audits), 'Reissuing the special Acceptance Letter must not notify the Migration handoff twice.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('migration-officer'), 11);
$advancement_draft_save = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'mode' => 'draft',
	'draft' => $advancement_draft,
)));
intake_assert_same(200, $advancement_draft_save->get_status(), 'Migration must be able to save a Foundation advancement draft.');
intake_assert_same('Application in progress', $GLOBALS['wpdb']->application['status'], 'A saved advancement draft must remain in preparation.');
intake_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'An unsubmitted advancement draft must not consume an annual accepted Bachelor seat.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('migration-officer'), 11);
$advancement_submission = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'mode' => 'review',
	'draft' => $advancement_draft,
)));
intake_assert_same(200, $advancement_submission->get_status(), 'Migration must be able to submit a Foundation advancement application.');
intake_assert_same(84, (int) $GLOBALS['wpdb']->application['wordpressUserId'], 'Submitted advancement must be charged to MC-ADMISSIONS-DPT.');
intake_assert_same('acceptance-issued', $GLOBALS['wpdb']->application['status'], 'Submitted advancement must skip review, offer, and payment queues.');
intake_assert_same('academically-cleared', $GLOBALS['wpdb']->application['reviewerDecision'], 'Submitted advancement must be de facto academically accepted.');
intake_assert_true(false !== strpos((string) $GLOBALS['wpdb']->application['workflowNote'], 'Issue the Acceptance Letter'), 'Submitted advancement must explain its direct Acceptance Letter handoff.');

// Generic operations PATCHes must not forge the policy-owned special review
// decision or reopen a rejected special case through the normal review queue.
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge(intake_application(), array(
	'programmeCode' => $advancement_code,
	'status' => 'profile-preparation',
	'reviewerDecision' => 'pending',
));
$forged_draft_clearance = $plugin->rest_update_operations(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'draft' => array('reviewerDecision' => 'academically-cleared'),
	)
));
intake_assert_same(400, $forged_draft_clearance->get_status(), 'A crafted operations PATCH must not academically clear an unsubmitted special draft.');
intake_assert_true(false !== strpos((string) $forged_draft_clearance->get_data()['error'], 'direct-acceptance workflow'), 'The rejected draft decision mutation must return the special workflow invariant.');
intake_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'A rejected draft decision mutation must leave the special draft pending.');

foreach (array('pending', 'hold') as $forged_active_decision) {
	$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
	$GLOBALS['wpdb']->application = intake_application(array(
		'programmeCode' => $advancement_code,
		'status' => 'acceptance-issued',
		'reviewerDecision' => 'academically-cleared',
	));
	$forged_active_response = $plugin->rest_update_operations(new WP_REST_Request(
		array('application_id' => 'application-1'),
		array(
			'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
			'draft' => array('reviewerDecision' => $forged_active_decision),
		)
	));
	intake_assert_same(400, $forged_active_response->get_status(), 'A crafted operations PATCH must not set an active special case to ' . $forged_active_decision . '.');
	intake_assert_same('academically-cleared', $GLOBALS['wpdb']->application['reviewerDecision'], 'An active special case must remain academically cleared after a rejected ' . $forged_active_decision . ' mutation.');
}

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array(
	'programmeCode' => $advancement_code,
	'status' => 'rejected',
	'reviewerDecision' => 'rejected',
));
$forged_special_reopen = $plugin->rest_update_operations(new WP_REST_Request(
	array('application_id' => 'application-1'),
	array(
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'draft' => array('reviewerDecision' => 'academically-cleared'),
	)
));
intake_assert_same(400, $forged_special_reopen->get_status(), 'A generic operations PATCH must not reopen a rejected Foundation advancement case.');
intake_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'A rejected special case must not re-enter review through a forged decision PATCH.');
intake_assert_same('rejected', $GLOBALS['wpdb']->application['reviewerDecision'], 'A rejected special case must retain its rejected decision after a forged reopen attempt.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('mc_agent'));
$unauthorized_advancement = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'mode' => 'review',
	'draft' => $advancement_draft,
)));
intake_assert_same(403, $unauthorized_advancement->get_status(), 'Ordinary agents must receive a forbidden response for a forged special programme payload. Response: ' . var_export($unauthorized_advancement->get_data(), true));
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$unauthorized_advancement_label = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'mode' => 'review',
	'draft' => array_merge(
		$advancement_draft,
		array('programme' => "Advancement from English Foundation Year to Bachelor’s degree in Business Administration")
	),
)));
intake_assert_same(403, $unauthorized_advancement_label->get_status(), 'Human-readable advancement labels must be normalized before authorization so ordinary agents cannot bypass the restricted programme gate.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));

$advancement_workflow_application = intake_application(array(
	'wordpressUserId' => 84,
	'wordpressUsername' => 'MC-ADMISSIONS-DPT',
	'wordpressEmail' => 'admissions@example.com',
	'agencyName' => 'MC Admissions Department',
	'programmeCode' => $advancement_code,
	'programmeLabel' => 'Advancement from English Foundation Year to Bachelor’s degree in Business Administration',
	'status' => 'acceptance-issued',
	'workflowNote' => 'Issue the Acceptance Letter.',
));
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = $advancement_workflow_application;
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('immigration-officer'), 12);
$forbidden_advancement_workflow = $plugin->rest_update_workflow(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'migration-documents',
)));
intake_assert_same(403, $forbidden_advancement_workflow->get_status(), 'Non-authorized internal roles must receive a forbidden response for the restricted Foundation advancement handoff.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = $advancement_workflow_application;
$dpt_user = array(
	'id' => 84,
	'username' => 'MC-ADMISSIONS-DPT',
	'name' => 'MC Admissions Department',
	'email' => 'admissions@example.com',
	'roles' => array('subscriber'),
);
intake_assert_throws_contains('Acceptance Letter before moving', static function () use ($update_workflow, $plugin, $dpt_user) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'migration-documents',
		'note' => 'Acceptance issued; begin migration preparation.',
		'user' => $dpt_user,
	));
}, 'Foundation advancement must remain in Acceptance until its Acceptance Letter is actually issued.');
intake_assert_throws_contains('Acceptance Letter before moving', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'entry-permit-processing',
		'note' => 'Attempt to skip the Acceptance and Migration handoffs.',
		'user' => intake_user(array('administrator')),
	));
}, 'Administrators must not bypass special Acceptance issuance by jumping directly to a later operational stage.');
$GLOBALS['wpdb']->generatedLetters[] = array(
	'id' => 'foundation-acceptance-1',
	'applicationId' => 'application-1',
	'templateId' => 'acceptance-letter',
	'templateLabel' => 'Acceptance letter',
	'templateVersion' => 'foundation-offline-v1',
	'stageKeySnapshot' => 'acceptance-issued',
	'fileName' => 'foundation-acceptance-letter.pdf',
	'outputFormat' => 'pdf',
	'generatedByName' => 'Migration Officer',
	'createdAt' => '2026-08-20 10:00:00.000',
);
$advancement_migration_handoff = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'migration-documents',
	'note' => 'Acceptance issued; begin migration preparation.',
	'user' => $dpt_user,
));
intake_assert_same('migration-documents', $GLOBALS['wpdb']->application['status'], 'MC-ADMISSIONS-DPT must be able to move its accepted advancement case to Migration.');
intake_assert_same(true, $advancement_migration_handoff['stageChanged'], 'The dedicated-owner Migration handoff must be recorded as a workflow stage change.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array('status' => 'acceptance-issued'));
$normal_migration_handoff = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'migration-documents',
	'note' => 'Begin ordinary migration preparation.',
	'user' => $migration_user,
));
intake_assert_same(true, $normal_migration_handoff['stageChanged'], 'Ordinary accepted cases must retain their existing Migration transition without a generated-letter existence gate.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'migration-documents',
	'reviewerDecision' => 'academically-cleared',
));
$legacy_migration_note = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'migration-documents',
	'note' => 'Legacy Migration follow-up without changing stage.',
	'user' => intake_user(array('administrator')),
));
intake_assert_same(false, $legacy_migration_note['stageChanged'], 'The Acceptance-letter gate must not block a same-stage note correction on a legacy Migration case.');
intake_assert_same('Legacy Migration follow-up without changing stage.', $GLOBALS['wpdb']->application['workflowNote'], 'A permitted legacy same-stage note correction must persist.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = $advancement_workflow_application;
foreach (array('review-pending', 'offer-issued', 'prepayment-pending') as $forbidden_advancement_status) {
	intake_assert_throws_contains('skip academic review', static function () use ($update_workflow, $plugin, $forbidden_advancement_status) {
		$update_workflow->invoke($plugin, array(
			'applicationId' => 'application-1',
			'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
			'status' => $forbidden_advancement_status,
			'note' => '',
			'user' => intake_user(array('administrator')),
		));
	}, 'Foundation advancement must not move backward into the ordinary assessment, offer, or payment queues.');
}

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = $advancement_workflow_application;
intake_assert_throws_contains('cannot return to profile preparation', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'profile-preparation',
		'note' => 'Attempt to reopen the intake wizard.',
		'user' => intake_user(array('administrator')),
	));
}, 'A submitted special case must not move backward into profile preparation.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'Application in progress',
	'reviewerDecision' => 'pending',
));
intake_assert_throws_contains('Submit the Foundation advancement intake form', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'migration-documents',
		'note' => '',
		'user' => intake_user(array('administrator')),
	));
}, 'A special draft must not bypass final form validation through the generic workflow endpoint.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'trashed',
	'reviewerDecision' => 'pending',
));
intake_assert_throws_contains('Restore the unsubmitted Foundation advancement draft to preparation', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'acceptance-issued',
		'note' => '',
		'user' => intake_user(array('administrator')),
	));
}, 'Trashing an unsubmitted special draft must not create a workflow route around final form validation.');
$restored_advancement_draft = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'profile-preparation',
	'note' => 'Restore the draft for completion.',
	'user' => intake_user(array('administrator')),
));
intake_assert_same('profile-preparation', $GLOBALS['wpdb']->application['status'], 'An unsubmitted trashed special draft may be restored only to preparation.');
intake_assert_same(true, $restored_advancement_draft['stageChanged'], 'The safe special-draft restore must remain an audited workflow transition.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'trashed',
	'reviewerDecision' => 'rejected',
));
intake_assert_throws_contains('cannot be reopened through the generic workflow', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'profile-preparation',
		'note' => 'Attempt to misclassify a rejected case as an unsubmitted draft.',
		'user' => intake_user(array('administrator')),
	));
}, 'A trashed rejected special case must not use the pending-draft restore path.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'rejected',
	'reviewerDecision' => 'rejected',
));
$GLOBALS['wpdb']->generatedLetters = array($advancement_acceptance_letter_record);
intake_assert_throws_contains('cannot be reopened through the generic workflow', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'migration-documents',
		'note' => 'Attempt to bypass rejected-state restoration through Migration.',
		'user' => intake_user(array('administrator')),
	));
}, 'A rejected special case must not jump to a later stage even when an old Acceptance Letter exists.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'trashed',
	'reviewerDecision' => 'academically-cleared',
));
intake_assert_throws_contains('Acceptance Letter before moving', static function () use ($update_workflow, $plugin) {
	$update_workflow->invoke($plugin, array(
		'applicationId' => 'application-1',
		'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
		'status' => 'migration-documents',
		'note' => 'Attempt to skip Acceptance Letter issuance.',
		'user' => intake_user(array('administrator')),
	));
}, 'A submitted special case restored from Trash must not jump directly to Migration without an Acceptance Letter.');
$restored_submitted_advancement = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'acceptance-issued',
	'note' => 'Restore the previously submitted advancement case.',
	'user' => intake_user(array('administrator')),
));
intake_assert_same('acceptance-issued', $GLOBALS['wpdb']->application['status'], 'A previously submitted and academically cleared special case may be restored to Acceptance.');
intake_assert_same(true, $restored_submitted_advancement['stageChanged'], 'Restoring a previously submitted special case must remain an audited workflow transition.');
intake_assert_true(false !== strpos((string) $GLOBALS['wpdb']->application['workflowNote'], 'Issue the Acceptance Letter'), 'Restoring a submitted special case without an Acceptance Letter must persist the pending-letter note.');
intake_assert_same(false, false !== stripos((string) $GLOBALS['wpdb']->application['workflowNote'], 'Payment Receipt'), 'The special Acceptance restore must not use generic payment-stage wording.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = array_merge($advancement_workflow_application, array(
	'status' => 'migration-documents',
	'reviewerDecision' => 'academically-cleared',
	'isTestData' => 0,
));
$GLOBALS['wpdb']->generatedLetters = array($advancement_acceptance_letter_record);
$returned_to_issued_acceptance = $update_workflow->invoke($plugin, array(
	'applicationId' => 'application-1',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'status' => 'acceptance-issued',
	'note' => 'Generic caller-provided acceptance note.',
	'user' => intake_user(array('administrator')),
));
intake_assert_same(true, $returned_to_issued_acceptance['stageChanged'], 'A submitted special case with an issued letter may move back to Acceptance for correction.');
intake_assert_true(false !== strpos((string) $GLOBALS['wpdb']->application['workflowNote'], 'Acceptance Letter was issued'), 'A special Acceptance correction with a generated letter must persist the issued-letter note.');
intake_assert_same(1, count($GLOBALS['wpdb']->activities), 'Moving a special case back to Acceptance must suppress generic stage/note email audit rows; the letter issuance owns delivery.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));

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

// Annual Bachelor availability is a College-wide informational counter. It
// combines semesters, counts academically-cleared active applications, keeps
// availability at zero once the 152 ceiling is exceeded, and excludes all
// non-Bachelor, test, rejected, and trashed records.
$annual_fixtures = array(
	intake_application(array('id' => 'spring-accepted', 'semester' => 'spring', 'year' => '2026', 'isTestData' => 0, 'status' => 'offer-issued', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'fall-accepted', 'semester' => 'fall', 'year' => '2026', 'isTestData' => 0, 'programmeCode' => 'hotel-casino-resort-management', 'status' => 'Acceptance Confirmed', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'advancement-business-accepted', 'semester' => 'spring', 'year' => '2026', 'isTestData' => 0, 'programmeCode' => 'foundation-advancement-business-administration', 'status' => 'acceptance-issued', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'advancement-hotel-accepted', 'semester' => 'summer', 'year' => '2026', 'isTestData' => 0, 'programmeCode' => 'foundation-advancement-hotel-casino-resort-management', 'status' => 'migration-documents', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'advancement-draft-excluded', 'semester' => 'fall', 'year' => '2026', 'isTestData' => 0, 'programmeCode' => 'foundation-advancement-business-administration', 'status' => 'Application in progress', 'reviewerDecision' => 'pending')),
	intake_application(array('id' => 'next-year-accepted', 'semester' => 'summer', 'year' => '2027', 'isTestData' => 0, 'status' => 'review-pending', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'test-excluded', 'year' => '2027', 'isTestData' => 1, 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'rejected-excluded', 'year' => '2027', 'isTestData' => 0, 'status' => 'Rejected', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'trashed-excluded', 'year' => '2027', 'isTestData' => 0, 'status' => 'trashed', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'conditional-excluded', 'year' => '2027', 'isTestData' => 0, 'reviewerDecision' => 'conditional-offer')),
	intake_application(array('id' => 'mba-year-excluded', 'year' => '2028', 'isTestData' => 0, 'programmeCode' => 'business-administration-masters', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'foundation-year-excluded', 'year' => '2029', 'isTestData' => 0, 'programmeCode' => 'english-foundation', 'reviewerDecision' => 'academically-cleared')),
	intake_application(array('id' => 'invalid-year-excluded', 'year' => '1999', 'isTestData' => 0, 'reviewerDecision' => 'academically-cleared')),
);
$annual_summary = $build_annual_seat_summary->invoke($plugin, $annual_fixtures, 2026);
intake_assert_same(array(2026, 2027), array_column($annual_summary, 'intakeYear'), 'Annual cards must include the current year and valid Bachelor intake years only, sorted ascending.');
intake_assert_same(4, $annual_summary[0]['acceptedPlacements'], 'Ordinary and both Foundation advancement Bachelor programmes must aggregate into one annual count after academic clearance, while drafts remain excluded.');
intake_assert_same(148, $annual_summary[0]['availablePlacements'], 'Annual availability must subtract ordinary and Foundation advancement Bachelor applications from 152.');
intake_assert_same(0, $annual_summary[0]['overCapacity'], 'An in-capacity year must not report an excess.');
intake_assert_same(1, $annual_summary[1]['acceptedPlacements'], 'Rejected, trashed, test, and non-cleared applications must not consume the annual count.');

$over_capacity_rows = array();
for ($index = 0; $index < 153; $index++) {
	$over_capacity_rows[] = intake_application(array(
		'id' => 'over-' . $index,
		'semester' => 0 === $index % 3 ? 'spring' : (1 === $index % 3 ? 'summer' : 'fall'),
		'year' => '2030',
		'isTestData' => 0,
		'programmeCode' => 0 === $index % 2 ? 'business-administration' : 'hotel-casino-resort-management',
		'status' => 'offer-issued',
		'reviewerDecision' => 'academically-cleared',
	));
}
$over_capacity_summary = $build_annual_seat_summary->invoke($plugin, $over_capacity_rows, 2030);
intake_assert_same(153, $over_capacity_summary[0]['acceptedPlacements'], 'The informational counter must continue counting beyond the ceiling.');
intake_assert_same(0, $over_capacity_summary[0]['availablePlacements'], 'Available seats must floor at zero.');
intake_assert_same(1, $over_capacity_summary[0]['overCapacity'], 'Excess accepted placements must be reported explicitly.');

// The global summary is private to internal staff. External agents continue to
// receive only their scoped application list and an empty summary.
$GLOBALS['wpdb']->annualSeatApplications = array(intake_application(array(
	'id' => 'runtime-accepted',
	'year' => gmdate('Y'),
	'isTestData' => 0,
	'reviewerDecision' => 'academically-cleared',
)));
$GLOBALS['wpdb']->boardApplications = array(
	intake_application(array(
		'id' => 'agent-owned-case',
		'referenceCode' => 'MC-OWNED',
		'wordpressUserId' => 42,
		'passportNumber' => 'OWNED-PASSPORT',
		'status' => 'migration-documents',
		'permitStatus' => 'approved',
		'permitReference' => 'MP-INTERNAL',
		'commissionStatus' => 'payable',
		'refundStatus' => 'requested',
		'workflowNote' => 'Internal workflow note.',
	)),
	intake_application(array(
		'id' => 'other-agency-case',
		'referenceCode' => 'MC-FOREIGN',
		'wordpressUserId' => 77,
		'passportNumber' => 'FOREIGN-PASSPORT',
	)),
	intake_application(array(
		'id' => 'foundation-board-draft',
		'referenceCode' => 'MC-FOUNDATION-DRAFT',
		'wordpressUserId' => 84,
		'programmeCode' => $advancement_code,
		'status' => 'profile-preparation',
		'reviewerDecision' => 'pending',
	)),
	intake_application(array(
		'id' => 'foundation-board-issued',
		'referenceCode' => 'MC-FOUNDATION-ISSUED',
		'wordpressUserId' => 84,
		'programmeCode' => $advancement_code,
		'status' => 'acceptance-issued',
		'reviewerDecision' => 'academically-cleared',
	)),
);
$GLOBALS['wpdb']->generatedLetters = array(array_merge(
	$advancement_acceptance_letter_record,
	array('id' => 'foundation-board-letter', 'applicationId' => 'foundation-board-issued')
));
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$internal_board = $plugin->rest_list_applications();
intake_assert_same(200, $internal_board->get_status(), 'Internal staff must be able to load the application board with annual seats.');
intake_assert_same(1, $internal_board->get_data()['annualSeatSummary'][0]['acceptedPlacements'], 'Internal annual seats must be computed globally from the application table.');
$foundation_board_rows = array_column($internal_board->get_data()['applications'], null, 'recordId');
intake_assert_same(0, $foundation_board_rows['foundation-board-draft']['totalIntakeDocuments'], 'Board rows must expose an empty required intake pack for special drafts.');
intake_assert_same(0, $foundation_board_rows['foundation-board-draft']['intakeMissingDocs'], 'Board rows must not report optional special intake uploads as missing.');
intake_assert_same(0, $foundation_board_rows['foundation-board-draft']['missingDocs'], 'The active board pack for a special draft must have no missing intake documents.');
intake_assert_same($advancement_code, $foundation_board_rows['foundation-board-draft']['programmeCode'], 'Internal board rows must expose the canonical special programme code.');
intake_assert_same(1, $foundation_board_rows['foundation-board-issued']['acceptanceLetterCount'], 'Board rows must expose the per-case Acceptance Letter count without a detail request.');
intake_assert_same(4, $foundation_board_rows['foundation-board-issued']['totalMigrationDocuments'], 'An issued special board row must retain the normal Migration document total.');
intake_assert_same(4, $foundation_board_rows['foundation-board-issued']['migrationMissingDocs'], 'An issued special board row must retain missing Migration requirements.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('mc_agent'), 42);
$agent_board = $plugin->rest_list_applications();
intake_assert_same(200, $agent_board->get_status(), 'Agents must retain their scoped application board access.');
intake_assert_same(array(), $agent_board->get_data()['annualSeatSummary'], 'Agents must not receive College-wide annual seat totals.');
$agent_board_applications = $agent_board->get_data()['applications'];
intake_assert_same(1, count($agent_board_applications), 'Agents must receive only applications owned by their WordPress account.');
intake_assert_same('agent-owned-case', $agent_board_applications[0]['recordId'], 'The scoped board must retain the owning agent application.');
intake_assert_same('OWNED-PASSPORT', $agent_board_applications[0]['passportNumber'], 'The scoped board must retain passport-number search data.');
intake_assert_same('approved', $agent_board_applications[0]['permitStatus'], 'The scoped board must retain the current permit status.');
intake_assert_same(0, $agent_board_applications[0]['acceptanceLetterCount'], 'Scoped board rows must expose the safe per-case Acceptance Letter count.');
foreach (array('permitReference', 'commissionStatus', 'refundStatus', 'workflowNote', 'updatedByName') as $internal_field) {
	intake_assert_same(false, array_key_exists($internal_field, $agent_board_applications[0]), 'The agent board must not expose internal field ' . $internal_field . '.');
}
$GLOBALS['mc_intake_current_user'] = (object) array(
	'ID' => 84,
	'user_login' => 'MC-ADMISSIONS-DPT',
	'display_name' => 'MC Admissions Department',
	'user_email' => 'admissions@example.com',
	'roles' => array('mc_agent'),
	'allcaps' => array(),
);
$dpt_board = $plugin->rest_list_applications();
intake_assert_same(200, $dpt_board->get_status(), 'Exact DPT must retain its externally scoped board response.');
$dpt_board_rows = array_column($dpt_board->get_data()['applications'], null, 'recordId');
intake_assert_same(2, count($dpt_board_rows), 'Exact DPT must receive only its two owned special cases in the board fixture.');
intake_assert_same($advancement_code, $dpt_board_rows['foundation-board-draft']['programmeCode'], 'The scoped DPT board must expose the canonical special programme code.');
intake_assert_same(1, $dpt_board_rows['foundation-board-issued']['acceptanceLetterCount'], 'The scoped DPT board must expose whether its Acceptance Letter has been issued.');
$GLOBALS['wpdb']->failAnnualSeatQuery = true;
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$failed_internal_board = $plugin->rest_list_applications();
intake_assert_same(400, $failed_internal_board->get_status(), 'A failed global count query must not publish a false 152-seat total.');
intake_assert_true(false !== strpos($failed_internal_board->get_data()['error'], 'annual Bachelor seat summary'), 'A count-query failure must be explicit.');
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('mc_agent'), 42);
$agent_board_without_global_query = $plugin->rest_list_applications();
intake_assert_same(200, $agent_board_without_global_query->get_status(), 'Agent board reads must never execute the private global summary query.');
intake_assert_same(array(), $agent_board_without_global_query->get_data()['annualSeatSummary'], 'Agent responses must remain empty even while the private summary query is unavailable.');
$GLOBALS['wpdb']->failAnnualSeatQuery = false;

// Legacy per-intake configuration routes remain available to internal staff
// for compatibility; agents receive an empty successful response while old
// desktop versions update and never receive College-wide figures.
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
intake_assert_same(200, $response->get_status(), 'Older agent clients must receive a compatible successful capacity response.');
intake_assert_same(array(), $response->get_data()['capacities'], 'External agents must not receive College-wide legacy capacity figures.');
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
$internal_capacity_response = $plugin->rest_list_intake_capacities();
intake_assert_same(200, $internal_capacity_response->get_status(), 'Internal staff may retain legacy capacity reads during retirement.');
intake_assert_same(8, $internal_capacity_response->get_data()['capacities'][0]['availablePlacements'], 'The internal legacy response must still compute available placements.');
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
intake_assert_same(3, $GLOBALS['wpdb']->capacities['summer:2027']['reservedPlacements'], 'Cancelling a historical offer audit must not mutate the retired semester counter.');
intake_assert_same(false, $historical_cancel['intakeCapacity']['limited'], 'Legacy clients must see capacity enforcement disabled after cancellation.');
intake_assert_same(null, $historical_cancel['intakeCapacity']['availablePlacements'], 'A case response must not expose the retired semester availability as authoritative.');

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

// Legacy audit rows no longer freeze intake edits. The authoritative application
// save remains CAS-protected while Programme and intake may be corrected.
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array('isTestData' => 0));
$GLOBALS['wpdb']->reservations['application-1'] = array(
	'applicationId' => 'application-1', 'programmeCode' => 'business-administration',
	'semester' => 'fall', 'intakeYear' => 2026, 'status' => 'active',
);
$GLOBALS['mc_intake_current_user'] = intake_wp_user(array('administrator'));
$intake_edit = $plugin->rest_save_application(new WP_REST_Request(array(), array(
	'applicationId' => 'application-1',
	'mode' => 'draft',
	'expectedUpdatedAt' => '2026-08-20T10:00:00.000Z',
	'draft' => intake_draft(array('programme' => 'business-administration-masters', 'semester' => 'spring', 'year' => '2027')),
)));
intake_assert_same(200, $intake_edit->get_status(), 'An active legacy reservation must not block a Programme or intake correction.');
intake_assert_same('business-administration-masters', $GLOBALS['wpdb']->application['programmeCode'], 'The corrected Programme must persist under normal CAS rules.');
intake_assert_same('spring', $GLOBALS['wpdb']->application['semester'], 'The corrected semester must persist under normal CAS rules.');
intake_assert_same('2027', $GLOBALS['wpdb']->application['year'], 'The corrected intake year must persist under normal CAS rules.');

// Offer issuance never reads or mutates the retired capacity table. Audit rows
// remain exact-once when available, but missing/full/unconfigured legacy data
// cannot prevent issuance.
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$staff = intake_user(array('admissions-officer'));
$application = intake_application(array('isTestData' => 0));
$production_capacity_snapshot = $capacity_snapshot->invoke($plugin, $application);
intake_assert_same(false, $production_capacity_snapshot['limited'], 'Old clients must receive limited=false for a production Bachelor case.');
intake_assert_same(null, $production_capacity_snapshot['totalPlacements'], 'Retired semester totals must not be exposed as authoritative case data.');
intake_assert_same(null, $production_capacity_snapshot['availablePlacements'], 'Retired semester availability must not be exposed as authoritative case data.');

intake_assert_same(true, $reserve_offer->invoke($plugin, $application, 'letter-without-capacity', $staff), 'An unconfigured Bachelor intake must allow the Offer Letter audit to be recorded.');
intake_assert_same(array(), $GLOBALS['wpdb']->capacities, 'Unconfigured offer issuance must not create or mutate a capacity row.');
intake_assert_same(false, $reserve_offer->invoke($plugin, $application, 'letter-reissued', $staff), 'Reissuing an offer must reuse its audit row.');
intake_assert_same('letter-reissued', $GLOBALS['wpdb']->reservations['application-1']['generatedLetterId'], 'Reissue must update the audit pointer without changing capacity.');

$GLOBALS['wpdb']->capacities = array('fall:2026' => intake_capacity(1, 152));
$second_application = intake_application(array('id' => 'application-2', 'isTestData' => 0));
intake_assert_same(true, $reserve_offer->invoke($plugin, $second_application, 'letter-over-capacity', $staff), 'A full legacy intake must not block another Bachelor Offer Letter.');
intake_assert_same(152, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'Offer issuance must never mutate the legacy semester counter.');

intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('programmeCode' => 'business-administration-masters')), 'letter-mba', $staff), 'MBA must not create a Bachelor offer audit.');
intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('programmeCode' => 'english-foundation')), 'letter-foundation', $staff), 'Foundation must not create a Bachelor offer audit.');
intake_assert_same(false, $reserve_offer->invoke($plugin, intake_application(array('isTestData' => 1)), 'letter-test-bachelor', $staff), 'Test-data Bachelor offers must not create an audit row.');

// Explicit cancellation changes only the optional offer audit. It succeeds with
// no capacity row, does not claim a semester seat was returned, and retains CAS.
$GLOBALS['wpdb']->application = $application;
$GLOBALS['wpdb']->capacities = array();
$GLOBALS['wpdb']->fail_post_commit_reload = true;
$cancelled_case = $cancel_offer->invoke(
	$plugin,
	'application-1',
	'2026-08-20T10:00:00.000Z',
	'Applicant withdrew before accepting the offer.',
	$staff
);
intake_assert_same(array(), $GLOBALS['wpdb']->capacities, 'Offer cancellation must not require or create a capacity row.');
intake_assert_same('released', $GLOBALS['wpdb']->reservations['application-1']['status'], 'Cancellation must mark only the audit row released.');
intake_assert_same('released', $cancelled_case['offerPlacementReservation']['status'], 'Cancellation response must expose released audit metadata.');
intake_assert_same(false, $cancelled_case['intakeCapacity']['limited'], 'Cancellation fallback must keep capacity enforcement disabled.');
intake_assert_same(null, $cancelled_case['intakeCapacity']['availablePlacements'], 'Cancellation must not synthesize a restored semester count.');
$GLOBALS['wpdb']->fail_post_commit_reload = false;
$cancelled_version = $cancelled_case['updatedAt'];
intake_assert_throws_contains('no active offer', static function () use ($cancel_offer, $plugin, $staff, $cancelled_version) {
	$cancel_offer->invoke($plugin, 'application-1', $cancelled_version, 'Duplicate cancellation.', $staff);
}, 'A duplicate cancellation must not rewrite the released audit row.');

intake_assert_same(true, $reserve_offer->invoke($plugin, $GLOBALS['wpdb']->application, 'letter-after-cancellation', $staff), 'Reissuing after cancellation may reactivate the optional audit row.');
intake_assert_same(array(), $GLOBALS['wpdb']->capacities, 'Audit reactivation must remain independent of capacity configuration.');

// If the legacy capacity and reservation tables are absent, the audit is simply
// skipped and the generated Offer Letter transaction still commits.
$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->application = intake_application(array('isTestData' => 0));
$GLOBALS['wpdb']->missingTables = array('mc_admission_intake_capacities', 'mc_admission_offer_reservations');
intake_assert_same(false, $reserve_offer->invoke($plugin, $GLOBALS['wpdb']->application, 'letter-without-legacy-tables', $staff), 'Missing legacy tables must degrade to a skipped audit, not a capacity error.');
$missing_table_letter = $persist_letter->invoke(
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
intake_assert_same('offer-letter', $missing_table_letter['letter']['templateId'], 'Offer Letter persistence must complete without either legacy placement table.');

$GLOBALS['wpdb'] = new MC_Intake_Test_Wpdb();
$GLOBALS['wpdb']->capacities = array('fall:2026' => intake_capacity(1, 152));
$GLOBALS['wpdb']->fail_reservation_write = true;
$GLOBALS['wpdb']->query('START TRANSACTION');
try {
	$reserve_offer->invoke($plugin, intake_application(array('isTestData' => 0)), 'letter-failing', $staff);
	throw new RuntimeException('Reservation audit write failure was expected.');
} catch (ReflectionException $error) {
	throw $error;
} catch (Throwable $error) {
	$GLOBALS['wpdb']->query('ROLLBACK');
}
intake_assert_same(152, $GLOBALS['wpdb']->capacities['fall:2026']['reservedPlacements'], 'A failed audit write must never alter the retired capacity counter.');
intake_assert_same(array(), $GLOBALS['wpdb']->reservations, 'A failed audit write must not leave a partial reservation row.');

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

// Structural guards keep the retired capacity system out of offer issuance and
// cancellation while preserving the legacy tables for non-destructive rollout.
$source = file_get_contents(dirname(__DIR__) . '/mc-admissions-wordpress-backend.php');
$workflow_start = strpos($source, 'private function update_admission_application_workflow');
$workflow_end = strpos($source, 'private function update_admission_application_operations', $workflow_start);
$workflow_source = substr($source, $workflow_start, $workflow_end - $workflow_start);
intake_assert_same(false, false !== strpos($workflow_source, 'reservedPlacements = reservedPlacements - 1'), 'Generic workflow and Trash transitions must never mutate retired capacity.');
intake_assert_true(substr_count($source, 'ENGINE=InnoDB') >= 2, 'Legacy capacity and audit tables must remain intact during the non-destructive rollout.');
$reserve_lines = file($reserve_offer->getFileName());
$reserve_source = implode('', array_slice($reserve_lines, $reserve_offer->getStartLine() - 1, $reserve_offer->getEndLine() - $reserve_offer->getStartLine() + 1));
intake_assert_same(false, false !== strpos($reserve_source, 'intake_capacities_table'), 'Offer issuance must not read the retired capacity table.');
intake_assert_same(false, false !== strpos($reserve_source, 'totalPlacements'), 'Offer issuance must not compare against a semester ceiling.');
intake_assert_same(false, false !== strpos($reserve_source, 'Placement availability conflict'), 'Offer issuance must not throw a capacity conflict.');
$cancel_lines = file($cancel_offer->getFileName());
$cancel_source = implode('', array_slice($cancel_lines, $cancel_offer->getStartLine() - 1, $cancel_offer->getEndLine() - $cancel_offer->getStartLine() + 1));
intake_assert_same(false, false !== strpos(strtolower($cancel_source), 'placement was returned'), 'Cancellation must not claim that a semester seat was restored.');
intake_assert_same(false, false !== strpos($source, 'assert_active_offer_reservation_intake_unchanged'), 'Legacy audit rows must not freeze Programme or intake edits.');
intake_assert_true(false !== strpos($source, "\$mime_type = 'application/pdf';"), 'Validated browser PDFs must be stored with the canonical MIME type.');
intake_assert_true(false !== strpos($source, '$this->assert_bank_transaction_confirmation_removable($application_id);'), 'Document deletion must enforce the payment-evidence invariant inside its transaction.');
intake_assert_true(false !== strpos($source, "'bankTransactionConfirmationReady'"), 'Detailed case response must expose Transaction Confirmation readiness.');
intake_assert_true(false !== strpos($source, "'offerPlacementReservation'"), 'Detailed case response must expose reservation metadata.');

echo "Intake capacity and authoritative submission tests passed.\n";
