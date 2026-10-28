<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Coordinates independent protection for WooCommerce's public Core forms. */
class Capconnect_WooCommerce
{
    public const OPTIONS = [
        'checkout' => 'capconnect_protect_woocommerce',
        'blocks' => 'capconnect_protect_woocommerce_blocks',
        'login' => 'capconnect_protect_woocommerce_login',
        'checkout_login' => 'capconnect_protect_woocommerce_checkout_login',
        'register' => 'capconnect_protect_woocommerce_register',
        'checkout_register' => 'capconnect_protect_woocommerce_checkout_register',
        'after_register' => 'capconnect_protect_woocommerce_after_register',
        'lost' => 'capconnect_protect_woocommerce_lostpassword',
        'reset' => 'capconnect_protect_woocommerce_resetpassword',
    ];

    private static bool $handlingLogin = false;
    private bool $loginVerified = false;
    private bool $assetsEnqueued = false;
    private Capconnect_Verifier $verifier;
    private Capconnect_Login_Proof $loginProof;

    /** Reuses the shared widget renderer and verification service. */
    public function __construct(private Capconnect_Widget $widget)
    {
        $this->verifier = new Capconnect_Verifier();
        $this->loginProof = new Capconnect_Login_Proof();
    }

    /** Enables WooCommerce protection only through its integration switch. */
    public static function isEnabled(): bool
    {
        return (bool) get_option('capconnect_woocommerce_enabled', false);
    }

    /** Returns the effective setting for one WooCommerce area. */
    public static function protects(string $area): bool
    {
        return self::isEnabled() && isset(self::OPTIONS[$area])
            && (bool) get_option(self::OPTIONS[$area], true);
    }

    /** Identifies authentication entered through WooCommerce's validated login handler. */
    public static function isHandlingLogin(): bool
    {
        return self::$handlingLogin;
    }

