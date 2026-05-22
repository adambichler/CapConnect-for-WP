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
        $this->initWooCommerceIntegration();
        $this->initGravityFormsIntegration();
    }

    private function initCommentIntegration(): void
    {
        add_action('comment_form_after_fields', [$this, 'renderWidgetComment']);
        add_filter('preprocess_comment', [$this, 'validateCommentToken']);
    }

    private function initLoginIntegration(): void
    {
        add_action('login_form', [$this, 'renderWidgetLogin']);
        add_filter('wp_authenticate_user', [$this, 'validateLoginToken'], 10, 2);
    }

    private function initRegisterIntegration(): void
    {
        add_action('register_form', [$this, 'renderWidgetRegister']);
        add_filter('registration_errors', [$this, 'validateRegisterToken'], 10, 3);
    }

    private function initWooCommerceIntegration(): void
    {
        if (! $this->isWooCommerceActive()) {
            return;
        }

        add_action('woocommerce_after_checkout_billing_form', [$this, 'renderWidgetCheckout']);
        add_action('woocommerce_checkout_process', [$this, 'validateCheckoutToken']);
    }

    private function initGravityFormsIntegration(): void
    {
        if (! $this->isGravityFormsActive()) {
            return;
        }

        add_filter('gform_submit_button', [$this, 'renderWidgetGravityForms'], 10, 2);
        add_filter('gform_validation', [$this, 'validateGravityFormsToken']);
    }

    public function renderWidgetComment(): void
    {
        $this->widget->enqueueAssets();
        echo $this->widget->renderWidget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetLogin(): void
    {
        $this->widget->enqueueAssets();
        echo $this->widget->renderWidget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetRegister(): void
    {
        $this->widget->enqueueAssets();
        echo $this->widget->renderWidget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function renderWidgetCheckout(): void
    {
        $this->widget->enqueueAssets();
        echo $this->widget->renderWidget(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function validateCommentToken(array $commentData): array
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wp_die(
                esc_html__('Cap verification failed. Please complete the challenge and try again.', 'oliweb-proof-of-work-for-cap'),
                esc_html__('Cap Verification Failed', 'oliweb-proof-of-work-for-cap'),
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
                __('Cap verification failed. Please complete the challenge and try again.', 'oliweb-proof-of-work-for-cap')
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
                __('Cap verification failed. Please complete the challenge and try again.', 'oliweb-proof-of-work-for-cap')
            );
        }

        return $errors;
    }

    public function validateCheckoutToken(): void
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wc_add_notice(
                __('Cap verification failed. Please complete the challenge and try again.', 'oliweb-proof-of-work-for-cap'),
                'error'
            );
        }
    }

    public function renderWidgetGravityForms(string $button, array $form): string
    {
        $this->widget->enqueueAssets();

        return $this->widget->renderWidget() . $button;
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
                    . esc_html__('Cap verification failed. Please complete the challenge and try again.', 'oliweb-proof-of-work-for-cap')
                    . '</div>'
            );
        }

        return $validationResult;
    }

    private function getToken(): string
    {
        $field = (string) get_option('tpow_token_field', 'cap-token');

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce is verified by the surrounding WordPress/WooCommerce context before this hook runs.
        return sanitize_text_field(wp_unslash((string) ($_POST[$field] ?? '')));
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
