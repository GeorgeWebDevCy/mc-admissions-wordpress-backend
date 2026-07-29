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
	public function prepare($query, ...$args) {
		return $query;
	}

	public function get_var($query) {
		return null;
	}
}

$GLOBALS['wpdb'] = new MC_Admissions_Test_Wpdb();
$GLOBALS['mc_admissions_test_roles'] = array();

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
	$versions = array(
		'mc_admissions_notification_activity_schema_version' => '1',
		'mc_admissions_resource_index_version' => '1',
		'mc_admissions_schema_version' => '0.2.14',
		'mc_admissions_offer_detail_schema_version' => '0.2.38',
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

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

$plugin = mc_admissions_wordpress_backend();
$reflection = new ReflectionClass($plugin);
$stale_policy = $reflection->getMethod('should_apply_stale_workflow_target');
$stale_policy->setAccessible(true);
$document_pack = $reflection->getMethod('active_document_pack_for_status');
$document_pack->setAccessible(true);

function assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
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
		true,
		$stale_policy->invoke($plugin, $transition[0], $transition[1]),
		'Stale exact-next forward transition should be allowed.'
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
