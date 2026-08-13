<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class WP_REST_Server {
	const READABLE = 'GET';
	const CREATABLE = 'POST';
}

final class WP_REST_Response {
	public function __construct($data = null, $status = 200) {}
}

final class WP_REST_Request {}

final class WP_Error {
	public function __construct($code = '', $message = '', $data = null) {}
}

final class MC_Rejection_Test_Role {
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

final class MC_Rejection_Test_Wpdb {
	public $application = array();
	public $activities = array();
	public $communications = array();
	public $events = array();
	public $force_stale = false;
	public $ignore_status_update = false;
	public $fail_rich_read_after_commit = false;
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
			if (
				$this->fail_rich_read_after_commit
				&& $this->committed
				&& false !== strpos($query, 'SELECT *')
			) {
				throw new RuntimeException('Offline rich read failure.');
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

		if (
			false !== strpos($query, 'SELECT COUNT(1) FROM mc_admission_communications')
			&& isset($args[0], $args[1])
		) {
			$count = 0;
			foreach ($this->communications as $communication) {
				if (
					(string) $communication['applicationId'] !== (string) $args[0]
					|| (string) $communication['subject'] !== (string) $args[1]
					|| false === strpos((string) $communication['detail'], 'Email delivery: sent to ')
				) {
					continue;
				}

				$reopened_after_audit = false;
				if (false !== strpos($query, 'Case reopened for review')) {
					foreach ($this->activities as $activity) {
						if (
							'workflow' === $activity['kind']
							&& in_array($activity['title'], array('Case reopened for review', 'Stage moved to review-pending'), true)
							&& (string) $activity['createdAt'] > (string) $communication['createdAt']
						) {
							$reopened_after_audit = true;
							break;
						}
					}
				}

				if (!$reopened_after_audit) {
					$count++;
				}
			}
			return $count;
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
				$column = $column_match[1];
				$value = $args[$arg_index++];
				if ('status' !== $column || !$this->ignore_status_update) {
					$this->application[$column] = $value;
				}
				continue;
			}
			if (preg_match('/^([A-Za-z0-9_]+) = NULL$/', $assignment, $column_match)) {
				$this->application[$column_match[1]] = null;
				continue;
			}
			if ('updatedAt = CURRENT_TIMESTAMP(3)' === $assignment) {
				$this->application['updatedAt'] = '2026-07-31 10:00:01.000';
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

	public function reset($application) {
		$this->application = $application;
		$this->activities = array();
		$this->communications = array();
		$this->events = array();
		$this->force_stale = false;
		$this->ignore_status_update = false;
		$this->fail_rich_read_after_commit = false;
		$this->committed = false;
		$this->transaction_snapshot = null;
	}
}

$GLOBALS['wpdb'] = new MC_Rejection_Test_Wpdb();
$GLOBALS['mc_rejection_roles'] = array();
$GLOBALS['mc_rejection_mail_calls'] = array();
$GLOBALS['mc_rejection_mail_result'] = true;
$GLOBALS['mc_rejection_uuid'] = 0;
$GLOBALS['mc_rejection_users'] = array();

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return $GLOBALS['mc_rejection_roles'][$slug] ?? null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Rejection_Test_Role($label);
	$GLOBALS['mc_rejection_roles'][$slug] = $role;
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

function sanitize_email($email) {
	return trim((string) $email);
}

function is_email($email) {
	return false !== filter_var((string) $email, FILTER_VALIDATE_EMAIL);
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

function wp_generate_uuid4() {
	$GLOBALS['mc_rejection_uuid']++;
	return 'offline-rejection-' . $GLOBALS['mc_rejection_uuid'];
}

function current_time($type, $gmt = false) {
	return '2026-07-31 10:00:02';
}

function wp_mail($to, $subject, $message, $headers = array(), $attachments = array()) {
	$GLOBALS['mc_rejection_mail_calls'][] = array(
		'to' => $to,
		'subject' => $subject,
		'message' => $message,
		'headers' => $headers,
		'attachments' => $attachments,
	);
	$GLOBALS['wpdb']->events[] = 'mail:' . $subject;
	return (bool) $GLOBALS['mc_rejection_mail_result'];
}

function get_userdata($user_id) {
	return $GLOBALS['mc_rejection_users'][(int) $user_id] ?? false;
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

function rejection_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

function rejection_assert_true($actual, $message) {
	if (!$actual) {
		throw new RuntimeException($message);
	}
}

function rejection_assert_contains($needle, $haystack, $message) {
	if (false === strpos((string) $haystack, (string) $needle)) {
		throw new RuntimeException($message . ' Missing ' . var_export($needle, true) . '.');
	}
}

function rejection_assert_throws_message($expected, $callback, $message) {
	try {
		$callback();
	} catch (Exception $error) {
		rejection_assert_same($expected, $error->getMessage(), $message);
		return;
	}

	throw new RuntimeException($message . ' No exception was thrown.');
}

function rejection_application(array $overrides = array()) {
	return array_merge(
		array(
			'id' => 'app-rejection-offline',
			'referenceCode' => 'MC-OFFLINE01',
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
			'submissionDate' => '2026-07-31',
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
			'createdAt' => '2026-07-31 09:00:00.000',
			'updatedAt' => '2026-07-31 10:00:00.000',
		),
		$overrides
	);
}

function rejection_user() {
	return array(
		'id' => 7,
		'username' => 'admissions-offline',
		'name' => 'Admissions Officer',
		'email' => 'staff@example.invalid',
		'roles' => array('admissions-officer'),
	);
}

function invoke_rejection_operations($method, array $draft, $expected_updated_at = '2026-07-31T10:00:00.000Z') {
	return $method->invoke(
		mc_admissions_wordpress_backend(),
		array(
			'applicationId' => 'app-rejection-offline',
			'draft' => $draft,
			'expectedUpdatedAt' => $expected_updated_at,
			'user' => rejection_user(),
		)
	);
}

function find_insert_by_title(array $rows, $title) {
	foreach ($rows as $row) {
		if (isset($row['title']) && $title === $row['title']) {
			return $row;
		}
	}
	return null;
}

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$operations = $reflection->getMethod('update_admission_application_operations');
$operations->setAccessible(true);

// Canonical rejection: decision and stage are persisted atomically, activities are
// committed first, and exactly the originating consultant receives the email.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['mc_rejection_mail_calls'] = array();
$GLOBALS['mc_rejection_users'][42] = (object) array(
	'ID' => 42,
	'user_login' => 'origin-agent',
	'display_name' => 'Current WordPress Agency',
	'user_email' => 'current-owner@example.com',
	'roles' => array('mc_agent'),
);
$result = invoke_rejection_operations(
	$operations,
	array(
		'reviewerDecision' => 'rejected',
		'reviewSummary' => 'The application does not meet the review criteria.',
	)
);
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'The workflow stage must be rejected.');
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['reviewerDecision'], 'The review decision must be rejected.');
rejection_assert_same(
	'Application rejected and closed after review.',
	$GLOBALS['wpdb']->application['workflowNote'],
	'The rejection must receive the direct-store default workflow note.'
);
rejection_assert_same('rejected', $result['stageKey'], 'The authoritative returned case must report the rejected stage.');
rejection_assert_true(
	null !== find_insert_by_title($GLOBALS['wpdb']->activities, 'Case closed as rejected'),
	'The rejection must record workflow activity.'
);
$operations_activity = find_insert_by_title($GLOBALS['wpdb']->activities, 'Operational details updated');
rejection_assert_true(null !== $operations_activity, 'The rejection must record operations activity.');
rejection_assert_contains('review pending -> rejected', $operations_activity['detail'], 'Operations activity must record the review change.');
rejection_assert_contains('stage review-pending -> rejected', $operations_activity['detail'], 'Operations activity must record the stage change.');
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'The rejection must send exactly one email.');
$mail = $GLOBALS['mc_rejection_mail_calls'][0];
rejection_assert_same(array('current-owner@example.com'), $mail['to'], 'Email must target the owning WordPress account current email.');
rejection_assert_same(
	'Application closed after review for Offline Student (MC-OFFLINE01)',
	$mail['subject'],
	'The rejection subject must match the direct-store notification.'
);
rejection_assert_true(
	in_array('Reply-To: Admissions Officer <staff@example.invalid>', $mail['headers'], true),
	'The email must use the acting staff member as Reply-To.'
);
rejection_assert_contains('<strong>Application:</strong> MC-OFFLINE01 / Offline Student', $mail['message'], 'The HTML email must include case context.');
rejection_assert_same(1, count($GLOBALS['wpdb']->communications), 'The delivery must create one communication audit.');
rejection_assert_contains('Recipient: Origin Consultant (current-owner@example.com).', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must identify the current WordPress recipient.');
rejection_assert_contains('Email delivery: sent to 1 recipient(s).', $GLOBALS['wpdb']->communications[0]['detail'], 'The audit must record delivery status.');
$commit_index = array_search('query:COMMIT', $GLOBALS['wpdb']->events, true);
$mail_index = array_search('mail:' . $mail['subject'], $GLOBALS['wpdb']->events, true);
rejection_assert_true(false !== $commit_index && false !== $mail_index && $commit_index < $mail_index, 'Email delivery must happen only after commit.');
unset($GLOBALS['mc_rejection_users'][42]);

// Legacy/split repair: an old Under review row whose decision is already rejected
// moves to the rejected stage and sends only when no audit exists for this cycle.
$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'status' => 'Under review',
			'reviewerDecision' => 'rejected',
		)
	)
);
$GLOBALS['mc_rejection_mail_calls'] = array();
$split_result = invoke_rejection_operations($operations, array('reviewSummary' => 'Repairing a split legacy row.'));
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'Legacy Under review must canonicalize to rejected.');
rejection_assert_same('rejected', $split_result['stageKey'], 'The repaired case response must be rejected.');
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'A split repair without an audit must send once.');

