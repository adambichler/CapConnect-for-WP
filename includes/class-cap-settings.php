<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cap_Settings
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('Cap CAPTCHA Settings', 'wordpress-cap'),
            __('Cap CAPTCHA', 'wordpress-cap'),
            'manage_options',
            'cap-captcha',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('cap_settings_group', 'cap_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);

        register_setting('cap_settings_group', 'cap_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('cap_settings_group', 'cap_token_field', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'cap-token',
        ]);

        register_setting('cap_settings_group', 'cap_timeout', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 5,
        ]);

        register_setting('cap_settings_group', 'cap_fail_open', [
            'type'              => 'boolean',
            'sanitize_callback' => fn($v) => (bool) $v,
            'default'           => false,
        ]);

        add_settings_section(
            'cap_main_section',
            __('Cap Instance', 'wordpress-cap'),
            null,
            'cap-captcha'
        );

        add_settings_field(
            'cap_endpoint',
            __('Endpoint URL', 'wordpress-cap'),
            [$this, 'renderEndpointField'],
            'cap-captcha',
            'cap_main_section'
        );

        add_settings_field(
            'cap_secret',
            __('Secret Key', 'wordpress-cap'),
            [$this, 'renderSecretField'],
            'cap-captcha',
            'cap_main_section'
        );

        add_settings_field(
            'cap_token_field',
            __('Token Field Name', 'wordpress-cap'),
            [$this, 'renderTokenFieldField'],
            'cap-captcha',
            'cap_main_section'
        );

        add_settings_field(
            'cap_timeout',
            __('Timeout (seconds)', 'wordpress-cap'),
            [$this, 'renderTimeoutField'],
            'cap-captcha',
            'cap_main_section'
        );

        add_settings_field(
            'cap_fail_open',
            __('Fail Open', 'wordpress-cap'),
            [$this, 'renderFailOpenField'],
            'cap-captcha',
            'cap_main_section'
        );
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('cap_settings_group');
                do_settings_sections('cap-captcha');
                submit_button(__('Save Settings', 'wordpress-cap'));
                ?>
            </form>
        </div>
        <?php
    }

    public function renderEndpointField(): void
    {
        $value = get_option('cap_endpoint', '');
        echo '<input type="url" name="cap_endpoint" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://cap.example.com/your-site-key/" />';
        echo '<p class="description">' . esc_html__('Full URL of your self-hosted Cap instance, including the site key.', 'wordpress-cap') . '</p>';
    }

    public function renderSecretField(): void
    {
        $value = get_option('cap_secret', '');
        echo '<input type="password" name="cap_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The secret key from your Cap dashboard. Never expose this publicly.', 'wordpress-cap') . '</p>';
    }

    public function renderTokenFieldField(): void
    {
        $value = get_option('cap_token_field', 'cap-token');
        echo '<input type="text" name="cap_token_field" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The name of the hidden field injected by the Cap widget.', 'wordpress-cap') . '</p>';
    }

    public function renderTimeoutField(): void
    {
        $value = (int) get_option('cap_timeout', 5);
        echo '<input type="number" name="cap_timeout" value="' . esc_attr((string) $value) . '" min="1" max="30" class="small-text" />';
        echo '<p class="description">' . esc_html__('Seconds before abandoning the request to /siteverify.', 'wordpress-cap') . '</p>';
    }

    public function renderFailOpenField(): void
    {
        $value = (bool) get_option('cap_fail_open', false);
        echo '<label><input type="checkbox" name="cap_fail_open" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Allow requests through when the Cap server is unreachable (not recommended for high-security forms).', 'wordpress-cap') . '</label>';
    }
}
