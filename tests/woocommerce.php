<?php
/** Runs isolated WooCommerce integration tests without WordPress, SQL, network, or disk-backed options. */
declare(strict_types=1);

namespace Automattic\WooCommerce\Blocks\BlockTypes\OrderConfirmation {
    class CreateAccount
    {
        /** Simulates Core's delayed-registration call path. */
        public function process_form_post($integration, $errors)
        {
            return $integration->validateAfterRegistration($errors, 'customer', 'customer@example.test');
        }
    }
}

namespace {
    if (PHP_SAPI !== 'cli') {
        http_response_code(404);
        exit;
    }
    define('ABSPATH', __DIR__);
    define('CAPCONNECT_PLUGIN_URL', 'https://example.test/plugin/');
    define('CAPCONNECT_VERSION', 'test');
    $options = [];
    $registered = [];
    $fields = [];
    $hooks = [];
    $notices = [];
    $loggedIn = false;
    $registrationRequired = false;
    $assertions = 0;
    $canManage = true;
    $currentUserId = 1;
    $currentBlogId = 1;
    $userMeta = [];

    if (!in_array('--inactive-settings-html', $argv, true) && !in_array('--inactive-notice', $argv, true)) {
        class WooCommerce {}
    }
    class WP_User { public int $ID = 1; }
    class WP_Error
    {
        public array $errors = [];
        /** Stores a simulated WordPress error. */
        public function __construct(string $code = '', string $message = '', $data = null) { if ($code) $this->add($code, $message); }
        /** Adds an error without replacing earlier errors. */
        public function add(string $code, string $message): void { $this->errors[$code] = $message; }
        /** Returns the first error code. */
        public function get_error_code(): string { return array_key_first($this->errors) ?? ''; }
    }
    class WP_REST_Request
    {
        /** Stores an isolated REST request. */
        public function __construct(private string $method, private string $route, private array $data = []) {}
        /** Returns the request method. */
        public function get_method(): string { return $this->method; }
        /** Returns the request route. */
        public function get_route(): string { return $this->route; }
        /** Returns one request parameter. */
        public function get_param(string $key) { return $this->data[$key] ?? null; }
    }
    class Capconnect_Verifier
    {
        public static int $calls = 0;
        public static array $used = [];
        /** Models expiring, single-use Cap tokens deterministically without HTTP calls. */
        public function verify(string $token): bool
        {
            self::$calls++;
            if (! str_starts_with($token, 'valid-') || isset(self::$used[$token])) return false;
            self::$used[$token] = true;
            return true;
        }
    }
    class Capconnect_Login_Proof
    {
        public static bool $valid = false;
        /** Models a signed login proof. */
        public function hasForUser($user): bool { return self::$valid; }
        /** Marks successful authentication. */
        public function issue($user): void { self::$valid = true; }
        /** Consumes the proof. */
        public function consume(): void { self::$valid = false; }
    }
    class Capconnect_Login_Recovery
    {
        /** Keeps the test's native login protection enabled. */
        public function isLoginCaptchaDisabled(): bool { return false; }
        /** Models an inactive emergency recovery session. */
        public function consumeForUser($user): bool { return false; }
        /** Accepts failure reporting without persistence. */
        public function markCaptchaValidationFailed(): void {}
    }
    class WC_Shortcode_My_Account
    {
        /** Simulates both callbacks on Core's shared lost-password hook. */
        public static function retrieve_password($woo, $native, $errors): void
        {
            $native->validateLostPasswordToken($errors);
            $woo->validateLostPassword($errors);
        }
    }
    class WC_Form_Handler
    {
        /** Simulates both callbacks on Core's shared reset hook. */
        public static function process_reset_password($woo, $native, $errors): void
        {
            $native->validateResetPasswordToken($errors, new WP_User());
            $woo->validateResetPassword($errors, new WP_User());
        }
    }
    /** Reads memory-only options, respecting explicit defaults like WordPress. */
    function get_option($key, $default = false) { global $options, $registered; return $options[$key] ?? (func_num_args() > 1 ? $default : ($registered[$key]['default'] ?? $default)); }
    /** Records registered settings without touching a database. */
    function register_setting($group, $key, $args): void { $GLOBALS['registered'][$key] = $args; }
    /** Records settings sections for UI assertions. */
    function add_settings_section($id, $title, $callback, $page): void { $GLOBALS['wp_settings_sections'][$page][$id] = compact('title', 'callback'); }
    /** Records fields for UI assertions. */
    function add_settings_field($id, $title, $callback, $page, $section, $args = []): void { $GLOBALS['fields'][$id] = compact('title', 'callback', 'page', 'section', 'args'); $GLOBALS['wp_settings_fields'][$page][$section][$id] = true; }
    /** Records hook registration. */
    function add_filter($name, $callback, $priority = 10, $args = 1): void { $GLOBALS['hooks'][$name][] = $callback; }
    /** Records action registration. */
    function add_action(...$args): void { add_filter(...$args); }
    /** Simulates a hook that has not fired. */
    function did_action($name): int { return 0; }
    /** Formats checkbox state without persistent settings. */
    function checked($value, $expected = true, $echo = true): string { $html = $value === $expected ? ' checked="checked"' : ''; if ($echo) echo $html; return $html; }
    /** Returns translations unchanged in this fixture. */
    function __($text, $domain = ''): string { return $text; }
    /** Escapes HTML in UI fixtures. */
    function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES); }
    /** Escapes attributes in UI fixtures. */
    function esc_attr($text): string { return esc_html($text); }
    /** Returns an escaped translation. */
    function esc_html__($text, $domain = ''): string { return esc_html($text); }
    /** Renders an escaped translation. */
    function esc_html_e($text, $domain = ''): void { echo esc_html($text); }
    /** Supplies deterministic valid origins. */
    function wp_verify_nonce($nonce, $action): bool { return $nonce === 'signed:' . $action; }
    /** Removes escaping from fixture strings. */
    function wp_unslash($text): string { return stripslashes($text); }
    /** Sanitizes fixture text. */
    function sanitize_text_field($text): string { return trim(strip_tags($text)); }
    /** Identifies email usernames. */
    function is_email($text): bool { return str_contains($text, '@'); }
    /** Supplies one isolated user. */
    function get_user_by($field, $value): WP_User { return new WP_User(); }
    /** Reads isolated authentication state. */
    function is_user_logged_in(): bool { return $GLOBALS['loggedIn']; }
    /** Identifies fixture errors. */
    function is_wp_error($value): bool { return $value instanceof WP_Error; }
    /** Keeps native login outside AJAX preflight. */
    function wp_doing_ajax(): bool { return false; }
    /** Models REST boolean sanitation. */
    function rest_sanitize_boolean($value): bool { return !in_array($value, [null, false, 0, '0', 'false', ''], true); }
    /** Stores notices in memory. */
    function wc_add_notice($message, $type): void { $GLOBALS['notices'][] = $message; }
    /** Supplies WooCommerce checkout behavior without any persistent state. */
    function WC(): object { return new class {
        /** Returns the isolated checkout object. */
        public function checkout(): object { return $this; }
        /** Reads required registration from the fixture. */
        public function is_registration_required(): bool { return $GLOBALS['registrationRequired']; }
    }; }
    /** Asserts a behavior and counts successful checks. */
    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
        $GLOBALS['assertions']++;
    }
    /** Starts a fresh memory-only request configuration. */
    function configure(array $enabled = []): void
    {
        $GLOBALS['options'] = ['capconnect_woocommerce_enabled' => true];
        foreach (Capconnect_WooCommerce::OPTIONS as $area => $option) $GLOBALS['options'][$option] = in_array($area, $enabled, true);
        $_POST = [];
        $GLOBALS['notices'] = [];
        $GLOBALS['loggedIn'] = false;
        $GLOBALS['registrationRequired'] = false;
        Capconnect_Verifier::$calls = 0;
        Capconnect_Login_Proof::$valid = false;
    }

    /** Supplies a locale for the rendered settings fixture. */
    function get_locale(): string { return 'en_US'; }
    /** Returns unchanged fixture filter values. */
    function apply_filters($name, $value) { return $value; }
    /** Reads administrator permissions from the isolated fixture. */
    function current_user_can($capability): bool { return $GLOBALS['canManage']; }
    /** Supplies a test administrator. */
    function get_current_user_id(): int { return $GLOBALS['currentUserId']; }
    /** Supplies a test site. */
    function get_current_blog_id(): int { return $GLOBALS['currentBlogId']; }
    /** Reads in-memory user preferences. */
    function get_user_meta($id, $key, $single = false) { return $GLOBALS['userMeta'][$id][$key] ?? ''; }
    /** Saves a user preference only in the test fixture. */
    function update_user_meta($id, $key, $value): void { $GLOBALS['userMeta'][$id][$key] = $value; }
    /** Supplies a local admin URL. */
    function admin_url($path = ''): string { return 'https://example.test/wp-admin/' . $path; }
    /** Escapes fixture URLs. */
    function esc_url($url): string { return esc_attr($url); }
    /** Rejects a dismissal without its nonce. */
    function check_admin_referer($action): void { if (($_POST['_wpnonce'] ?? '') !== 'signed:' . $action) throw new \RuntimeException('Invalid nonce'); }
    /** Models WordPress terminating an unauthorized request. */
    function wp_die($message, $title = '', $args = []): void { throw new \RuntimeException('Unauthorized'); }
    /** Supplies a safe referring admin page. */
    function wp_get_referer(): string { return admin_url('index.php'); }
    /** Records the redirect while stopping the fixture before exit. */
    function wp_safe_redirect($url): void { throw new \RuntimeException('Redirect: ' . $url); }
    /** Supplies the settings page heading. */
    function get_admin_page_title(): string { return 'CapConnect'; }
    /** Prints a simulated Settings API nonce. */
    function settings_fields($group): void { echo '<input type="hidden" name="_wpnonce" value="settings" />'; }
    /** Supplies deterministic UI nonces. */
    function wp_create_nonce($action): string { return 'signed:' . $action; }
    /** Prints a simulated nonce field. */
    function wp_nonce_field($action, $name = '_wpnonce', $referer = true): void { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '" />'; }
    /** Supplies JSON for inline scripts. */
    function wp_json_encode($value): string { return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); }
    /** Escapes script strings in this fixture. */
    function esc_js($text): string { return addslashes($text); }
    /** Renders a submit button for the settings fixture. */
    function submit_button($label, $type, $name, $wrap): void { echo '<button type="submit">' . esc_html($label) . '</button>'; }
    /** Renders real checkbox controls while replacing unrelated credential controls with fixtures. */
    function do_settings_fields($page, $section): void
    {
        foreach ($GLOBALS['fields'] as $id => $field) {
            if ($field['section'] !== $section) continue;
            echo '<tr><th>' . esc_html($field['title']) . '</th><td>';
            if (isset($field['args']['field']) && str_contains($field['args']['field'], 'protect_') || $id === 'capconnect_woocommerce_enabled') {
                ($field['callback'])($field['args']);
            } elseif (in_array($id, ['capconnect_instance_url', 'capconnect_site_key', 'capconnect_secret'], true)) {
                echo '<input name="' . esc_attr($id) . '" value="" />';
            }
            echo '</td></tr>';
        }
    }

    require __DIR__ . '/../includes/class-cap-settings.php';
    require __DIR__ . '/../includes/class-cap-widget.php';
    require __DIR__ . '/../includes/class-cap-woocommerce.php';
    require __DIR__ . '/../includes/class-cap-integrations.php';
    if (in_array('--inactive-notice', $argv, true)) {
        (new Capconnect_Settings())->displayWooCommerceInactiveNotice();
        exit;
    }
    if (in_array('--settings-html', $argv, true) || in_array('--inactive-settings-html', $argv, true)) {
        if (in_array('--inactive-settings-html', $argv, true)) $options = ['capconnect_woocommerce_enabled' => true, 'capconnect_protect_woocommerce_blocks' => true];
        $settings = new Capconnect_Settings();
        $settings->registerSettings();
        $settings->renderPage();
        exit;
    }
    if (in_array('--layout-fixture', $argv, true)) {
        $layout = new class(new Capconnect_Widget()) extends Capconnect_WooCommerce {
            /** Skips external asset registration for isolated markup generation. */
            public function enqueueAssets(bool $force = false): void {}
        };
        $styles = new \ReflectionMethod(Capconnect_WooCommerce::class, 'layoutStyles');
        $markup = new \ReflectionMethod(Capconnect_WooCommerce::class, 'markup');
        $fixture = ['css' => $styles->invoke($layout)];
        foreach (['programmatic', 'widget'] as $mode) {
            $options['capconnect_mode'] = $mode;
            foreach (['login', 'checkout', 'blocks', 'after_register'] as $area) {
                $fixture[$mode][$area] = $markup->invoke($layout, $area);
            }
        }
        echo json_encode($fixture);
        exit;
    }
    $woo = new Capconnect_WooCommerce(new Capconnect_Widget());
    $native = new Capconnect_Integrations();
    $settings = new Capconnect_Settings();
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    $noticeHtml = ob_get_clean();
    check(str_contains($noticeHtml, 'WooCommerce is active') && str_contains($noticeHtml, 'capconnect_dismiss_woocommerce_notice') && str_contains($noticeHtml, 'signed:capconnect_dismiss_woocommerce_notice'), 'Unprotected WooCommerce displays a dismissible, nonce-protected admin notice');
    $options['capconnect_woocommerce_enabled'] = true;
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    check(ob_get_clean() === '', 'Enabled WooCommerce does not display the notice');
    $options['capconnect_woocommerce_enabled'] = false;
    $canManage = false;
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    check(ob_get_clean() === '', 'Non-administrators do not see the notice');
    try { $settings->dismissWooCommerceInactiveNotice(); } catch (\RuntimeException $exception) { check($exception->getMessage() === 'Unauthorized', 'Dismissal requires permission'); }
    $canManage = true;
    $_POST = [];
    try { $settings->dismissWooCommerceInactiveNotice(); } catch (\RuntimeException $exception) { check($exception->getMessage() === 'Invalid nonce', 'Dismissal requires a nonce'); }
    check($userMeta === [], 'Unauthorized or invalid dismissal does not change preferences');
    $_POST['_wpnonce'] = 'signed:capconnect_dismiss_woocommerce_notice';
    try { $settings->dismissWooCommerceInactiveNotice(); } catch (\RuntimeException $exception) { check(str_starts_with($exception->getMessage(), 'Redirect: '), 'Dismissal returns to the admin page'); }
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    check(ob_get_clean() === '', 'Dismissal remains hidden for this administrator');
    $currentUserId = 2;
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    check(str_contains(ob_get_clean(), 'WooCommerce is active'), 'Dismissal does not hide notices from other administrators');
    $currentUserId = 1;
    $currentBlogId = 2;
    ob_start();
    $settings->displayWooCommerceInactiveNotice();
    check(str_contains(ob_get_clean(), 'WooCommerce is active'), 'Dismissal is scoped to its site');
    $currentBlogId = 1;
    $options = [];
    $_POST = [];
    check(!Capconnect_WooCommerce::isEnabled(), 'New installation defaults off');
    $options['capconnect_protect_woocommerce'] = true;
    foreach (array_keys(Capconnect_WooCommerce::OPTIONS) as $area) check(!Capconnect_WooCommerce::protects($area), 'Master switch required: ' . $area);
    $options = ['capconnect_woocommerce_enabled' => true];
    foreach (array_keys(Capconnect_WooCommerce::OPTIONS) as $area) check(Capconnect_WooCommerce::protects($area), 'Enabled by default: ' . $area);
    $options['capconnect_protect_woocommerce_blocks'] = false;
    check(!Capconnect_WooCommerce::protects('blocks') && Capconnect_WooCommerce::protects('checkout'), 'Individual settings override defaults');
    $options['capconnect_woocommerce_enabled'] = false;
    check(!Capconnect_WooCommerce::protects('checkout'), 'Master switch overrides enabled areas');
    $options['capconnect_woocommerce_enabled'] = true;
    check(!Capconnect_WooCommerce::protects('blocks') && Capconnect_WooCommerce::protects('checkout'), 'Individual settings survive master toggle');
    foreach (array_keys(Capconnect_WooCommerce::OPTIONS) as $area) {
        configure([$area]);
        foreach (array_keys(Capconnect_WooCommerce::OPTIONS) as $other) check(Capconnect_WooCommerce::protects($other) === ($other === $area), 'Independent area: ' . $area);
    }
    configure();
    (new Capconnect_Settings())->registerSettings();
    check(count(array_filter($fields, fn($field) => $field['section'] === 'capconnect_woocommerce_section')) === 9, 'Exactly nine dedicated WooCommerce settings');
    check($fields['capconnect_woocommerce_enabled']['section'] === 'capconnect_forms_section', 'Master remains in Integrations');
    check($registered['capconnect_woocommerce_enabled']['default'] === false, 'Integration defaults off');
    foreach (Capconnect_WooCommerce::OPTIONS as $area => $option) check($registered[$option]['default'] === true, 'Area defaults on: ' . $area);
    ob_start();
    (new Capconnect_Settings())->renderCheckboxField(['field' => Capconnect_WooCommerce::OPTIONS['blocks'], 'label' => 'Blocks', 'default' => false]);
    $html = ob_get_clean();
    check(str_contains($html, 'value="0"') && !str_contains($html, 'checked='), 'Explicit unchecked values and correct false defaults');

    configure();
    check($native->validateLoginToken(new WP_User(), 'password') instanceof WP_Error, 'Native login still rejects a missing token');
    $_POST['cap-token'] = 'valid-native';
    check($native->validateLoginToken(new WP_User(), 'password') instanceof WP_User, 'Native login accepts a valid token');
    Capconnect_Login_Proof::$valid = false;
    configure();
    $_POST = ['wc_reset_password' => 'true', 'woocommerce-lost-password-nonce' => 'forged'];
    $errors = new WP_Error();
    $native->validateLostPasswordToken($errors);
    check($errors->get_error_code() !== '', 'Posted WooCommerce flags cannot bypass native password protection');

    foreach (['login', 'checkout_login'] as $area) {
        configure([$area]);
        check($woo->requiresLoginToken(), 'Missing login origin cannot bypass ' . $area);
        $_POST = ['capconnect-wc-login' => $area, 'capconnect-wc-origin' => 'forged'];
        check($woo->requiresLoginToken(), 'Forged login origin cannot bypass ' . $area);
        $other = $area === 'login' ? 'checkout_login' : 'login';
        $_POST = ['capconnect-wc-login' => $other, 'capconnect-wc-origin' => 'signed:capconnect_wc_' . $other];
        check(!$woo->requiresLoginToken(), 'Valid separately disabled origin stays disabled');
        $_POST = [];
        check($woo->validateLogin(new WP_Error(), 'customer', 'password')->get_error_code() !== '', 'Login rejects missing tokens');
        $_POST['cap-token'] = 'valid-login-' . $area;
        check($woo->validateLogin(new WP_Error(), 'customer', 'password')->get_error_code() === '', 'Login accepts fresh token');
        $calls = Capconnect_Verifier::$calls;
        check($native->validateLoginToken(new WP_User(), 'password') instanceof WP_User && $calls === Capconnect_Verifier::$calls, 'Native login must not verify Woo token again');
        $woo->issueLoginProof(new WP_User(), 'password');
        check(Capconnect_Login_Proof::$valid, 'Woo login receives existing transaction proof');
        $woo->consumeLoginProof('customer', new WP_User());
        check(!Capconnect_Login_Proof::$valid, 'Successful login consumes proof');
    }
    foreach (['lost', 'reset'] as $area) {
        foreach ([false, true] as $enabled) {
            configure($enabled ? [$area] : []);
            $errors = new WP_Error();
            if ($area === 'lost') WC_Shortcode_My_Account::retrieve_password($woo, $native, $errors);
            else WC_Form_Handler::process_reset_password($woo, $native, $errors);
            check(($errors->get_error_code() !== '') === $enabled, 'Password switch independent of native: ' . $area);
            check(Capconnect_Verifier::$calls === (int)$enabled, 'Exactly one password validation: ' . $area);
        }
        configure([$area]);
        $_POST['cap-token'] = 'valid-password-' . $area;
        $errors = new WP_Error();
        if ($area === 'lost') WC_Shortcode_My_Account::retrieve_password($woo, $native, $errors);
        else WC_Form_Handler::process_reset_password($woo, $native, $errors);
        check(!$errors->get_error_code() && Capconnect_Verifier::$calls === 1, 'Valid password flow verifies once');
    }
    configure(['register']);
    check($woo->validateRegistration(new WP_Error(), '', '', 'customer@example.test')->get_error_code() !== '', 'Account registration blocks missing token');
    $_POST['cap-token'] = 'valid-register';
    check(!$woo->validateRegistration(new WP_Error(), '', '', 'customer@example.test')->get_error_code(), 'Account registration accepts fresh token');
    check($woo->validateRegistration(new WP_Error(), '', '', 'customer@example.test')->get_error_code() !== '', 'Account registration rejects replay');
    configure(['after_register']);
    check(!$woo->validateAfterRegistration(new WP_Error(), '', 'customer@example.test')->get_error_code(), 'Backend/API customer creation remains unaffected');
    $block = new \Automattic\WooCommerce\Blocks\BlockTypes\OrderConfirmation\CreateAccount();
    check($block->process_form_post($woo, new WP_Error())->get_error_code() !== '', 'Delayed registration blocks missing token');
    $_POST['cap-token'] = 'valid-delayed';
    check(!$block->process_form_post($woo, new WP_Error())->get_error_code(), 'Delayed registration accepts fresh token');

    foreach ([['checkout'], ['checkout_register'], ['checkout', 'checkout_register']] as $enabled) {
        configure($enabled);
        $_POST = ['createaccount' => '1', 'cap-token' => 'valid-classic-' . implode('-', $enabled)];
        $woo->validateCheckout();
        check(!$notices && Capconnect_Verifier::$calls === 1, 'Classic checkout and registration share one verification');
        $woo->validateCheckout();
        check(count($notices) === 1, 'Classic checkout blocks replay');
        $loggedIn = true;
        $calls = Capconnect_Verifier::$calls;
        $woo->validateCheckout();
        check(Capconnect_Verifier::$calls === $calls, 'Logged-in checkout is exempt');
    }
    configure(['checkout_register']);
    $woo->validateCheckout();
    check(Capconnect_Verifier::$calls === 0, 'Guest order without account needs no registration challenge');
    $registrationRequired = true;
    $woo->validateCheckout();
    check(count($notices) === 1, 'Required account creation cannot bypass by omitting checkbox');

    configure(['blocks', 'checkout_register']);
    foreach (['', 'invalid', 'expired', ['bad']] as $token) {
        $result = $woo->validateStoreCheckout(null, [], new WP_REST_Request('POST', '/wc/store/v1/checkout', ['extensions' => ['capconnect' => ['token' => $token]]]));
        check($result instanceof WP_Error, 'Store API rejects missing, invalid, expired or array tokens');
    }
    $request = new WP_REST_Request('POST', '/wc/store/v1/checkout', ['create_account' => true, 'extensions' => ['capconnect' => ['token' => 'valid-rest']]]);
    $calls = Capconnect_Verifier::$calls;
    check($woo->validateStoreCheckout(null, [], $request) === null && Capconnect_Verifier::$calls === $calls + 1, 'Block checkout verifies exactly once');
    check($woo->validateStoreCheckout(null, [], $request) instanceof WP_Error, 'Second batch operation cannot reuse token');
    $previous = new WP_Error('existing', 'Existing failure');
    check($woo->validateStoreCheckout($previous, [], $request) === $previous, 'Prior REST failures preserved');
    foreach ([['GET', '/wc/store/v1/checkout'], ['POST', '/wc/store/v1/cart'], ['POST', '/wc/store/v1/checkout-other'], ['POST', '/wc/v3/customers']] as [$method, $route]) {
        check($woo->validateStoreCheckout(null, [], new WP_REST_Request($method, $route)) === null, 'Unrelated request unaffected: ' . $route);
    }
    configure(['checkout_register']);
    check($woo->validateStoreCheckout(null, [], new WP_REST_Request('POST', '/wc/store/v1/checkout', ['create_account' => false])) === null, 'Block guest order without registration is unaffected');
    check($woo->validateStoreCheckout(null, [], new WP_REST_Request('POST', '/wc/store/v1/checkout', ['create_account' => true])) instanceof WP_Error, 'Independent block account creation is protected');
    $options['capconnect_woocommerce_enabled'] = false;
    check($woo->validateStoreCheckout(null, [], $request) === null, 'Master off disables Store API protection');
    configure(['blocks']);
    $hooks = [];
    $woo->init();
    check(isset($hooks['rest_request_before_callbacks'], $hooks['render_block_woocommerce/checkout-actions-block']), 'Enabled integration registers server and Block Checkout hooks');
    $options['capconnect_woocommerce_enabled'] = false;
    $hooks = [];
    $woo->init();
    check(!isset($hooks['rest_request_before_callbacks'], $hooks['woocommerce_checkout_process']), 'Disabled integration does not register checkout enforcement');
    check(isset($hooks['woocommerce_process_login_errors']), 'Disabled integration still isolates native authentication routing');
    echo "Passed {$assertions} isolated PHP assertions.\n";
}