$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'status' => 'Under review',
			'reviewerDecision' => 'rejected',
		)
	)
);
$prior_subject = 'Application closed after review for Offline Student (MC-OFFLINE01)';
$GLOBALS['wpdb']->communications[] = array(
	'id' => 'prior-audit',
	'applicationId' => 'app-rejection-offline',
	'direction' => 'outbound',
	'channel' => 'email',
	'subject' => $prior_subject,
	'detail' => "Prior same-cycle delivery audit.
Email delivery: sent to 1 recipient(s).",
	'actorName' => 'Admissions Officer',
	'createdAt' => '2026-07-31 10:00:00',
);
$GLOBALS['mc_rejection_mail_calls'] = array();
invoke_rejection_operations($operations, array('reviewSummary' => 'Repair without duplicate delivery.'));
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'An audited split row must still repair its stage.');
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'A same-cycle audit must suppress duplicate email.');
rejection_assert_same(1, count($GLOBALS['wpdb']->communications), 'Duplicate suppression must not add another communication.');

// Reopening mirrors the direct-store mapping and never sends a rejection email.
$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'status' => 'rejected',
			'reviewerDecision' => 'rejected',
			'workflowNote' => 'Application rejected and closed after review.',
		)
	)
);
$GLOBALS['mc_rejection_mail_calls'] = array();
$reopened = invoke_rejection_operations($operations, array('reviewerDecision' => 'hold'));
rejection_assert_same('review-pending', $GLOBALS['wpdb']->application['status'], 'A non-rejected explicit decision must reopen the case.');
rejection_assert_same('review-pending', $reopened['stageKey'], 'The authoritative response must report the reopened stage.');
rejection_assert_same(
	'Application has been submitted and is waiting for admissions assessment and document verification.',
	$GLOBALS['wpdb']->application['workflowNote'],
	'Reopening after a rejection must use the assessment note, not the Trash restoration note.'
);
rejection_assert_true(
	null !== find_insert_by_title($GLOBALS['wpdb']->activities, 'Case reopened for review'),
	'Reopening must record workflow activity.'
);
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'Reopening must not send a rejection email.');

