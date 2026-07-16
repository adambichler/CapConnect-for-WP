<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Integrations
{
    private Tpow_Verifier $verifier;
    private Tpow_Widget $widget;
    private Tpow_Login_Recovery $loginRecovery;

    /**
     * Creates the form integrations and their login recovery dependency.
     */
    public function __construct(?Tpow_Login_Recovery $loginRecovery = null)
    {
        $this->verifier      = new Tpow_Verifier();
        $this->widget        = new Tpow_Widget();
        $this->loginRecovery = $loginRecovery ?? new Tpow_Login_Recovery();
    }

    public function init(): void
    {
        if (! Tpow_Settings::isConfigured()) {
            return;
        }

        $this->initCommentIntegration();
        $this->initLoginIntegration();
        $this->initRegisterIntegration();
        $this->initLostPasswordIntegration();
        $this->initWooCommerceIntegration();
        $this->initGravityFormsIntegration();
        $this->initForminatorIntegration();
    }

    private function initCommentIntegration(): void
    {
        if (! get_option('tpow_protect_comments', true)) {
            return;
        }
        add_action('comment_form_after_fields', [$this, 'renderWidgetComment']);
        add_filter('preprocess_comment', [$this, 'validateCommentToken']);
    }

    /**
     * Registers login protection unless the emergency bypass is active.
     */
    private function initLoginIntegration(): void
    {
        if (! get_option('tpow_protect_login', true) || $this->loginRecovery->isLoginCaptchaDisabled()) {
            return;
        }
        add_action('login_form', [$this, 'renderWidgetLogin']);
        add_filter('wp_authenticate_user', [$this, 'validateLoginToken'], 10, 2);
    }

    private function initRegisterIntegration(): void
    {
        if (! get_option('tpow_protect_register', true)) {
            return;
        }
        add_action('register_form', [$this, 'renderWidgetRegister']);
        add_filter('registration_errors', [$this, 'validateRegisterToken'], 10, 3);
    }

    private function initLostPasswordIntegration(): void
    {
        if (! get_option('tpow_protect_lostpassword', true)) {
            return;
        }
        add_action('lostpassword_form', [$this, 'renderWidgetLostPassword']);
        add_action('lostpassword_post', [$this, 'validateLostPasswordToken'], 10, 1);

        add_action('resetpass_form', [$this, 'renderWidgetResetPassword']);
        add_action('validate_password_reset', [$this, 'validateResetPasswordToken'], 10, 2);
    }

    private function initWooCommerceIntegration(): void
    {
        if (! get_option('tpow_protect_woocommerce', true)) {
            return;
        }

        if (! $this->isWooCommerceActive()) {
            return;
        }

        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderWidgetCheckout']);
        add_action('woocommerce_checkout_process', [$this, 'validateCheckoutToken']);
    }

    private function initGravityFormsIntegration(): void
    {
        if (! get_option('tpow_protect_gravityforms', true)) {
            return;
        }

        if (! $this->isGravityFormsActive()) {
            return;
        }

        add_filter('gform_submit_button', [$this, 'renderWidgetGravityForms'], 10, 2);
        add_filter('gform_validation', [$this, 'validateGravityFormsToken']);
    }

    private function initForminatorIntegration(): void
    {
        if (! get_option('tpow_protect_forminator', true)) {
            return;
        }

        if (! $this->isForminatorActive()) {
            return;
        }

        add_filter('forminator_render_button_markup', [$this, 'renderWidgetForminator'], 10, 2);
        add_filter('forminator_custom_form_submit_errors', [$this, 'validateForminatorToken'], 10, 3);
    }

    public function renderWidgetComment(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
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

        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetRegister(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetLostPassword(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetResetPassword(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetCheckout(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="form-row cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

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
     * Validates the login captcha or consumes a matching administrator recovery session.
     */
    public function validateLoginToken(\WP_User|\WP_Error $user, string $password): \WP_User|\WP_Error
    {
        if (is_wp_error($user)) {
            return $user;
        }

        if ($this->loginRecovery->consumeForUser($user)) {
            return $user;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $this->loginRecovery->markCaptchaValidationFailed();
            return new \WP_Error(
                'tpow_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }

        return $user;
    }

    public function validateRegisterToken(\WP_Error $errors, string $sanitizedUserLogin, string $userEmail): \WP_Error
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'tpow_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }

        return $errors;
    }

    public function validateLostPasswordToken(\WP_Error $errors): void
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'tpow_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }
    }

    public function validateResetPasswordToken(\WP_Error $errors, $user): void
    {
        if (! isset($_POST['cap-token'])) {
            return;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'tpow_verification_failed',
                $this->getVerificationFailedMessage()
            );
        }
    }

    public function validateCheckoutToken(): void
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wc_add_notice(
                $this->getVerificationFailedMessage(),
                'error'
            );
        }
    }

    public function renderWidgetGravityForms(string $button, array $form): string
    {
        $this->widget->enqueueAssets();

        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<div class="cap-widget-wrapper">' . $html . '</div>';
        }

        return $html . $button;
    }

    public function validateGravityFormsToken(array $validationResult): array
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $validationResult['is_valid'] = false;

            $formId = (int) $validationResult['form']['id'];
            add_filter(
                "gform_validation_message_{$formId}",
                fn(string $message): string => '<div class="validation_error">'
                    . esc_html($this->getVerificationFailedMessage())
                    . '</div>'
            );
        }

        return $validationResult;
    }

    /**
     * Renders the Cap field in Forminator's field structure.
     */
    public function renderWidgetForminator(string $html, $button): string
    {
        if (strpos($html, 'forminator-button-submit') === false) {
            return $html;
        }

        $this->widget->enqueueAssets();

        $widgetHtml = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $widgetHtml = '<div class="cap-widget-wrapper">' . $widgetHtml . '</div>';
        }

        $widgetHtml = '<div class="forminator-row cap-widget-row"><div class="forminator-col forminator-col-12"><div class="forminator-field">'
            . $widgetHtml
            . '</div></div></div>';

        return $widgetHtml . $html;
    }

    public function validateForminatorToken(array $submit_errors, $form_id, array $field_data_array): array
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $submit_errors[][ 'cap-token' ] = esc_html($this->getVerificationFailedMessage());
        }

        return $submit_errors;
    }

    private function getToken(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the surrounding WordPress/WooCommerce context before this hook runs.
        return sanitize_text_field(wp_unslash((string) ($_POST['cap-token'] ?? '')));
    }

    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
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
        $message = (string) get_option('tpow_verification_failed_label', '');
        return $message !== '' ? $message : __('Captcha verification failed', 'capconnect-for-wp');
    }
}
