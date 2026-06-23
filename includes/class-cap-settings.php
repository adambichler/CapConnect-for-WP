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
        add_action('wp_ajax_tpow_test_connection', [$this, 'handleTestConnection']);

        // Settings link on the plugins list page
        $plugin_file = TPOW_PLUGIN_DIR . 'oliweb-proof-of-work-for-cap.php';
        add_filter('plugin_action_links_' . plugin_basename($plugin_file), [$this, 'addSettingsLink']);

        // Migrate legacy combined endpoint URL to separate fields
        $this->migrateLegacyEndpoint();
    }

    /**
     * Fügt einen Einstellungs-Link auf der WordPress-Plugin-Seite hinzu.
     */
    public function addSettingsLink(array $links): array
    {
        $settings_link = '<a href="' . esc_url(admin_url('options-general.php?page=tpow-settings')) . '">'
            . esc_html__('Settings', 'capconnect-for-wp')
            . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Liefert den kombinierten Endpoint aus Instanz-URL und Site Key.
     */
    public static function getEndpoint(): string
    {
        $instance = (string) get_option('tpow_instance_url', '');
        $site_key = (string) get_option('tpow_site_key', '');
        if ($instance !== '' && $site_key !== '') {
            return rtrim($instance, '/') . '/' . ltrim($site_key, '/');
        }
        return (string) get_option('tpow_endpoint', '');
    }

    /**
     * Migriert das alte, kombinierte tpow_endpoint in separate Optionen.
     */
    private function migrateLegacyEndpoint(): void
    {
        $endpoint = trim((string) get_option('tpow_endpoint', ''));
        if ($endpoint !== '') {
            $parsed_url = wp_parse_url($endpoint);
            if ($parsed_url && isset($parsed_url['path'])) {
                $path = trim($parsed_url['path'], '/');
                $parts = explode('/', $path);
                if (! empty($parts)) {
                    $site_key = array_pop($parts);
                    
                    // Rebuild the instance URL
                    $scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
                    $host = isset($parsed_url['host']) ? $parsed_url['host'] : '';
                    $port = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
                    $remaining_path = implode('/', $parts);
                    
                    $instance_url = $scheme . $host . $port;
                    if ($remaining_path !== '') {
                        $instance_url .= '/' . $remaining_path;
                    }
                    
                    update_option('tpow_instance_url', $instance_url);
                    update_option('tpow_site_key', $site_key);
                    delete_option('tpow_endpoint');
                }
            }
        }
    }

    /**
     * Fügt die Einstellungsseite im WordPress-Backend hinzu.
     */
    public function addMenuPage(): void
    {
        add_options_page(
            __('CapConnect for WP — Settings', 'capconnect-for-wp'),
            __('CapConnect', 'capconnect-for-wp'),
            'manage_options',
            'tpow-settings',
            [$this, 'renderPage']
        );
    }

    public function registerSettings(): void
    {
        register_setting('tpow_settings_group', 'tpow_instance_url', [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_site_key', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_secret', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
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
            __('Cap Instance', 'capconnect-for-wp'),
            null,
            'tpow-settings'
        );

        add_settings_field(
            'tpow_mode',
            __('Verification Mode', 'capconnect-for-wp'),
            [$this, 'renderModeField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_instance_url',
            __('Instance URL', 'capconnect-for-wp'),
            [$this, 'renderInstanceUrlField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_site_key',
            __('Site Key', 'capconnect-for-wp'),
            [$this, 'renderSiteKeyField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_secret',
            __('Secret Key', 'capconnect-for-wp'),
            [$this, 'renderSecretField'],
            'tpow-settings',
            'tpow_main_section'
        );


        add_settings_field(
            'tpow_timeout',
            __('Timeout (seconds)', 'capconnect-for-wp'),
            [$this, 'renderTimeoutField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_fail_open',
            __('Fail Open', 'capconnect-for-wp'),
            [$this, 'renderFailOpenField'],
            'tpow-settings',
            'tpow_main_section'
        );

        add_settings_field(
            'tpow_hide_attribution',
            __('Hide Attribution Link', 'capconnect-for-wp'),
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
                submit_button(__('Save Settings', 'capconnect-for-wp'));
                ?>
            </form>

            <hr>
            <h2><?php esc_html_e('Test Connection', 'capconnect-for-wp'); ?></h2>
            <p><?php esc_html_e('Checks that the endpoint URL is reachable and returns a valid challenge. Save your settings first.', 'capconnect-for-wp'); ?></p>
            <button id="tpow-test-btn" class="button button-secondary">
                <?php esc_html_e('Test connection', 'capconnect-for-wp'); ?>
            </button>
            <span id="tpow-test-result" style="margin-left:10px;line-height:30px;vertical-align:middle;"></span>

            <script>
            (function () {
                var nonce = <?php echo wp_json_encode(wp_create_nonce('tpow_test_connection')); ?>;
                var btn    = document.getElementById('tpow-test-btn');
                var result = document.getElementById('tpow-test-result');

                btn.addEventListener('click', function () {
                    btn.disabled   = true;
                    result.style.color = '#666';
                    result.textContent = '<?php esc_html_e('Testing…', 'capconnect-for-wp'); ?>';

                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    'action=tpow_test_connection&nonce=' + encodeURIComponent(nonce),
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        result.style.color = data.success ? 'green' : '#cc0000';
                        result.textContent = data.data.message;
                    })
                    .catch(function () {
                        result.style.color = '#cc0000';
                        result.textContent = '<?php esc_html_e('Request failed.', 'capconnect-for-wp'); ?>';
                    })
                    .finally(function () { btn.disabled = false; });
                });
            })();
            </script>
        </div>
        <?php
    }

    public function renderModeField(): void
    {
        $value = (string) get_option('tpow_mode', 'widget');
        echo '<select name="tpow_mode">';
        echo '<option value="widget"' . selected($value, 'widget', false) . '>' . esc_html__('Widget (visible)', 'capconnect-for-wp') . '</option>';
        echo '<option value="programmatic"' . selected($value, 'programmatic', false) . '>' . esc_html__('Programmatic (invisible)', 'capconnect-for-wp') . '</option>';
        echo '</select>';
        echo '<p class="description">' . esc_html__('Programmatic mode solves the challenge silently in the background — no widget is shown to the user.', 'capconnect-for-wp') . '</p>';
    }

    public function renderInstanceUrlField(): void
    {
        $value = get_option('tpow_instance_url', '');
        echo '<input type="url" name="tpow_instance_url" value="' . esc_attr($value) . '" class="regular-text" placeholder="https://cap.example.com" />';
        echo '<p class="description">' . esc_html__('Your Cap Standalone server URL, e.g. https://cap.example.com', 'capconnect-for-wp') . '</p>';
    }

    public function renderSiteKeyField(): void
    {
        $value = get_option('tpow_site_key', '');
        echo '<input type="text" name="tpow_site_key" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The site key from your Cap dashboard.', 'capconnect-for-wp') . '</p>';
    }

    public function renderSecretField(): void
    {
        $value = get_option('tpow_secret', '');
        echo '<input type="password" name="tpow_secret" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html__('The secret key from your Cap dashboard. Never expose this publicly.', 'capconnect-for-wp') . '</p>';
    }


    public function renderTimeoutField(): void
    {
        $value = (int) get_option('tpow_timeout', 5);
        echo '<input type="number" name="tpow_timeout" value="' . esc_attr((string) $value) . '" min="1" max="30" class="small-text" />';
        echo '<p class="description">' . esc_html__('Seconds before abandoning the request to /siteverify.', 'capconnect-for-wp') . '</p>';
    }

    public function renderFailOpenField(): void
    {
        $value = (bool) get_option('tpow_fail_open', false);
        echo '<label><input type="checkbox" name="tpow_fail_open" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Allow requests through when the Cap server is unreachable (not recommended for high-security forms).', 'capconnect-for-wp') . '</label>';
    }

    public function renderHideAttributionField(): void
    {
        $value = (bool) get_option('tpow_hide_attribution', false);
        echo '<label><input type="checkbox" name="tpow_hide_attribution" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Hide the "Cap" link displayed in the bottom-right corner of the widget.', 'capconnect-for-wp') . '</label>';
    }

    public function handleTestConnection(): void
    {
        check_ajax_referer('tpow_test_connection', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'capconnect-for-wp')], 403);
        }

        $endpoint = self::getEndpoint();

        if (empty($endpoint)) {
            wp_send_json_error(['message' => __('No endpoint URL configured.', 'capconnect-for-wp')]);
        }

        $url = rtrim($endpoint, '/') . '/challenge';

        $response = wp_remote_post($url, [
            'timeout'     => 10,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => '{}',
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['token'])) {
            wp_send_json_error([
                'message' => sprintf(
                    /* translators: 1: HTTP status code */
                    __('Server responded with HTTP %d — check your endpoint URL.', 'capconnect-for-wp'),
                    $code
                ),
            ]);
        }

        wp_send_json_success([
            'message' => __('✓ Connection successful — Cap server is reachable and responding correctly.', 'capconnect-for-wp'),
        ]);
    }
}