// A later reopen starts a new review cycle, so an older rejection audit must not
// suppress the next legitimate rejection notification.
$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'status' => 'rejected',
			'reviewerDecision' => 'rejected',
			'workflowNote' => 'Application rejected and closed after review.',
		)
	)
);
$GLOBALS['wpdb']->communications[] = array(
	'id' => 'previous-cycle-audit',
	'applicationId' => 'app-rejection-offline',
	'direction' => 'outbound',
	'channel' => 'email',
	'subject' => $prior_subject,
	'detail' => "Previous-cycle delivery audit.
Email delivery: sent to 1 recipient(s).",
	'actorName' => 'Admissions Officer',
	'createdAt' => '2026-07-30 10:00:00',
);
$GLOBALS['mc_rejection_mail_calls'] = array();
invoke_rejection_operations($operations, array('reviewerDecision' => 'hold'));
invoke_rejection_operations(
	$operations,
	array('reviewerDecision' => 'rejected'),
	'2026-07-31T10:00:01.000Z'
);
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'A new review cycle must send a fresh rejection email.');
rejection_assert_same(2, count($GLOBALS['wpdb']->communications), 'A new review cycle must append a fresh delivery audit.');

// Administrator workflow correction uses a different reopen title; it must also
// start a new delivery cycle.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['wpdb']->communications[] = array(
	'id' => 'workflow-cycle-audit',
	'applicationId' => 'app-rejection-offline',
	'direction' => 'outbound',
	'channel' => 'email',
	'subject' => $prior_subject,
	'detail' => "Previous workflow-cycle audit.\nEmail delivery: sent to 1 recipient(s).",
	'actorName' => 'Admissions Officer',
	'createdAt' => '2026-07-30 10:00:00',
);
$GLOBALS['wpdb']->activities[] = array(
	'id' => 'workflow-reopen',
	'applicationId' => 'app-rejection-offline',
	'kind' => 'workflow',
	'title' => 'Stage moved to review-pending',
	'detail' => 'Administrator stage correction.',
	'actorName' => 'Administrator',
	'actorRole' => 'internal',
	'createdAt' => '2026-07-31 09:00:00',
);
$GLOBALS['mc_rejection_mail_calls'] = array();
invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'Workflow-style reopen must allow a fresh rejection email.');
rejection_assert_same(2, count($GLOBALS['wpdb']->communications), 'Workflow-style reopen must append a fresh delivery audit.');

