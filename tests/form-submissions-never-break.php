<?php
/**
 * A submission handler must never break the form it observes.
 *
 * Run with: php tests/form-submissions-never-break.php
 *
 * No framework, no WordPress: the handful of WordPress functions the plugin
 * touches on this path are stubbed below, so this runs anywhere PHP does.
 *
 * The case being pinned came off a live site. An option that earlier versions
 * could leave as an empty string was read back as one, array_filter() threw a
 * TypeError on PHP 8, and the visitor's submit returned a 500 with the lead
 * lost. It had happened once before in a different integration, which is why
 * the guard is now generic and why this file exists.
 */
define('ABSPATH', dirname(__DIR__) . '/');

$GLOBALS['lt_options'] = array();
$GLOBALS['lt_hooks']   = array();
$logfile = sys_get_temp_dir() . '/leadtrackr-test-log.txt';
@unlink($logfile);
ini_set('log_errors', '1');
ini_set('error_log', $logfile);

function get_option($name, $default = false) {
    return array_key_exists($name, $GLOBALS['lt_options']) ? $GLOBALS['lt_options'][$name] : $default;
}
function update_option($name, $value) { $GLOBALS['lt_options'][$name] = $value; return true; }
function add_option($name, $value) {
    if (!array_key_exists($name, $GLOBALS['lt_options'])) { $GLOBALS['lt_options'][$name] = $value; }
    return true;
}
function add_action($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['lt_hooks'][$hook][] = $cb; }
function add_filter($hook, $cb, $priority = 10, $args = 1) { $GLOBALS['lt_hooks'][$hook][] = $cb; }
function plugin_dir_url($f) { return 'https://example.test/wp-content/plugins/leadtrackr/'; }
function sanitize_text_field($v) { return is_string($v) ? trim(strip_tags($v)) : $v; }
function wp_unslash($v) { return $v; }
function wp_json_encode($v) { return json_encode($v); }
function home_url($p = '') { return 'https://example.test' . $p; }
function wp_parse_url($u, $c = -1) { return parse_url($u, $c); }
function esc_url_raw($u) { return $u; }
function is_wp_error($t) { return false; }
function wp_remote_post($url, $args = array()) { $GLOBALS['lt_sent'][] = $url; return array('response' => array('code' => 200), 'body' => '{}'); }
function wp_remote_retrieve_response_code($r) { return 200; }
function wp_remote_retrieve_body($r) { return isset($r['body']) ? $r['body'] : ''; }
function wp_salt($s = 'auth') { return 'test-salt-test-salt'; }
function is_admin() { return false; }
function wp_get_theme() { return null; }

require dirname(__DIR__) . '/leadtrackr.php';

$failures = 0;
function check($description, $passed) {
    global $failures;
    if (!$passed) { $failures++; }
    echo ($passed ? "  ok   " : "  FAIL ") . $description . PHP_EOL;
}

echo PHP_EOL . "1. The option holds an empty string instead of a list" . PHP_EOL;
$GLOBALS['lt_options']['leadtrackr_wpforms_forms'] = '';
check('leadtrackr_forms_option() still returns an array', is_array(leadtrackr_forms_option('leadtrackr_wpforms_forms')));

echo PHP_EOL . "2. A WPForms submission survives it" . PHP_EOL;
$GLOBALS['lt_options']['leadtrackr_wpforms_track_all'] = true;
$GLOBALS['lt_options']['leadtrackr_project_id'] = 'proj_test';
$fields = array(1 => array('name' => 'Email', 'value' => 'a@b.nl', 'type' => 'email'));
$entry  = array('fields' => array(1 => 'a@b.nl'));
$form_data = array('id' => 42, 'settings' => array('form_title' => 'Contact'));
$thrown = null;
try { leadtrackr_wpforms_forms_submission($fields, $entry, $form_data, 7); }
catch (\Throwable $e) { $thrown = get_class($e) . ': ' . $e->getMessage(); }
check('the handler throws nothing' . ($thrown ? " (kreeg: $thrown)" : ''), $thrown === null);
check('the lead is still sent', !empty($GLOBALS['lt_sent']));

echo PHP_EOL . "3. The guard contains a handler that throws" . PHP_EOL;
function lt_handler_that_throws($a, $b) { throw new \RuntimeException('boom'); }
leadtrackr_add_guarded_action('test_form_submit', 'lt_handler_that_throws', 10, 2);
$cb = $GLOBALS['lt_hooks']['test_form_submit'][0];
$thrown = null; $result = 'NOT-RUN';
try { $result = $cb('first-argument', 'second'); }
catch (\Throwable $e) { $thrown = get_class($e) . ': ' . $e->getMessage(); }
check('nothing escapes to the form' . ($thrown ? " (kreeg: $thrown)" : ''), $thrown === null);
check('the first argument is handed back (safe if the hook is a filter)', $result === 'first-argument');
$logged = file_exists($logfile) ? file_get_contents($logfile) : '';
check('the failure is logged', strpos($logged, 'boom') !== false && strpos($logged, '[LeadTrackr]') !== false);

echo PHP_EOL . "4. One unusable row does not sink the rest" . PHP_EOL;
$GLOBALS['lt_options']['leadtrackr_gf_forms'] = array('a bare string', array('no-id' => 1), array('id' => 5, 'sendToLeadTrackr' => true));
$cleaned = leadtrackr_forms_option('leadtrackr_gf_forms');
check('only usable rows survive', count($cleaned) === 1 && $cleaned[0]['id'] === 5);

echo PHP_EOL . ($failures === 0 ? "ALL CHECKS PASSED" : "$failures CHECK(S) FAILED") . PHP_EOL;
exit($failures === 0 ? 0 : 1);
