<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cap_Integrations
{
    private Cap_Verifier $verifier;
    private Cap_Widget $widget;

    public function __construct()
    {
        $this->verifier = new Cap_Verifier();
        $this->widget   = new Cap_Widget();
    }

    public function init(): void
    {
        $this->initCommentIntegration();
        $this->initLoginIntegration();
        $this->initRegisterIntegration();
        $this->initWooCommerceIntegration();
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
                esc_html__('Cap verification failed. Please complete the challenge and try again.', 'wordpress-cap'),
                esc_html__('Cap Verification Failed', 'wordpress-cap'),
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
                'cap_verification_failed',
                __('Cap verification failed. Please complete the challenge and try again.', 'wordpress-cap')
            );
        }

        return $user;
    }

    public function validateRegisterToken(\WP_Error $errors, string $sanitizedUserLogin, string $userEmail): \WP_Error
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            $errors->add(
                'cap_verification_failed',
                __('Cap verification failed. Please complete the challenge and try again.', 'wordpress-cap')
            );
        }

        return $errors;
    }

    public function validateCheckoutToken(): void
    {
        $token = $this->getToken();

        if (! $this->verifier->verify($token)) {
            wc_add_notice(
                __('Cap verification failed. Please complete the challenge and try again.', 'wordpress-cap'),
                'error'
            );
        }
    }

    private function getToken(): string
    {
        $field = (string) get_option('cap_token_field', 'cap-token');

        return sanitize_text_field((string) ($_POST[$field] ?? ''));
    }

    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }
}