// A consultant address that is the student address is never used; the skipped
// delivery remains visible in the audit rather than falling back to roles/users.
$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'consultantEmail' => 'student@example.invalid',
			'email' => 'student@example.invalid',
		)
	)
);
$GLOBALS['mc_rejection_mail_calls'] = array();
invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'The student address must never receive the rejection email.');
rejection_assert_same(1, count($GLOBALS['wpdb']->communications), 'Skipped unsafe delivery must be audited once.');
rejection_assert_contains('Email delivery skipped:', $GLOBALS['wpdb']->communications[0]['detail'], 'Unsafe delivery must be classified as skipped, not failed.');
rejection_assert_contains('matches the student email', $GLOBALS['wpdb']->communications[0]['detail'], 'The skipped audit must state the safety reason.');

// Failed and unsafe delivery audits are not treated as success; a later safe
// retry on the already-rejected row is allowed and audited.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['mc_rejection_mail_calls'] = array();
$GLOBALS['mc_rejection_mail_result'] = false;
invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'A failed wp_mail attempt must still be made once.');
rejection_assert_same(1, count($GLOBALS['wpdb']->communications), 'A failed wp_mail attempt must be audited.');
rejection_assert_contains('Email delivery failed:', $GLOBALS['wpdb']->communications[0]['detail'], 'A failed wp_mail attempt must record failed delivery.');
$GLOBALS['wpdb']->application['consultantEmail'] = 'replacement-consultant@example.invalid';
$GLOBALS['mc_rejection_mail_calls'] = array();
$GLOBALS['mc_rejection_mail_result'] = true;
invoke_rejection_operations(
	$operations,
	array('reviewSummary' => 'Retry notification after mail recovery.'),
	'2026-07-31T10:00:01.000Z'
);
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'A failed delivery audit must not suppress a later retry.');
rejection_assert_same(array('replacement-consultant@example.invalid'), $GLOBALS['mc_rejection_mail_calls'][0]['to'], 'The retry must use the current exact consultantEmail.');
rejection_assert_same(2, count($GLOBALS['wpdb']->communications), 'A successful retry must append a delivery audit.');

