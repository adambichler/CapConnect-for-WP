<?php

declare(strict_types=1);
define('ABSPATH', __DIR__);
define('DAY_IN_SECONDS', 86400);
define('HOUR_IN_SECONDS', 3600);
$GLOBALS['options'] = [];
$GLOBALS['scheduled'] = [];
$GLOBALS['transients'] = [];
$GLOBALS['mail'] = [];
$GLOBALS['mail_result'] = true;
$GLOBALS['result'] = ['success' => true];
/** Simulates WordPress option reads. */
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
/** Simulates WordPress option updates. */
function update_option($key, $value, $autoload = null) { $GLOBALS['options'][$key] = $value; return true; }
/** Simulates atomic option creation. */
function add_option($key, $value, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$key])) return false; $GLOBALS['options'][$key] = $value; return true; }
/** Simulates WordPress option removal. */
function delete_option($key) { unset($GLOBALS['options'][$key]); return true; }
/** Simulates transient reads. */
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
/** Simulates transient writes. */
function set_transient($key, $value, $expiration) { $GLOBALS['transients'][$key] = $value; return true; }
/** Simulates scheduled-event lookup. */
function wp_next_scheduled($hook) { return $GLOBALS['scheduled'][$hook] ?? false; }
/** Simulates scheduling an event. */
function wp_schedule_single_event($time, $hook) { $GLOBALS['scheduled'][$hook] = $time; return true; }
/** Simulates clearing an event. */
function wp_clear_scheduled_hook($hook) { unset($GLOBALS['scheduled'][$hook]); }
/** Captures outgoing mail without sending it. */
function wp_mail($to, $subject, $body) { $GLOBALS['mail'][] = compact('to', 'subject', 'body'); return $GLOBALS['mail_result']; }
/** Formats the test timestamp. */
function wp_date($format, $timestamp = null) { return '2026-11-06 12:00'; }
/** Returns the test site's name. */
function get_bloginfo($key) { return 'Test'; }
/** Returns untranslated test strings. */
function __($text, $domain = '') { return $text; }
class Capconnect_Settings {
    /** Supplies a simulated connection check. */
    public static function checkConnection(): array { return $GLOBALS['result']; }
    /** Supplies an isolated verification endpoint. */
    public static function getEndpoint(): string { return 'https://cap.example.test/site'; }
}
require dirname(__DIR__) . '/includes/class-cap-monitor.php';
require dirname(__DIR__) . '/includes/class-cap-verifier.php';
/** Supplies a fixture origin without accessing WordPress. */
function home_url($path = '') { return 'https://example.test' . $path; }
/** Parses fixture URLs. */
function wp_parse_url($url) { return parse_url($url); }
/** Encodes fixture JSON. */
function wp_json_encode($value) { return json_encode($value); }
/** Returns a simulated verification response without HTTP. */
function wp_remote_post($url, $args) { return ['success' => $GLOBALS['token_valid']]; }
/** Keeps simulated responses free of transport errors. */
function is_wp_error($response) { return false; }
/** Supplies a successful HTTP status independently of token validity. */
function wp_remote_retrieve_response_code($response) { return 200; }
/** Returns the simulated token result. */
function wp_remote_retrieve_body($response) { return json_encode($response); }
/** Supplies the expected response media type. */
function wp_remote_retrieve_header($response, $header) { return 'application/json'; }
/** Checks a test condition. */
function check($condition, $message) { if (! $condition) throw new RuntimeException($message); }
$GLOBALS['options']['capconnect_alert_email'] = 'admin@example.test';
Capconnect_Monitor::browserError('Blocked by instrumentation secret=do-not-log');
Capconnect_Monitor::browserError('hashwx worker #0 timed out after 60000ms');
check(count($GLOBALS['mail']) === 0 && empty($GLOBALS['scheduled']), 'Browser errors must not trigger alerts or checks');
check(! str_contains(json_encode($GLOBALS['options']), 'do-not-log'), 'Untrusted message must not be persisted');
Capconnect_Monitor::browserError('Failed to fetch');
check(isset($GLOBALS['scheduled']['capconnect_monitor_check']), 'Failed fetch schedules a probe');
Capconnect_Monitor::recovered();
$GLOBALS['result'] = ['success' => false, 'type' => 'connection', 'reason' => 'Challenge: HTTP 503'];
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 0, 'First failure must not send mail');
$GLOBALS['options']['capconnect_monitor_state']['first'] -= 61;
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 1 && str_contains($GLOBALS['mail'][0]['subject'], 'connection'), 'Confirmed failure must send connection mail');
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 1, 'One incident must not send duplicates');
$GLOBALS['result'] = ['success' => true];
Capconnect_Monitor::check();
check(! isset($GLOBALS['options']['capconnect_monitor_state']), 'Recovery clears incident');
$GLOBALS['result'] = ['success' => false, 'type' => 'configuration', 'reason' => 'Siteverify: HTTP 403'];
Capconnect_Monitor::check();
$GLOBALS['options']['capconnect_monitor_state']['first'] -= 61;
$GLOBALS['mail_result'] = false;
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 2 && str_contains($GLOBALS['mail'][1]['subject'], 'configuration'), 'Configuration alert must be distinguished');
check(empty($GLOBALS['options']['capconnect_monitor_state']['sent']), 'Failed mail must remain retryable');
$GLOBALS['mail_result'] = true;
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 3 && $GLOBALS['options']['capconnect_monitor_state']['sent'], 'Mail retry must succeed');
$GLOBALS['options']['capconnect_log_retention'] = 2;
$GLOBALS['options']['capconnect_debug_log'][] = ['category'=>'browser', 'message'=>'expired', 'first'=>time()-3*DAY_IN_SECONDS, 'last'=>time()-3*DAY_IN_SECONDS, 'count'=>1];
Capconnect_Monitor::prune();
check(! str_contains(json_encode($GLOBALS['options']['capconnect_debug_log']), 'expired'), 'Expired logs must be removed');
// A successful form must probe an existing incident before a new outage can alert again.
$verifier = new Capconnect_Verifier();
$GLOBALS['token_valid'] = true;
$GLOBALS['scheduled'] = [];
check($verifier->verify('valid-token'), 'Valid tokens remain accepted during a confirmed incident');
check(isset($GLOBALS['scheduled']['capconnect_monitor_check']), 'Successful verification schedules recovery confirmation');
check($GLOBALS['options']['capconnect_monitor_state']['sent'], 'Verification alone must not clear an incident');
$GLOBALS['scheduled'] = [];
Capconnect_Monitor::check();
check($GLOBALS['options']['capconnect_monitor_state']['sent'] && count($GLOBALS['mail']) === 3, 'A failed recovery probe preserves the incident without duplicate mail');
$verifier->verify('valid-token');
$GLOBALS['scheduled'] = [];
$GLOBALS['result'] = ['success' => true];
Capconnect_Monitor::check();
check(! isset($GLOBALS['options']['capconnect_monitor_state']), 'A successful recovery probe clears the incident');
check(! isset($GLOBALS['options']['capconnect_connection_failed_notice']), 'Confirmed recovery clears the notice');
$verifier->verify('valid-token');
check(empty($GLOBALS['scheduled']), 'Healthy verification must not schedule probes');
$GLOBALS['result'] = ['success' => false, 'type' => 'connection'];
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 3, 'A new outage still requires confirmation');
$GLOBALS['options']['capconnect_monitor_state']['first'] -= 61;
$GLOBALS['scheduled'] = [];
$GLOBALS['token_valid'] = false;
check(! $verifier->verify('invalid-token') && empty($GLOBALS['scheduled']), 'Rejected tokens must not trigger recovery probes');
Capconnect_Monitor::check();
check(count($GLOBALS['mail']) === 4, 'A new confirmed outage sends a new alert');
echo "Monitor tests passed.\n";