    /** Distinguishes shared password hooks by their actual caller, never by posted flags. */
    public static function isHandlingPassword(string $area): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            if ($area === 'lost' && ($frame['class'] ?? '') === 'WC_Shortcode_My_Account'
                && ($frame['function'] ?? '') === 'retrieve_password') {
                return true;
            }
            if ($area === 'reset' && ($frame['class'] ?? '') === 'WC_Form_Handler'
                && ($frame['function'] ?? '') === 'process_reset_password') {
                return true;
            }
        }
        return false;
    }

    /** Registers routing even when disabled so native WordPress settings stay independent. */
    public function init(): void
    {
        if (! class_exists('WooCommerce')) {
            return;
        }
        add_filter('woocommerce_process_login_errors', [$this, 'validateLogin'], 10, 3);
        add_filter('wp_authenticate_user', [$this, 'issueLoginProof'], 20, 2);
        add_action('wp_login', [$this, 'consumeLoginProof'], PHP_INT_MAX, 2);
        add_action('lostpassword_post', [$this, 'validateLostPassword']);
        add_action('validate_password_reset', [$this, 'validateResetPassword'], 10, 2);

        if (! self::isEnabled()) {
            return;
        }
        add_action('wp_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('woocommerce_login_form', [$this, 'renderLogin']);
        add_action('woocommerce_register_form', [$this, 'renderRegistration']);
        add_action('woocommerce_lostpassword_form', [$this, 'renderLostPassword']);
        add_action('woocommerce_resetpassword_form', [$this, 'renderResetPassword']);
        add_action('woocommerce_review_order_before_submit', [$this, 'renderCheckout']);
        add_action('woocommerce_checkout_process', [$this, 'validateCheckout']);
        add_filter('woocommerce_process_registration_errors', [$this, 'validateRegistration'], 10, 4);
        add_filter('woocommerce_registration_errors', [$this, 'validateAfterRegistration'], 10, 3);
        add_filter('render_block_woocommerce/checkout-actions-block', [$this, 'renderBlockCheckout']);
        add_filter('render_block_woocommerce/order-confirmation-create-account', [$this, 'renderAfterRegistration']);
        add_filter('rest_request_before_callbacks', [$this, 'validateStoreCheckout'], 10, 3);
        add_action('woocommerce_blocks_loaded', [$this, 'registerStoreSchema']);
        if (did_action('woocommerce_blocks_loaded')) {
            $this->registerStoreSchema();
        }
    }

    /** Registers transient extension data without saving tokens to orders or accounts. */
    public function registerStoreSchema(): void
    {
        if (! function_exists('woocommerce_store_api_register_endpoint_data')) {
            return;
        }
        woocommerce_store_api_register_endpoint_data([
            'endpoint' => 'checkout',
            'namespace' => 'capconnect',
            'schema_callback' => static function (): array {
                return ['token' => ['description' => 'Cap verification token', 'type' => 'string', 'context' => ['view', 'edit']]];
            },
            'schema_type' => ARRAY_A,
        ]);
    }

    /** Loads assets early on protected WooCommerce pages, including checkout data dependencies. */
    public function enqueueAssets(bool $force = false): void
    {
        if ($this->assetsEnqueued || (! $force && ! is_account_page() && ! is_checkout() && ! is_order_received_page())) {
            return;
        }
        $this->assetsEnqueued = true;
        $this->widget->enqueueAssets();
        wp_add_inline_style('capconnect-widget', $this->layoutStyles());
        wp_enqueue_script('capconnect-woocommerce', CAPCONNECT_PLUGIN_URL . 'assets/js/capconnect-woocommerce.js',
            ['capconnect-forms', 'wp-data', 'wp-api-fetch'], CAPCONNECT_VERSION, true);
        wp_add_inline_script('capconnect-woocommerce', 'window.CAPCONNECT_WC = ' . wp_json_encode([
            'mode' => get_option('capconnect_mode', 'widget'),
            'blocks' => ! is_user_logged_in() && self::protects('blocks'),
            'registration' => ! is_user_logged_in() && self::protects('checkout_register'),
            'registrationRequired' => WC()->checkout()->is_registration_required(),
        ]) . ';', 'before');
    }

    /** Keeps invisible WooCommerce wrappers out of layout until an error needs space. */
    private function layoutStyles(): string
    {
        return '[data-capconnect-wc][data-capconnect-mode] { position: relative !important; }'
            . '[data-capconnect-wc][data-capconnect-mode="programmatic"]:not(.capconnect-has-error) {'
            . 'position: absolute !important; width: 0 !important; height: 0 !important;'
            . 'min-width: 0 !important; min-height: 0 !important; margin: 0 !important;'
            . 'padding: 0 !important; border: 0 !important; overflow: hidden !important; }';
    }

    /** Builds form-local markup with explicit layout mode for visible and invisible widgets. */
    private function markup(string $area): string
    {
        $this->enqueueAssets(true);
        $mode = get_option('capconnect_mode', 'widget') === 'programmatic' ? 'programmatic' : 'widget';
        return '<div class="form-row cap-widget-wrapper" data-capconnect-wc="' . esc_attr($area)
            . '" data-capconnect-mode="' . esc_attr($mode) . '">'
            . $this->widget->renderForMode() . '</div>';
    }

    /** Renders a signed origin to distinguish the two Core login locations. */
    public function renderLogin(): void
    {
        $area = is_checkout() ? 'checkout_login' : 'login';
        // Include origin even for an unprotected form; only signed origins select a weaker policy.
        echo '<input type="hidden" name="capconnect-wc-login" value="' . esc_attr($area) . '" />';
        wp_nonce_field('capconnect_wc_' . $area, 'capconnect-wc-origin', false);
        if (self::protects($area)) {
            echo $this->markup($area); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Renders the My Account registration challenge. */
    public function renderRegistration(): void
    {
        if (self::protects('register')) {
            echo $this->markup('register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Renders the WooCommerce password recovery challenge. */
    public function renderLostPassword(): void
    {
        if (self::protects('lost')) {
            echo $this->markup('lost'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Renders the WooCommerce password reset challenge. */
    public function renderResetPassword(): void
    {
        if (self::protects('reset')) {
            echo $this->markup('reset'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Renders one checkout challenge; registration-only visibility follows the account checkbox. */
    public function renderCheckout(): void
    {
        if (! is_user_logged_in() && (self::protects('checkout') || self::protects('checkout_register'))) {
            echo $this->markup(self::protects('checkout') ? 'checkout' : 'checkout_register'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
    }

    /** Adds one challenge adjacent to the Block Checkout order button. */
    public function renderBlockCheckout(string $content): string
    {
        if (is_user_logged_in() || (! self::protects('blocks') && ! self::protects('checkout_register'))) {
            return $content;
        }
        return $this->markup('blocks') . $content;
    }

    /** Supplies inert markup for the client-rendered post-order registration form. */
    public function renderAfterRegistration(string $content): string
    {
        if (! self::protects('after_register') || is_user_logged_in() || $content === '') {
            return $content;
        }
        return $content . '<template id="capconnect-wc-after-register">' . $this->markup('after_register') . '</template>';
    }

    /** Checks a scalar posted token without accepting array-shaped input. */
    private function token(): string
    {
        $token = $_POST['cap-token'] ?? '';
        return is_string($token) ? sanitize_text_field(wp_unslash($token)) : '';
    }

    /** Returns the configured verification failure label. */
    private function message(): string
    {
        return (string) get_option('capconnect_verification_failed_label', '') ?: __('Captcha verification failed', 'capconnect-for-wp');
    }

    /** Adds verification failures to the original error collection. */
    private function validateErrors(\WP_Error $errors): \WP_Error
    {
        if (! $this->verifier->verify($this->token())) {
            $errors->add('capconnect_verification_failed', $this->message());
        }
        return $errors;
    }

    /** Resolves signed login origin; missing or malformed origins use the stricter policy. */
    public function requiresLoginToken(): bool
    {
        $area = $_POST['capconnect-wc-login'] ?? '';
        $nonce = $_POST['capconnect-wc-origin'] ?? '';
        if (is_string($area) && in_array($area, ['login', 'checkout_login'], true)
            && is_string($nonce) && wp_verify_nonce(wp_unslash($nonce), 'capconnect_wc_' . $area)) {
            return self::protects($area);
        }
        return self::protects('login') || self::protects('checkout_login');
    }

    /** Validates once before WooCommerce authenticates, independently of WordPress's toggle. */
    public function validateLogin(\WP_Error $errors, string $username, string $password): \WP_Error
    {
        self::$handlingLogin = true;
        $this->loginVerified = false;
        if (! $this->requiresLoginToken()) {
            return $errors;
        }
        $user = get_user_by(is_email($username) ? 'email' : 'login', $username);
        if ($user instanceof \WP_User && $this->loginProof->hasForUser($user)) {
            $this->loginVerified = true;
            return $errors;
        }
        $this->loginVerified = $this->verifier->verify($this->token());
        if (! $this->loginVerified) {
            $errors->add('capconnect_verification_failed', $this->message());
        }
        return $errors;
    }

    /** Binds a solved WooCommerce login to the authenticated user for existing 2FA support. */
    public function issueLoginProof($user, string $password)
    {
        if (self::$handlingLogin && $this->loginVerified && $user instanceof \WP_User) {
            $this->loginProof->issue($user);
        }
        return $user;
    }

    /** Clears transaction proofs after successful authentication. */
    public function consumeLoginProof(string $login, \WP_User $user): void
    {
        if (self::$handlingLogin && $this->loginProof->hasForUser($user)) {
            $this->loginProof->consume();
        }
    }

    /** Protects only My Account's registration handler, never every customer-creation call. */
    public function validateRegistration(\WP_Error $errors, string $username, string $password, string $email): \WP_Error
    {
        return self::protects('register') ? $this->validateErrors($errors) : $errors;
    }

    /** Restricts delayed registration protection to the actual Core block processing stack. */
    public function validateAfterRegistration(\WP_Error $errors, string $username, string $email): \WP_Error
    {
        if (! self::protects('after_register')) {
            return $errors;
        }
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            if (($frame['class'] ?? '') === 'Automattic\\WooCommerce\\Blocks\\BlockTypes\\OrderConfirmation\\CreateAccount'
                && ($frame['function'] ?? '') === 'process_form_post') {
                return $this->validateErrors($errors);
            }
        }
        return $errors;
    }

    /** Validates only recovery initiated by WooCommerce's Core handler. */
    public function validateLostPassword(\WP_Error $errors): void
    {
        if (self::protects('lost') && self::isHandlingPassword('lost')) {
            $this->validateErrors($errors);
        }
    }

    /** Requires a token even if an attacker removes the field from a WooCommerce reset. */
    public function validateResetPassword(\WP_Error $errors, $user): void
    {
        if (self::protects('reset') && self::isHandlingPassword('reset')) {
            $this->validateErrors($errors);
        }
    }

    /** Protects guest checkout and conditional registration with one verification. */
    public function validateCheckout(): void
    {
        if (is_user_logged_in()) {
            return;
        }
        $registration = ! empty($_POST['createaccount']) || WC()->checkout()->is_registration_required();
        if ((self::protects('checkout') || ($registration && self::protects('checkout_register')))
            && ! $this->verifier->verify($this->token())) {
            wc_add_notice($this->message(), 'error');
        }
    }

    /** Checks each REST subrequest before processing; unrelated routes and prior errors pass through. */
    public function validateStoreCheckout($response, array $handler, \WP_REST_Request $request)
    {
        if ($response !== null || $request->get_method() !== 'POST'
            || ! preg_match('#^/wc/store(?:/v[0-9]+)?/checkout/?$#D', $request->get_route())
            || is_user_logged_in()) {
            return $response;
        }
        $registration = rest_sanitize_boolean($request->get_param('create_account'))
            || (function_exists('WC') && WC()->checkout()->is_registration_required());
        if (! self::protects('blocks') && ! ($registration && self::protects('checkout_register'))) {
            return $response;
        }
        $extensions = $request->get_param('extensions');
        $token = is_array($extensions) && is_array($extensions['capconnect'] ?? null)
            ? ($extensions['capconnect']['token'] ?? '') : '';
        if (! is_string($token) || ! $this->verifier->verify($token)) {
            return new \WP_Error('capconnect_verification_failed', $this->message(), ['status' => 403]);
        }
        return $response;
    }
}