// The minimal authoritative row sends and audits before a fragile rich case read.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['wpdb']->fail_rich_read_after_commit = true;
$GLOBALS['mc_rejection_mail_calls'] = array();
$GLOBALS['mc_rejection_mail_result'] = true;
rejection_assert_throws_message(
	'Offline rich read failure.',
	function () use ($operations) {
		invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
	},
	'A post-commit rich-read failure must surface after notification delivery.'
);
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'The committed rejection must remain authoritative after rich-read failure.');
rejection_assert_same(1, count($GLOBALS['mc_rejection_mail_calls']), 'Rich-read failure must not prevent the rejection email.');
rejection_assert_same(1, count($GLOBALS['wpdb']->communications), 'Rich-read failure must not prevent the delivery audit.');
rejection_assert_contains('Email delivery: sent to 1 recipient(s).', $GLOBALS['wpdb']->communications[0]['detail'], 'Rich-read failure must leave a successful durable audit.');

// Operations mutations require optimistic concurrency and reject malformed review
// decisions before any transaction can regress workflow state.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['mc_rejection_mail_calls'] = array();
rejection_assert_throws_message(
	'Application version is required.',
	function () use ($operations) {
		invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'), null);
	},
	'Operations updates without expectedUpdatedAt must be rejected.'
);
rejection_assert_same('review-pending', $GLOBALS['wpdb']->application['status'], 'An unversioned operation must not change workflow state.');
rejection_assert_same(0, count($GLOBALS['wpdb']->activities), 'An unversioned operation must not create activity.');
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'An unversioned operation must not send email.');

$GLOBALS['wpdb']->reset(
	rejection_application(
		array(
			'status' => 'rejected',
			'reviewerDecision' => 'rejected',
		)
	)
);
$GLOBALS['mc_rejection_mail_calls'] = array();
rejection_assert_throws_message(
	'Invalid reviewer decision.',
	function () use ($operations) {
		invoke_rejection_operations($operations, array('reviewerDecision' => 'bogus'));
	},
	'Invalid reviewer decisions must never reopen a rejected case.'
);
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['status'], 'An invalid decision must leave the case rejected.');
rejection_assert_same('rejected', $GLOBALS['wpdb']->application['reviewerDecision'], 'An invalid decision must preserve the stored review outcome.');
rejection_assert_same(0, count($GLOBALS['wpdb']->activities), 'An invalid decision must not create activity.');
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'An invalid decision must not send email.');

// Stale and unverifiable writes roll back without activities, audit, or email.
$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['wpdb']->force_stale = true;
$GLOBALS['mc_rejection_mail_calls'] = array();
rejection_assert_throws_message(
	'This application changed since you opened it. Refresh and try again.',
	function () use ($operations) {
		invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
	},
	'A stale operation must remain a 409-class mutation error.'
);
rejection_assert_same('review-pending', $GLOBALS['wpdb']->application['status'], 'A stale rejection must not change stage.');
rejection_assert_same(0, count($GLOBALS['wpdb']->activities), 'A stale rejection must not create activity.');
rejection_assert_same(0, count($GLOBALS['wpdb']->communications), 'A stale rejection must not create communication audit.');
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'A stale rejection must not send email.');

$GLOBALS['wpdb']->reset(rejection_application());
$GLOBALS['wpdb']->ignore_status_update = true;
$GLOBALS['mc_rejection_mail_calls'] = array();
rejection_assert_throws_message(
	'The application review outcome was not saved. Refresh and try again.',
	function () use ($operations) {
		invoke_rejection_operations($operations, array('reviewerDecision' => 'rejected'));
	},
	'An unverifiable stage write must fail before commit.'
);
rejection_assert_same('review-pending', $GLOBALS['wpdb']->application['status'], 'An unverifiable write must roll back the decision and stage.');
rejection_assert_same('pending', $GLOBALS['wpdb']->application['reviewerDecision'], 'An unverifiable write must roll back reviewerDecision.');
rejection_assert_same(0, count($GLOBALS['wpdb']->activities), 'An unverifiable write must roll back activities.');
rejection_assert_same(0, count($GLOBALS['mc_rejection_mail_calls']), 'An unverifiable write must not send email.');

foreach ($GLOBALS['mc_rejection_mail_calls'] as $unexpected_mail) {
	throw new RuntimeException('No final test should leave an unexpected wp_mail call.');
}

echo "Operations rejection parity tests passed.\n";
