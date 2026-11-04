<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Capconnect_Integrations
{
    private Capconnect_Verifier $verifier;
    private Capconnect_Widget $widget;
    private Capconnect_Login_Recovery $loginRecovery;
    private Capconnect_Login_Proof $loginProof;

    /**
     * Creates the form integrations and their login recovery dependency.
     */
    public function __construct(?Capconnect_Login_Recovery $loginRecovery = null)
    {
        $this->verifier      = new Capconnect_Verifier();
        $this->widget        = new Capconnect_Widget();
        $this->loginRecovery = $loginRecovery ?? new Capconnect_Login_Recovery();
        $this->loginProof    = new Capconnect_Login_Proof();
    }

    /** Initializes configured native forms and the dedicated WooCommerce integration. */
    public function init(): void
    {
        if (! Capconnect_Settings::isConfigured()) {
            return;
        }

        $this->initCommentIntegration();
        $this->initLoginIntegration();
        $this->initRegisterIntegration();
        $this->initLostPasswordIntegration();
        (new Capconnect_WooCommerce($this->widget))->init();
        $this->initGravityFormsIntegration();
        $this->initForminatorIntegration();

        add_action('login_enqueue_scripts', [$this, 'enqueueNativeFormAssets']);
    }

    /** Protects comments from both visitors and signed-in users. */
    private function initCommentIntegration(): void
    {
        if (! get_option('capconnect_protect_comments', true)) {
            return;
        }
        add_action('comment_form_after_fields', [$this, 'renderWidgetComment']);
        add_action('comment_form_logged_in_after', [$this, 'renderWidgetComment']);
        add_filter('preprocess_comment', [$this, 'validateCommentToken']);
    }

    /**
     * Registers login protection unless the emergency bypass is active.
     */
    private function initLoginIntegration(): void
    {
        if (! get_option('capconnect_protect_login', true) || $this->loginRecovery->isLoginCaptchaDisabled()) {
            return;
        }
        add_action('login_form', [$this, 'renderWidgetLogin']);
        add_filter('wp_authenticate_user', [$this, 'validateLoginToken'], 10, 2);
        add_filter('authenticate', [$this, 'validateFinalLoginToken'], PHP_INT_MAX, 3);
        add_action('wp_login', [$this, 'consumeLoginProof'], PHP_INT_MAX, 2);
        add_action('login_form_validate_2fa', [$this, 'validateTwoFactorLoginProof'], 1);
        add_action('two_factor_user_authenticated', [$this, 'consumeTwoFactorLoginProof'], 10, 2);
    }

    private function initRegisterIntegration(): void
    {
        if (! get_option('capconnect_protect_register', true)) {
            return;
        }
        add_action('register_form', [$this, 'renderWidgetRegister']);
        add_filter('registration_errors', [$this, 'validateRegisterToken'], 10, 3);
    }

    private function initLostPasswordIntegration(): void
    {
        if (! get_option('capconnect_protect_lostpassword', true)) {
            return;
        }
        add_action('lostpassword_form', [$this, 'renderWidgetLostPassword']);
        add_action('lostpassword_post', [$this, 'validateLostPasswordToken'], 10, 1);

        add_action('resetpass_form', [$this, 'renderWidgetResetPassword']);
        add_action('validate_password_reset', [$this, 'validateResetPasswordToken'], 10, 2);
    }

    private function initGravityFormsIntegration(): void
    {
        if (! get_option('capconnect_protect_gravityforms', true)) {
            return;
        }

        if (! $this->isGravityFormsActive()) {
            return;
        }

        add_filter('gform_submit_button', [$this, 'renderWidgetGravityForms'], 10, 2);
        add_filter('gform_validation', [$this, 'validateGravityFormsToken'], 10, 2);
    }

    private function initForminatorIntegration(): void
    {
        if (! get_option('capconnect_protect_forminator', true)) {
            return;
        }

        if (! $this->isForminatorActive()) {
            return;
        }

        add_filter('forminator_render_button_markup', [$this, 'renderWidgetForminator'], 10, 2);
        add_filter('forminator_pagination_submit_markup', [$this, 'renderWidgetForminatorPagination']);
        add_filter('forminator_custom_form_submit_errors', [$this, 'validateForminatorToken'], 10, 3);
    }

    /**
     * Loads widget assets before WordPress prints native form page styles.
     */
    public function enqueueNativeFormAssets(): void
    {
        $action = isset($_REQUEST['action'])
            ? sanitize_key(wp_unslash($_REQUEST['action']))
            : 'login';

        $isProtectedForm = match ($action) {
            'register' => (bool) get_option('capconnect_protect_register', true),
            'lostpassword', 'retrievepassword', 'rp', 'resetpass' => (bool) get_option('capconnect_protect_lostpassword', true),
            'login' => (bool) get_option('capconnect_protect_login', true)
                && ! $this->loginRecovery->isLoginCaptchaDisabled(),
            default => false,
        };

        if ($isProtectedForm) {
            $this->widget->enqueueAssets();
        }
    }

    /** Renders the challenge in the current comment form. */
    public function renderWidgetComment(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /**
     * Renders the login widget unless a validated recovery session replaces it.
     */
    public function renderWidgetLogin(): void
    {
        if ($this->loginRecovery->hasActiveSession()) {
            return;
        }

        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetRegister(): void
    {
        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetLostPassword(): void
    {
        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetResetPassword(): void
    {
        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** Rejects unverified comments before WordPress stores them. */
    public function validateCommentToken(array $commentData): array
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wp_die(
                esc_html($this->getVerificationFailedMessage()),
                esc_html__('Cap Verification Failed', 'capconnect-for-wp'),
                ['response' => 403, 'back_link' => true]
            );
        }

        return $commentData;
    }

    /**
     * Validates native logins or reuses their proof; WooCommerce verifies its own transaction.
     */
    public function validateLoginToken(\WP_User|\WP_Error $user, string $password): \WP_User|\WP_Error
    {
        if (Capconnect_WooCommerce::isHandlingLogin() || is_wp_error($user)) {
            return $user;
        }

        if ($this->isWordfenceAuthenticationPreflight()) {
            return $user;
        }

        if ($this->loginProof->hasForUser($user)) {
            return $user;
        }

        if ($this->loginRecovery->consumeForUser($user)) {
            $this->loginProof->issue($user);
            return $user;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $this->loginRecovery->markCaptchaValidationFailed();
            return new \WP_Error(
                'capconnect_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }

        $this->loginProof->issue($user);

        return $user;
    }

    /** Guards the native login after other providers without affecting application-password/API authentication. */
    public function validateFinalLoginToken($user, string $username, string $password)
    {
        if (($GLOBALS['pagenow'] ?? '') !== 'wp-login.php') {
            return $user;
        }
        return $user instanceof \WP_User ? $this->validateLoginToken($user, $password) : $user;
    }

    /**
     * Requires the initial captcha proof before Two-Factor validates its second step.
     */
    public function validateTwoFactorLoginProof(): void
    {
        $userId = isset($_REQUEST['wp-auth-id']) ? absint($_REQUEST['wp-auth-id']) : 0;
        $user = $userId > 0 ? get_user_by('id', $userId) : false;

        if ($user instanceof \WP_User && $this->loginProof->hasForUser($user)) {
            return;
        }

        wp_die(
            esc_html($this->getVerificationFailedMessage()),
            esc_html__('Cap Verification Failed', 'capconnect-for-wp'),
            ['response' => 403, 'back_link' => true]
        );
    }

    /**
     * Consumes the proof after WordPress completes a non-interstitial login.
     */
    public function consumeLoginProof(string $userLogin, \WP_User $user): void
    {
        if ($this->loginProof->hasForUser($user)) {
            $this->loginProof->consume();
        }
    }

    /**
     * Consumes the proof after the official Two-Factor plugin completes authentication.
     */
    public function consumeTwoFactorLoginProof(\WP_User $user, $provider): void
    {
        if ($this->loginProof->hasForUser($user)) {
            $this->loginProof->consume();
        }
    }

    /** Adds CAP failures to native registration errors. */
    public function validateRegisterToken(\WP_Error $errors, string $sanitizedUserLogin, string $userEmail): \WP_Error
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'capconnect_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }

        return $errors;
    }

    /**
     * Validates native password requests independently of WooCommerce settings.
     */
    public function validateLostPasswordToken(\WP_Error $errors): void
    {
        if (Capconnect_WooCommerce::isHandlingPassword('lost')) {
            return;
        }
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'capconnect_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }
    }

    /**
     * Validates native resets without consuming WooCommerce tokens twice.
     */
    public function validateResetPasswordToken(\WP_Error $errors, $user): void
    {
        if (Capconnect_WooCommerce::isHandlingPassword('reset')) {
            return;
        }
        // Core also fires this hook while initially displaying the reset form.
        if (! isset($_POST['pass1'])) {
            return;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'capconnect_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }
    }

    /** Marks the challenge for its adapter and preserves CAP styles against Gravity Forms' theme reset. */
    public function renderWidgetGravityForms(string $button, array $form): string
    {
        $this->widget->enqueueAssets();

        $html = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $html = '<div class="cap-widget-wrapper">' . $html . '</div>';
            $html .= '<div class="capconnect-error-message" role="alert" style="display: none;"></div>';
        }

        return '<div class="gform-theme__no-reset--children" data-capconnect-integration="gravityforms">' . $html . '</div>' . $button;
    }

    /** Verifies final Gravity Forms submissions while preserving intermediate page validation. */
    public function validateGravityFormsToken(array $validationResult, string $context = ''): array
    {
        if ($context === 'form-submit' && class_exists('GFFormDisplay')
            && ! GFFormDisplay::is_last_page($validationResult['form'])) {
            return $validationResult;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $validationResult['is_valid'] = false;

            $formId = (int) $validationResult['form']['id'];
            add_filter(
                "gform_validation_message_{$formId}",
                fn(string $message): string => '<div class="validation_error capconnect-error-message gform-theme__no-reset--el" role="alert">'
                    . esc_html($this->getVerificationFailedMessage())
                    . '</div>'
            );
        }

        return $validationResult;
    }

    /**
     * Renders the Cap field in Forminator's field structure.
     */
    public function renderWidgetForminator(string $html, $button = null): string
    {
        if (strpos($html, 'forminator-button-submit') === false) {
            return $html;
        }

        return $this->renderForminatorChallenge() . $html;
    }

    /** Adds the challenge to Forminator's final pagination page. */
    public function renderWidgetForminatorPagination(string $html): string
    {
        if (strpos($html, 'forminator-pagination-submit') === false) {
            return $html;
        }

        return $this->renderForminatorChallenge() . $html;
    }

    /** Builds the shared challenge markup for Forminator forms. */
    private function renderForminatorChallenge(): string
    {
        $this->widget->enqueueAssets();

        $widgetHtml = $this->widget->renderForMode();
        if (get_option('capconnect_mode', 'widget') !== 'programmatic') {
            $widgetHtml = '<div class="cap-widget-wrapper">' . $widgetHtml . '</div>';
        }

        return '<div class="forminator-row cap-widget-row" data-capconnect-integration="forminator"><div class="forminator-col forminator-col-12"><div class="forminator-field">'
            . $widgetHtml
            . '</div></div></div>';
    }

    /** Rejects final Forminator submissions without a valid token. */
    public function validateForminatorToken(array $submit_errors, $form_id, array $field_data_array): array
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $submit_errors[][ 'cap-token' ] = esc_html($this->getVerificationFailedMessage());
        }

        return $submit_errors;
    }

    /** Reads a scalar token; malformed payloads are treated as missing. */
    private function getToken(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the surrounding WordPress/WooCommerce context before this hook runs.
        $token = $_POST['cap-token'] ?? '';
        return is_string($token) ? sanitize_text_field(wp_unslash($token)) : '';
    }

    /**
     * Detects Wordfence's credential preflight, which must not consume a captcha.
     */
    private function isWordfenceAuthenticationPreflight(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Wordfence validates its AJAX nonce before invoking authentication.
        $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
        return wp_doing_ajax() && $action === 'wordfence_ls_authenticate';
    }

    private function isGravityFormsActive(): bool
    {
        return class_exists('GFForms');
    }

    private function isForminatorActive(): bool
    {
        return class_exists('Forminator');
    }

    private function getVerificationFailedMessage(): string
    {
        $message = (string) get_option('capconnect_verification_failed_label', '');
        return $message !== '' ? $message : __('Captcha verification failed', 'capconnect-for-wp');
    }
}
