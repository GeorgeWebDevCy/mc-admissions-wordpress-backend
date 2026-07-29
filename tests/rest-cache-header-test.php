<?php

declare(strict_types=1);

define('ABSPATH', dirname(__DIR__) . DIRECTORY_SEPARATOR);
define('ARRAY_A', 'ARRAY_A');

final class MC_Rest_Cache_Test_Role {
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

final class MC_Rest_Cache_Test_Request {
	private $route;

	public function __construct($route) {
		$this->route = $route;
	}

	public function get_route() {
		return $this->route;
	}
}

final class MC_Rest_Cache_Test_Response {
	private $headers = array();

	public function header($key, $value, $replace = true) {
		if ($replace || !isset($this->headers[$key])) {
			$this->headers[$key] = $value;
		}
	}

	public function get_headers() {
		return $this->headers;
	}
}

final class MC_Rest_Cache_Test_Wpdb {
	public function prepare($query, ...$args) {
		return $query;
	}

	public function get_var($query) {
		return null;
	}

	public function query($query) {
		return 1;
	}
}

$GLOBALS['wpdb'] = new MC_Rest_Cache_Test_Wpdb();
$GLOBALS['mc_rest_cache_roles'] = array();
$GLOBALS['mc_rest_cache_filters'] = array();
$GLOBALS['mc_rest_cache_actions'] = array();
$GLOBALS['mc_rest_cache_nocache_headers_calls'] = 0;

function __($text, $domain = null) {
	return $text;
}

function get_role($slug) {
	return isset($GLOBALS['mc_rest_cache_roles'][$slug])
		? $GLOBALS['mc_rest_cache_roles'][$slug]
		: null;
}

function add_role($slug, $label, $capabilities = array()) {
	$role = new MC_Rest_Cache_Test_Role($label);
	$GLOBALS['mc_rest_cache_roles'][$slug] = $role;

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

function add_filter($hook, $callback, $priority = 10, $accepted_args = 1) {
	$GLOBALS['mc_rest_cache_filters'][$hook] = array(
		'callback' => $callback,
		'priority' => $priority,
		'accepted_args' => $accepted_args,
	);

	return true;
}

function add_action(...$args) {
	return true;
}

function do_action($hook, ...$args) {
	$GLOBALS['mc_rest_cache_actions'][] = array(
		'hook' => $hook,
		'args' => $args,
	);
}

function nocache_headers() {
	$GLOBALS['mc_rest_cache_nocache_headers_calls']++;
}

function register_activation_hook(...$args) {
	return true;
}

function cache_assert_same($expected, $actual, $message) {
	if ($expected !== $actual) {
		throw new RuntimeException(
			$message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.'
		);
	}
}

require dirname(__DIR__) . '/mc-admissions-wordpress-backend.php';

$plugin = mc_admissions_wordpress_backend();

cache_assert_same(
	array('priority' => 10, 'accepted_args' => 3),
	array(
		'priority' => $GLOBALS['mc_rest_cache_filters']['rest_pre_dispatch']['priority'],
		'accepted_args' => $GLOBALS['mc_rest_cache_filters']['rest_pre_dispatch']['accepted_args'],
	),
	'The namespace no-cache guard must run before MC Admissions REST callbacks.'
);
cache_assert_same(
	array('priority' => 10, 'accepted_args' => 3),
	array(
		'priority' => $GLOBALS['mc_rest_cache_filters']['rest_post_dispatch']['priority'],
		'accepted_args' => $GLOBALS['mc_rest_cache_filters']['rest_post_dispatch']['accepted_args'],
	),
	'The response no-cache headers must be applied after MC Admissions REST callbacks.'
);

$applications_request = new MC_Rest_Cache_Test_Request('/mc-admissions/v1/applications');
$sentinel = new stdClass();
$pre_dispatch_result = $plugin->disable_mc_admissions_rest_cache(
	$sentinel,
	null,
	$applications_request
);

cache_assert_same($sentinel, $pre_dispatch_result, 'The pre-dispatch guard must not replace the route result.');
cache_assert_same(true, defined('DONOTCACHEPAGE') && DONOTCACHEPAGE, 'The request must opt out of generic WordPress page caches.');
cache_assert_same(1, $GLOBALS['mc_rest_cache_nocache_headers_calls'], 'WordPress no-cache headers must be requested once.');
cache_assert_same(
	array(
		array(
			'hook' => 'litespeed_control_set_nocache',
			'args' => array('Authenticated MC Admissions REST responses must never be cached.'),
		),
	),
	$GLOBALS['mc_rest_cache_actions'],
	'The official LiteSpeed no-cache action must be emitted for the MC Admissions namespace.'
);

$response = new MC_Rest_Cache_Test_Response();
$post_dispatch_result = $plugin->add_mc_admissions_rest_no_cache_headers(
	$response,
	null,
	$applications_request
);

cache_assert_same($response, $post_dispatch_result, 'The post-dispatch guard must preserve the response object.');
cache_assert_same(
	array(
		'X-LiteSpeed-Cache-Control' => 'no-cache',
		'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
		'Pragma' => 'no-cache',
		'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
	),
	$response->get_headers(),
	'Authenticated MC Admissions REST responses must carry both LiteSpeed and standard no-cache headers.'
);

$foreign_request = new MC_Rest_Cache_Test_Request('/wp/v2/users');
$foreign_response = new MC_Rest_Cache_Test_Response();
$plugin->disable_mc_admissions_rest_cache('foreign-result', null, $foreign_request);
$plugin->add_mc_admissions_rest_no_cache_headers($foreign_response, null, $foreign_request);

cache_assert_same(1, $GLOBALS['mc_rest_cache_nocache_headers_calls'], 'Foreign REST namespaces must not trigger the no-cache guard.');
cache_assert_same(1, count($GLOBALS['mc_rest_cache_actions']), 'Foreign REST namespaces must not emit LiteSpeed controls.');
cache_assert_same(array(), $foreign_response->get_headers(), 'Foreign REST responses must remain untouched.');

$lookalike_request = new MC_Rest_Cache_Test_Request('/mc-admissions/v10/applications');
$lookalike_response = new MC_Rest_Cache_Test_Response();
$plugin->add_mc_admissions_rest_no_cache_headers($lookalike_response, null, $lookalike_request);
cache_assert_same(array(), $lookalike_response->get_headers(), 'Lookalike namespaces must not match the MC Admissions namespace.');

echo "REST cache header tests passed.\n";
