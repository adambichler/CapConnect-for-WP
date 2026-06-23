<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Integrations
{
    private Tpow_Verifier $verifier;
    private Tpow_Widget $widget;

    public function __construct()
    {
        $this->verifier = new Tpow_Verifier();
        $this->widget   = new Tpow_Widget();
    }

    public function init(): void
    {
        $this->initCommentIntegration();
        $this->initLoginIntegration();
        $this->initRegisterIntegration();
        $this->initLostPasswordIntegration();
        $this->initWooCommerceIntegration();
        $this->initGravityFormsIntegration();
    }

    private function initCommentIntegration(): void
    {
        if (! get_option('tpow_protect_comments', true)) {
            return;
        }
        add_action('comment_form_after_fields', [$this, 'renderWidgetComment']);
        add_filter('preprocess_comment', [$this, 'validateCommentToken']);
    }

    private function initLoginIntegration(): void
    {
        if (! get_option('tpow_protect_login', true)) {
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

    public function renderWidgetComment(): void
    {
        $this->widget->enqueueAssets();
        $html = $this->widget->renderForMode();
        if (get_option('tpow_mode', 'widget') !== 'programmatic') {
            $html = '<p class="cap-widget-wrapper">' . $html . '</p>';
        }
        echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetLogin(): void
    {
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
                esc_html__('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp'),
                esc_html__('Cap Verification Failed', 'capconnect-for-wp'),
                ['response' => 403, 'back_link' => true]
            );
        }

        return $commentData;
    }

    public function validateLoginToken(\WP_User|\WP_Error $user, string $password): \WP_User|\WP_Error
    {
        if (is_wp_error($user)) {
            return $user;
        }

        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            return new \WP_Error(
                'tpow_verification_failed',
                __('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp')
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
                __('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp')
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
                __('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp')
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
                __('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp')
            );
        }
    }

    public function validateCheckoutToken(): void
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wc_add_notice(
                __('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp'),
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
                    . esc_html__('Cap verification failed. Please complete the challenge and try again.', 'capconnect-for-wp')
                    . '</div>'
            );
        }

        return $validationResult;
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
}
