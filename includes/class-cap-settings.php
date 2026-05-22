<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Settings
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addMenuPage']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenuPage(): void
    {
        add_options_page(
            __('OliWeb Proof-of-Work for Cap — Settings', 'oliweb-proof-of-work-for-cap'),
            __('PoW for Cap', 'oliweb-proof-of-work-for-cap'),
            'manage_options',
            'tpow-settings',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('tpow_settings_group', 'tpow_endpoint', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_token_field', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'cap-token',
        ]);

        register_setting('tpow_settings_group', 'tpow_timeout', [
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 5,
        ]);

        register_setting('tpow_settings_group', 'tpow_fail_open', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => false,
        ]);

        register_setting('tpow_settings_group', 'tpow_hide_attribution', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => false,
        ]);

        register_setting('tpow_settings_group', 'tpow_mode', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_key',
            'default'           => 'widget',
        ]);

        add_settings_section(
            'tpow_main_section',
            __('Cap Instance', 'oliweb-proof-of-work-for-cap'),
            null,
            'tpow-settings'
        );

        add_settings_field(
            'tpow_mode',
            __('Verification Mode', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderModeField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_endpoint',
            __('Endpoint URL', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderEndpointField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_secret',
            __('Secret Key', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderSecretField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_token_field',
            __('Token Field Name', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderTokenFieldField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_timeout',
            __('Timeout (seconds)', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderTimeoutField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_fail_open',
            __('Fail Open', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderFailOpenField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_hide_attribution',
            __('Hide Attribution Link', 'oliweb-proof-of-work-for-cap'),
            [$this, 'renderHideAttributionField'],
            'tpow-settings',
            'tpow_main_section'
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
                settings_fields('tpow_settings_group');
                do_settings_sections('tpow-settings');
                submit_button(__('Save Settings', 'oliweb-proof-of-work-for-cap'));
                ?>
            </form>
        </div>
        <?php
    }

    public function renderModeField(): void
    {
        $value = (string) get_option('tpow_mode', 'widget');
        echo '<select name="tpow_mode">';
        echo '<option value="widget"' . selected($value, 'widget', false) . '>' . esc_html__('Widget (visible)', 'oliweb-proof-of-work-for-cap') . '</option>';
        echo '<option value="programmatic"' . selected($value, 'programmatic', false) . '>' . esc_html__('Programmatic (invisible)', 'oliweb-proof-of-work-for-cap') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Programmatic mode solves the challenge silently in the background — no widget is shown to the user.', 'oliweb-proof-of-work-for-cap') . '</p>';
    }

    public function renderEndpointField(): void
    {
        $value = get_option('tpow_endpoint', '');
        echo '<input type="url" name="tpow_endpoint" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://cap.example.com/your-site-key/" />';
        echo '<p class="description">' . esc_html__('Full URL of your self-hosted Cap instance, including the site key.', 'oliweb-proof-of-work-for-cap') . '</p>';
    }

    public function renderSecretField(): void
    {
        $value = get_option('tpow_secret', '');
        echo '<input type="password" name="tpow_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The secret key from your Cap dashboard. Never expose this publicly.', 'oliweb-proof-of-work-for-cap') . '</p>';
    }

    public function renderTokenFieldField(): void
    {
        $value = get_option('tpow_token_field', 'cap-token');
        echo '<input type="text" name="tpow_token_field" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The name of the hidden field injected by the Cap widget.', 'oliweb-proof-of-work-for-cap') . '</p>';
    }

    public function renderTimeoutField(): void
    {
        $value = (int) get_option('tpow_timeout', 5);
        echo '<input type="number" name="tpow_timeout" value="' . esc_attr((string) $value) . '" min="1" max="30" class="small-text" />';
        echo '<p class="description">' . esc_html__('Seconds before abandoning the request to /siteverify.', 'oliweb-proof-of-work-for-cap') . '</p>';
    }

    public function renderFailOpenField(): void
    {
        $value = (bool) get_option('tpow_fail_open', false);
        echo '<label><input type="checkbox" name="tpow_fail_open" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Allow requests through when the Cap server is unreachable (not recommended for high-security forms).', 'oliweb-proof-of-work-for-cap') . '</label>';
    }

    public function renderHideAttributionField(): void
    {
        $value = (bool) get_option('tpow_hide_attribution', false);
        echo '<label><input type="checkbox" name="tpow_hide_attribution" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Hide the "Cap" link displayed in the bottom-right corner of the widget.', 'oliweb-proof-of-work-for-cap') . '</label>';
    }
}
