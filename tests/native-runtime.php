<?php
/** Exercises installed WordPress/WooCommerce handlers with real hooks and memory-only dependencies. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$installation = rtrim((string) getenv('CAPCONNECT_TEST_WORDPRESS'), '/');
if (!is_file($installation . '/wp-includes/user.php')) {
    throw new RuntimeException('CAPCONNECT_TEST_WORDPRESS muss auf eine lokale WordPress-Installation zeigen.');
}
define('ABSPATH', $installation . '/');
require ABSPATH . 'wp-includes/plugin.php';
require ABSPATH . 'wp-includes/class-wp-error.php';
require ABSPATH . 'wp-includes/user.php';
require ABSPATH . 'wp-content/plugins/woocommerce/includes/shortcodes/class-wc-shortcode-my-account.php';
class WooCommerce {}
class WP_User { public int $ID = 1; public string $user_login = 'adam-test'; public string $user_pass = 'fixture'; }
class Capconnect_Settings {
    /** Enables configured integrations in memory. */
    public static function isConfigured(): bool { return true; }
    /** Supplies a non-routable verification endpoint. */
    public static function getEndpoint(): string { return 'https://captcha.example.test'; }
    /** Accepts simulated service alerts without sending mail. */
    public static function sendAlertEmail(string $message): void {}
}
class Capconnect_Widget {}
class Capconnect_Login_Proof {
    public static bool $valid = false;
    /** Reads a memory-only transaction proof. */
    public function hasForUser($user): bool { return self::$valid; }
    /** Stores a proof only in memory. */
    public function issue($user): void { self::$valid = true; }
    /** Ends the isolated transaction. */
    public function consume(): void { self::$valid = false; }
}
class Capconnect_Login_Recovery {
    /** Keeps the emergency bypass disabled. */
    public function isLoginCaptchaDisabled(): bool { return false; }
    /** Supplies no recovery credentials. */
    public function consumeForUser($user): bool { return false; }
    /** Accepts failure reporting without persistence. */
    public function markCaptchaValidationFailed(): void {}
}
$options = ['capconnect_woocommerce_enabled' => true];
$notices = [];
$verificationCalls = 0;
$used = [];
/** Supplies memory-only protection settings. */
function get_option($key, $default = false) { return $GLOBALS['options'][$key] ?? $default; }
/** Supplies an existing test user without a database. */
function get_user_by($field, $value) { return new WP_User(); }
/** Removes fixture slashes. */
function wp_unslash($value) { return stripslashes($value); }
/** Sanitizes fixture text. */
function sanitize_text_field($value) { return trim(strip_tags($value)); }
/** Sanitizes the fixture username. */
function sanitize_user($value) { return trim($value); }
/** Returns untranslated fixture text. */
function __($value, $domain = '') { return $value; }
/** Escapes fixture messages. */
function esc_html($value) { return htmlspecialchars($value, ENT_QUOTES); }
/** Recognizes actual WordPress error instances. */
function is_wp_error($value) { return $value instanceof WP_Error; }
/** Disables AJAX preflight exemptions in these requests. */
function wp_doing_ajax() { return false; }
/** Models valid account credentials independently of CAP. */
function wp_check_password($password, $hash, $id) { return true; }
/** Prevents password rehash writes. */
function wp_password_needs_rehash($hash, $id) { return false; }
/** Records WooCommerce errors in memory. */
function wc_add_notice($message, $type) { $GLOBALS['notices'][] = $message; }
/** Supplies the site's public origin without configuration access. */
function home_url($path = '') { return 'https://example.test' . $path; }
/** Parses fixture URLs. */
function wp_parse_url($url) { return parse_url($url); }
/** Encodes the verification fixture. */
function wp_json_encode($value) { return json_encode($value); }
/** Models a single-use CAP service without HTTP. */
function wp_remote_post($url, $args) {
    $GLOBALS['verificationCalls']++;
    $token = json_decode($args['body'], true)['response'];
    $success = str_starts_with($token, 'valid-') && !isset($GLOBALS['used'][$token]);
    $GLOBALS['used'][$token] = true;
    return ['response' => ['code' => 200], 'body' => json_encode(['success' => $success])];
}
/** Reads the simulated HTTP status. */
function wp_remote_retrieve_response_code($response) { return $response['response']['code']; }
/** Reads the simulated HTTP body. */
function wp_remote_retrieve_body($response) { return $response['body']; }
/** Supplies the expected verification media type. */
function wp_remote_retrieve_header($response, $name) { return 'application/json'; }
/** Clears verifier flags only in memory. */
function delete_option($key) { unset($GLOBALS['options'][$key]); }
require dirname(__DIR__) . '/includes/class-cap-verifier.php';
require dirname(__DIR__) . '/includes/class-cap-woocommerce.php';
require dirname(__DIR__) . '/includes/class-cap-integrations.php';
(new Capconnect_Integrations())->init();
$checks = 0;
$pagenow = 'wp-login.php';
/** Verifies isolated handler outcomes. */
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
    $GLOBALS['checks']++;
}
// Stop accepted requests before reset keys, mail, sessions, or persistent state can be created.
add_filter('lostpassword_errors', function ($errors) {
    if (!$errors->has_errors()) throw new RuntimeException('accepted');
    return $errors;
}, PHP_INT_MAX);
add_action('retrieve_password', function () { throw new RuntimeException('accepted'); });
foreach (['missing', '', ' ', ['valid-array'], 'invalid', 'valid-runtime'] as $index => $token) {
    $_POST = ['user_login' => 'adam-test'];
    if ($token !== 'missing') $_POST['cap-token'] = $token;
    Capconnect_Login_Proof::$valid = false;
    $login = wp_authenticate_username_password(null, 'adam-test', 'correct-password');
    check(($login instanceof WP_User) === ($index === 5), 'Installed WordPress login must enforce CAP');
    foreach (['native', 'woocommerce'] as $area) {
        $notices = [];
        if ($index === 5) $_POST['cap-token'] = 'valid-runtime-' . $area;
        $accepted = false;
        try {
            $result = $area === 'native' ? retrieve_password('adam-test') : WC_Shortcode_My_Account::retrieve_password();
            check($area === 'native' ? is_wp_error($result) : ($result === false && $notices !== []), 'Handler rejects CAP before password mail');
        } catch (RuntimeException $exception) {
            if ($exception->getMessage() !== 'accepted') throw $exception;
            $accepted = true;
        }
        check($accepted === ($index === 5), 'Installed password handler CAP outcome: ' . $area);
    }
}
check($verificationCalls > 0, 'Valid and invalid tokens reached the actual verifier');
// A provider that returns a user without wp_authenticate_user must still pass the final guard.
$_POST = [];
Capconnect_Login_Proof::$valid = false;
check(apply_filters('authenticate', new WP_User(), 'adam-test', 'correct-password') instanceof WP_Error, 'Final authentication cannot bypass CAP');
$pagenow = 'index.php';
$apiUser = new WP_User();
check(apply_filters('authenticate', $apiUser, 'adam-test', 'application-password') === $apiUser, 'Noninteractive authentication providers remain unaffected');
$before = $verificationCalls;
$options['capconnect_fail_open'] = true;
check(!(new Capconnect_Verifier())->verify('') && $verificationCalls === $before, 'Fail-open never accepts a missing token or contacts the service');
echo "Passed {$checks} installed-handler assertions without WordPress bootstrap, database, HTTP, or mail.\n";
