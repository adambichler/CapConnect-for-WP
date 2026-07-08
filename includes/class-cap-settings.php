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
        add_action('wp_ajax_tpow_report_frontend_error', [$this, 'handleReportFrontendError']);
        add_action('wp_ajax_nopriv_tpow_report_frontend_error', [$this, 'handleReportFrontendError']);
        add_action('admin_init', [$this, 'handleResetSettings']);
        add_action('admin_init', [$this, 'handleResetStyleSettings']);
        add_action('tpow_hourly_connection_check', [$this, 'runHourlyCheck']);
        add_action('admin_notices', [$this, 'displayConnectionFailedNotice']);

        // Reset alert status and verify connection when credentials or alert settings change
        add_action('update_option_tpow_instance_url', [$this, 'handleCredentialUpdate']);
        add_action('update_option_tpow_site_key', [$this, 'handleCredentialUpdate']);
        add_action('update_option_tpow_secret', [$this, 'handleCredentialUpdate']);
        add_action('update_option_tpow_alert_email', [$this, 'handleCredentialUpdate']);

        // Settings link on the plugins list page
        $plugin_file = TPOW_PLUGIN_DIR . 'capconnect-for-wp.php';
        add_filter('plugin_action_links_' . plugin_basename($plugin_file), [$this, 'addSettingsLink']);

        // Migrate legacy combined endpoint URL to separate fields
        $this->migrateLegacyEndpoint();

        // Register filters to support locale-specific translations directly within the settings fields
        $multilingual_options = [
            'tpow_initial_state_label',
            'tpow_verifying_label',
            'tpow_solved_label',
            'tpow_required_label',
            'tpow_verified_aria_label',
            'tpow_verifying_aria_label',
            'tpow_verify_aria_label',
            'tpow_error_label',
            'tpow_error_aria_label',
            'tpow_wasm_disabled_label',
            'tpow_troubleshoot_label',
        ];

        foreach ($multilingual_options as $option) {
            add_filter("pre_update_option_{$option}", function($new_value) use ($option) {
                $locale = isset($_POST['tpow_current_edit_locale']) ? sanitize_text_field(wp_unslash($_POST['tpow_current_edit_locale'])) : self::getCurrentLanguage();
                $translations = get_option("{$option}_translations", []);
                $translations[$locale] = $new_value;
                update_option("{$option}_translations", $translations);
                return $new_value;
            }, 10, 1);

            add_filter("option_{$option}", function($value) use ($option) {
                $locale = self::getCurrentLanguage();
                $translations = get_option("{$option}_translations", []);
                
                if (isset($translations[$locale])) {
                    return $translations[$locale];
                }
                
                // Fallback for single-language sites or legacy saves:
                // If the translations array is completely empty, but the base option has a value,
                // we treat the base option as the value for the site's default locale.
                if (empty($translations) && $value !== '') {
                    return $value;
                }
                
                return '';
            }, 10, 1);
        }
    }

    /**
     * Adds a settings link on the WordPress plugins page.
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
     * Returns the combined endpoint from the instance URL and site key.
     */
    public static function getEndpoint(): string
    {
        $instance = (string) get_option('tpow_instance_url', '');
        $site_key = (string) get_option('tpow_site_key', '');
        if ($instance !== '' && $site_key !== '') {
            return rtrim($instance, '/') . '/' . ltrim($site_key, '/');
        }
        return '';
    }

    /**
     * Migrates the legacy combined tpow_endpoint to separate options.
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
     * Adds the settings page in the WordPress backend.
     */
    public function addMenuPage(): void
    {
        $hook = add_options_page(
            __('CapConnect for WP — Settings', 'capconnect-for-wp'),
            __('CapConnect', 'capconnect-for-wp'),
            'manage_options',
            'tpow-settings',
            [$this, 'renderPage']
        );
        add_action("admin_print_scripts-{$hook}", [$this, 'enqueueAdminScripts']);
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

        register_setting('tpow_settings_group', 'tpow_alert_email', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default'           => '',
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

        register_setting('tpow_settings_group', 'tpow_background', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_color', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_border_color', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_background', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_spinner_color', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '#374151',
        ]);

        register_setting('tpow_settings_group', 'tpow_spinner_background', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_border_color', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_border_style', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeBorderStyle'],
            'default'           => 'solid',
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_border_width', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitizeBorderWidth'],
            'default'           => 2,
        ]);

        register_setting('tpow_settings_group', 'tpow_border_radius', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitizeWidgetBorderRadius'],
            'default'           => 8,
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_border_radius', [
            'type'              => 'integer',
            'sanitize_callback' => [$this, 'sanitizeCheckboxBorderRadius'],
            'default'           => 5,
        ]);

        register_setting('tpow_settings_group', 'tpow_checkbox_checkmark_color', [
            'type'              => 'string',
            'sanitize_callback' => [$this, 'sanitizeHexColor'],
            'default'           => '#374151',
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_login', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_register', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_lostpassword', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_comments', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_woocommerce', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_protect_gravityforms', [
            'type'              => 'boolean',
            'sanitize_callback' => 'boolval',
            'default'           => true,
        ]);

        register_setting('tpow_settings_group', 'tpow_initial_state_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_verifying_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_solved_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_required_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_verified_aria_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_verifying_aria_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_verify_aria_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_error_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_error_aria_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_wasm_disabled_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ]);

        register_setting('tpow_settings_group', 'tpow_troubleshoot_label', [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
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
            'tpow_alert_email',
            __('Alert Email', 'capconnect-for-wp'),
            [$this, 'renderAlertEmailField'],
            'tpow-settings',
            'tpow_main_section'
        );



        add_settings_section(
            'tpow_forms_section',
            __('Form Protection', 'capconnect-for-wp'),
            [$this, 'renderFormsSectionDescription'],
            'tpow-settings'
        );

        add_settings_field(
            'tpow_protect_login',
            __('Login Form', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_login',
                'label' => __('Protect the login form', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_protect_register',
            __('Registration Form', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_register',
                'label' => __('Protect the registration form', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_protect_lostpassword',
            __('Lost Password Form', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_lostpassword',
                'label' => __('Protect the lost password and password reset forms', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_protect_comments',
            __('Comments Form', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_comments',
                'label' => __('Protect the comments form', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_protect_woocommerce',
            __('WooCommerce Checkout', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_woocommerce',
                'label' => __('Protect the WooCommerce checkout form', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_protect_gravityforms',
            __('Gravity Forms', 'capconnect-for-wp'),
            [$this, 'renderCheckboxField'],
            'tpow-settings',
            'tpow_forms_section',
            [
                'field' => 'tpow_protect_gravityforms',
                'label' => __('Protect Gravity Forms submissions', 'capconnect-for-wp'),
            ]
        );

        add_settings_section(
            'tpow_styling_section',
            __('Custom Styling', 'capconnect-for-wp'),
            [$this, 'renderStylingSectionDescription'],
            'tpow-settings'
        );

        add_settings_field(
            'tpow_widget_styles',
            __('Widget Container Styles', 'capconnect-for-wp'),
            [$this, 'renderWidgetStylesField'],
            'tpow-settings',
            'tpow_styling_section',
            [
                'class' => 'tpow-styling-field',
            ]
        );

        add_settings_field(
            'tpow_checkbox_styles',
            __('Checkbox Styles', 'capconnect-for-wp'),
            [$this, 'renderCheckboxStylesField'],
            'tpow-settings',
            'tpow_styling_section',
            [
                'class' => 'tpow-styling-field',
            ]
        );

        add_settings_field(
            'tpow_spinner_styles',
            __('Spinner Styles', 'capconnect-for-wp'),
            [$this, 'renderSpinnerStylesField'],
            'tpow-settings',
            'tpow_styling_section',
            [
                'class' => 'tpow-styling-field',
            ]
        );

        add_settings_field(
            'tpow_hide_attribution',
            __('Hide Attribution Link', 'capconnect-for-wp'),
            [$this, 'renderHideAttributionField'],
            'tpow-settings',
            'tpow_styling_section'
        );

        add_settings_section(
            'tpow_labels_section',
            __('Widget Labels & Translations', 'capconnect-for-wp'),
            [$this, 'renderLabelsSectionDescription'],
            'tpow-settings'
        );

        add_settings_field(
            'tpow_initial_state_label',
            __('Initial Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_initial_state_label',
                'placeholder' => __("Verify you're human", 'capconnect-for-wp'),
                'description' => __('The initial text displayed on the widget.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_verifying_label',
            __('Verifying Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_verifying_label',
                'placeholder' => __('Verifying...', 'capconnect-for-wp'),
                'description' => __('Text shown while the verification challenge is running.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_solved_label',
            __('Solved Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_solved_label',
                'placeholder' => __("You're a human", 'capconnect-for-wp'),
                'description' => __('Text shown when verification is completed successfully.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_required_label',
            __('Required Field Error Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_required_label',
                'placeholder' => __("Please verify you're human", 'capconnect-for-wp'),
                'description' => __('HTML5 validation error message shown if form is submitted without solving the widget.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_verified_aria_label',
            __('Solved Accessibility Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_verified_aria_label',
                'placeholder' => __("We have verified you're a human, you may now continue", 'capconnect-for-wp'),
                'description' => __('Accessibility/ARIA screen-reader message read when verification is successfully completed.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_verifying_aria_label',
            __('Verifying Accessibility Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_verifying_aria_label',
                'placeholder' => __("Verifying you're a human, please wait", 'capconnect-for-wp'),
                'description' => __('Accessibility/ARIA screen-reader message read when verification starts.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_verify_aria_label',
            __('Action Accessibility Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_verify_aria_label',
                'placeholder' => __("Click to verify you're a human", 'capconnect-for-wp'),
                'description' => __('Accessibility/ARIA screen-reader description for the click target button.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_error_label',
            __('Error Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_error_label',
                'placeholder' => __('Error', 'capconnect-for-wp'),
                'description' => __('General error text shown in the widget when verification fails.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_error_aria_label',
            __('Error Accessibility Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_error_aria_label',
                'placeholder' => __('An error occurred, please try again', 'capconnect-for-wp'),
                'description' => __('Accessibility/ARIA screen-reader error message read when verification fails.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_wasm_disabled_label',
            __('WASM Warning Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_wasm_disabled_label',
                'placeholder' => __('Enable WASM for significantly faster solving', 'capconnect-for-wp'),
                'description' => __('Warning message shown to users who have WebAssembly disabled in their browser.', 'capconnect-for-wp'),
            ]
        );

        add_settings_field(
            'tpow_troubleshoot_label',
            __('Troubleshooting Link Label', 'capconnect-for-wp'),
            [$this, 'renderLabelField'],
            'tpow-settings',
            'tpow_labels_section',
            [
                'field'       => 'tpow_troubleshoot_label',
                'placeholder' => __('Troubleshoot', 'capconnect-for-wp'),
                'description' => __('The text for the troubleshooting guide link displayed when verification fails.', 'capconnect-for-wp'),
            ]
        );
    }

    /**
     * Renders a single settings section by ID.
     */
    private function renderSettingsSection(string $page, string $section_id): void
    {
        global $wp_settings_sections, $wp_settings_fields;

        if (! isset($wp_settings_sections[$page][$section_id])) {
            return;
        }

        $section = $wp_settings_sections[$page][$section_id];

        if ($section['title']) {
            echo "<h2>" . esc_html($section['title']) . "</h2>\n";
        }

        if ($section['callback']) {
            call_user_func($section['callback'], $section);
        }

        if (! isset($wp_settings_fields[$page][$section_id])) {
            return;
        }

        echo '<table class="form-table" role="presentation">';
        do_settings_fields($page, $section_id);
        echo '</table>';
    }

    public function renderPage(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        if (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'reset') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('All settings have been reset to defaults.', 'capconnect-for-wp') . '</p></div>';
        } elseif (isset($_GET['settings-updated']) && $_GET['settings-updated'] === 'reset-styles') {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Style settings have been reset to defaults.', 'capconnect-for-wp') . '</p></div>';
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <style>
                .tpow-nav-tab-wrapper {
                    margin-bottom: 20px;
                }
                .tpow-nav-tab-wrapper .nav-tab {
                    cursor: pointer;
                    user-select: none;
                }
                .tpow-tab-content {
                    background: #fff;
                    border: 1px solid #ccd0d4;
                    border-top: none;
                    padding: 20px;
                    box-shadow: 0 1px 1px rgba(0,0,0,.04);
                }
                .tpow-test-connection-wrapper {
                    margin-top: 30px;
                    padding-top: 20px;
                    border-top: 1px solid #ccd0d4;
                }
                .tpow-submit-wrapper {
                    margin-top: 20px;
                    padding: 0;
                }
            </style>

            <h2 class="nav-tab-wrapper tpow-nav-tab-wrapper">
                <a href="#connection" class="nav-tab nav-tab-active" data-tab="connection"><?php esc_html_e('Connection', 'capconnect-for-wp'); ?></a>
                <a href="#forms" class="nav-tab" data-tab="forms"><?php esc_html_e('Forms', 'capconnect-for-wp'); ?></a>
                <a href="#styling" class="nav-tab" data-tab="styling"><?php esc_html_e('Styling', 'capconnect-for-wp'); ?></a>
                <a href="#labels" class="nav-tab" data-tab="labels"><?php esc_html_e('Labels', 'capconnect-for-wp'); ?></a>
            </h2>

            <form method="post" action="options.php">
                <?php settings_fields('tpow_settings_group'); ?>
                <input type="hidden" name="tpow_current_edit_locale" value="<?php echo esc_attr(self::getCurrentLanguage()); ?>" />

                <!-- Connection Tab -->
                <div id="tpow-tab-connection" class="tpow-tab-content">
                    <?php $this->renderSettingsSection('tpow-settings', 'tpow_main_section'); ?>
                    
                    <div class="tpow-test-connection-wrapper">
                        <h2><?php esc_html_e('Test Connection', 'capconnect-for-wp'); ?></h2>
                        <p><?php esc_html_e('Checks that the endpoint URL is reachable and returns a valid challenge. Save your settings first.', 'capconnect-for-wp'); ?></p>
                        <button type="button" id="tpow-test-btn" class="button button-secondary">
                            <?php esc_html_e('Test connection', 'capconnect-for-wp'); ?>
                        </button>
                        <span id="tpow-test-result" style="margin-left:10px;line-height:30px;vertical-align:middle;"></span>
                    </div>
                </div>

                <!-- Forms Tab -->
                <div id="tpow-tab-forms" class="tpow-tab-content" style="display: none;">
                    <?php $this->renderSettingsSection('tpow-settings', 'tpow_forms_section'); ?>
                </div>

                <!-- Styling Tab -->
                <div id="tpow-tab-styling" class="tpow-tab-content" style="display: none;">
                    <?php $this->renderSettingsSection('tpow-settings', 'tpow_styling_section'); ?>
                    <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ccd0d4;">
                        <button type="button" id="tpow-reset-styles-trigger-btn" class="button button-secondary" style="text-decoration: none; color: #b32d2e; border-color: #b32d2e;">
                            <?php esc_html_e('Reset Style Settings', 'capconnect-for-wp'); ?>
                        </button>
                    </div>
                </div>

                <!-- Labels Tab -->
                <div id="tpow-tab-labels" class="tpow-tab-content" style="display: none;">
                    <?php $this->renderSettingsSection('tpow-settings', 'tpow_labels_section'); ?>
                </div>

                <div class="tpow-submit-wrapper">
                    <p class="submit" style="display: flex; align-items: center; gap: 16px;">
                        <?php submit_button(__('Save Settings', 'capconnect-for-wp'), 'primary', 'submit', false); ?>
                        <button type="button" id="tpow-reset-trigger-btn" class="button button-secondary" style="text-decoration: none; color: #b32d2e; border-color: #b32d2e;">
                            <?php esc_html_e('Reset All Settings', 'capconnect-for-wp'); ?>
                        </button>
                    </p>
                </div>
            </form>

            <form id="tpow-reset-form" method="post" action="" style="display:none;">
                <?php wp_nonce_field('tpow_reset_settings_nonce', 'tpow_reset_nonce'); ?>
                <input type="hidden" name="tpow_reset_settings" value="1" />
            </form>

            <form id="tpow-reset-styles-form" method="post" action="" style="display:none;">
                <?php wp_nonce_field('tpow_reset_style_settings_nonce', 'tpow_reset_style_nonce'); ?>
                <input type="hidden" name="tpow_reset_style_settings" value="1" />
            </form>

            <script>
            (function () {
                var nonce = <?php echo wp_json_encode(wp_create_nonce('tpow_test_connection')); ?>;
                var btn    = document.getElementById('tpow-test-btn');
                var result = document.getElementById('tpow-test-result');

                btn.addEventListener('click', function () {
                    btn.disabled   = true;
                    result.style.color = '#666';
                    result.textContent = '<?php esc_html_e('Testing…', 'capconnect-for-wp'); ?>';

                    var instanceUrl = document.querySelector('input[name="tpow_instance_url"]').value;
                    var siteKey = document.querySelector('input[name="tpow_site_key"]').value;
                    var secret = document.querySelector('input[name="tpow_secret"]').value;

                    var body = 'action=tpow_test_connection&nonce=' + encodeURIComponent(nonce) +
                               '&instance_url=' + encodeURIComponent(instanceUrl) +
                               '&site_key=' + encodeURIComponent(siteKey) +
                               '&secret=' + encodeURIComponent(secret);

                    fetch(ajaxurl, {
                        method:  'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body:    body,
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
            <script>
            jQuery(function ($) {
                // Tab switching logic
                var $tabs = $('.tpow-nav-tab-wrapper .nav-tab');
                var $tabContents = $('.tpow-tab-content');

                function switchTab(tabId) {
                    // Update active tab header class
                    $tabs.removeClass('nav-tab-active');
                    $tabs.filter('[data-tab="' + tabId + '"]').addClass('nav-tab-active');

                    // Show corresponding content, hide others
                    $tabContents.hide();
                    $('#tpow-tab-' + tabId).show();

                    // Save state to sessionStorage & update window hash
                    sessionStorage.setItem('tpow_active_tab', tabId);
                    window.location.hash = tabId;
                }

                $tabs.on('click', function (e) {
                    e.preventDefault();
                    var tabId = $(this).data('tab');
                    switchTab(tabId);
                });

                // Determine initial tab on page load
                var initialTab = 'connection';
                var hash = window.location.hash.substring(1);
                var storedTab = sessionStorage.getItem('tpow_active_tab');

                if (hash && $('#tpow-tab-' + hash).length) {
                    initialTab = hash;
                } else if (storedTab && $('#tpow-tab-' + storedTab).length) {
                    initialTab = storedTab;
                }

                // Dynamic styling tab visibility based on Verification Mode
                var $modeSelect = $('select[name="tpow_mode"]');
                function toggleStylingTab() {
                    var isWidget = $modeSelect.val() === 'widget';
                    var $stylingTab = $('.tpow-nav-tab-wrapper .nav-tab[data-tab="styling"]');
                    
                    if (isWidget) {
                        $stylingTab.show();
                    } else {
                        $stylingTab.hide();
                        // If current active tab is styling but styling is hidden, fall back to connection tab
                        var activeTab = $('.tpow-nav-tab-wrapper .nav-tab-active').data('tab');
                        if (activeTab === 'styling') {
                            switchTab('connection');
                        }
                    }
                }

                $modeSelect.on('change', toggleStylingTab);
                
                // Initialize styling tab visibility first, then switch to initial tab
                toggleStylingTab();
                
                // If initialTab is 'styling' but it's not a widget mode, force it to 'connection'
                if (initialTab === 'styling' && $modeSelect.val() !== 'widget') {
                    initialTab = 'connection';
                }
                
                switchTab(initialTab);

                // Reset confirmation
                $('#tpow-reset-trigger-btn').on('click', function (e) {
                    e.preventDefault();
                    if (confirm('<?php echo esc_js(__('Are you sure you want to reset all settings? This will clear all configuration and styles.', 'capconnect-for-wp')); ?>')) {
                        $('#tpow-reset-form').submit();
                    }
                });

                // Reset styles confirmation
                $('#tpow-reset-styles-trigger-btn').on('click', function (e) {
                    e.preventDefault();
                    if (confirm('<?php echo esc_js(__('Are you sure you want to reset all style settings to defaults?', 'capconnect-for-wp')); ?>')) {
                        $('#tpow-reset-styles-form').submit();
                    }
                });
            });
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

    public function renderAlertEmailField(): void
    {
        $value = get_option('tpow_alert_email', '');
        echo '<input type="email" name="tpow_alert_email" value="' . esc_attr($value) . '" class="regular-text" placeholder="admin@example.com" />';
        echo '<p class="description">' . esc_html__('Get notified by email if the connection to your Cap server fails (either during the hourly check or on a frontend verification attempt). Leave empty to disable alerts.', 'capconnect-for-wp') . '</p>';
    }

    public function renderHideAttributionField(): void
    {
        $value = (bool) get_option('tpow_hide_attribution', false);
        echo '<label><input type="checkbox" name="tpow_hide_attribution" value="1"' . checked($value, true, false) . ' /> ';
        echo esc_html__('Hide the "Cap" link displayed in the bottom-right corner of the widget.', 'capconnect-for-wp') . '</label>';
    }

    public function renderFormsSectionDescription(): void
    {
        echo '<p class="description">' . esc_html__('Select which WordPress forms you want to protect using Cap.', 'capconnect-for-wp') . '</p>';
    }

    public function renderCheckboxField(array $args): void
    {
        $field = $args['field'];
        $label = $args['label'];
        $value = (bool) get_option($field, true);

        $disabled = false;

        if ($field === 'tpow_protect_woocommerce' && ! class_exists('WooCommerce')) {
            $disabled = true;
        } elseif ($field === 'tpow_protect_gravityforms' && ! class_exists('GFForms')) {
            $disabled = true;
        }

        if ($disabled) {
            echo '<input type="hidden" name="' . esc_attr($field) . '" value="' . ($value ? '1' : '0') . '" />';
            echo '<label><input type="checkbox" value="1"' . checked($value, true, false) . ' disabled="disabled" /> ';
        } else {
            echo '<label><input type="checkbox" name="' . esc_attr($field) . '" value="1"' . checked($value, true, false) . ' /> ';
        }
        echo esc_html($label) . '</label>';
    }

    public function enqueueAdminScripts(): void
    {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script(
            'wp-color-picker',
            'jQuery(function($){ $(".tpow-color-picker").wpColorPicker(); });'
        );

        $custom_css = '
            input[type="checkbox"]:disabled {
                filter: grayscale(100%);
                opacity: 0.5;
                cursor: not-allowed;
            }
        ';
        wp_add_inline_style('wp-color-picker', $custom_css);
    }

    public function renderStylingSectionDescription(): void
    {
        echo '<div id="tpow-styling-section-desc"></div>';
    }

    public function renderWidgetStylesField(): void
    {
        $bg = get_option('tpow_background', '');
        $color = get_option('tpow_color', '');
        $border = get_option('tpow_border_color', '');
        $radius = get_option('tpow_border_radius', 8);
        ?>
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Background Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_background" value="<?php echo esc_attr($bg); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Text Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_color" value="<?php echo esc_attr($color); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Border Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_border_color" value="<?php echo esc_attr($border); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Border Radius (px)', 'capconnect-for-wp'); ?></label>
                <input type="number" name="tpow_border_radius" value="<?php echo esc_attr((string) $radius); ?>" min="0" max="100" class="small-text" />
            </div>
        </div>
        <?php
    }

    public function renderCheckboxStylesField(): void
    {
        $bg = get_option('tpow_checkbox_background', '');
        $checkmark = get_option('tpow_checkbox_checkmark_color', '#374151');
        $color = get_option('tpow_checkbox_border_color', '');
        $style = get_option('tpow_checkbox_border_style', 'solid');
        $width = get_option('tpow_checkbox_border_width', 2);
        $radius = get_option('tpow_checkbox_border_radius', 5);
        ?>
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Checkbox Background', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_checkbox_background" value="<?php echo esc_attr($bg); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Checkmark Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_checkbox_checkmark_color" value="<?php echo esc_attr($checkmark); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div style="border-left: 1px solid #ccc; padding-left: 16px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap;">
                <div>
                    <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Border Color', 'capconnect-for-wp'); ?></label>
                    <input type="text" name="tpow_checkbox_border_color" value="<?php echo esc_attr($color); ?>" class="tpow-color-picker" data-default-color="" />
                </div>
                <div>
                    <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Border Style', 'capconnect-for-wp'); ?></label>
                    <select name="tpow_checkbox_border_style">
                        <?php
                        $styles = ['solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset', 'none'];
                        foreach ($styles as $s) {
                            echo '<option value="' . esc_attr($s) . '"' . selected($style, $s, false) . '>' . esc_html($s) . '</option>';
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Border Width (px)', 'capconnect-for-wp'); ?></label>
                    <input type="number" name="tpow_checkbox_border_width" value="<?php echo esc_attr((string) $width); ?>" min="0" max="20" class="small-text" />
                </div>
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Checkbox Border Radius (px)', 'capconnect-for-wp'); ?></label>
                <input type="number" name="tpow_checkbox_border_radius" value="<?php echo esc_attr((string) $radius); ?>" min="0" max="50" class="small-text" />
            </div>
        </div>
        <?php
    }

    public function renderSpinnerStylesField(): void
    {
        $bg = get_option('tpow_spinner_background', '');
        $color = get_option('tpow_spinner_color', '#374151');
        ?>
        <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap;">
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Spinner Background Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_spinner_background" value="<?php echo esc_attr($bg); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
            <div>
                <label style="display: block; font-size: 11px; color: #666; margin-bottom: 4px;"><?php esc_html_e('Spinner Color', 'capconnect-for-wp'); ?></label>
                <input type="text" name="tpow_spinner_color" value="<?php echo esc_attr($color); ?>" class="tpow-color-picker" data-default-color="" />
            </div>
        </div>
        <?php
    }

    public function sanitizeHexColor($value): string
    {
        if (empty($value)) {
            return '';
        }
        $hex = sanitize_hex_color($value);
        return $hex !== null ? $hex : '';
    }

    public function sanitizeBorderStyle($value): string
    {
        $allowed = ['solid', 'dashed', 'dotted', 'double', 'groove', 'ridge', 'inset', 'outset', 'none'];
        return in_array($value, $allowed, true) ? $value : 'solid';
    }

    public function sanitizeBorderWidth($value): int
    {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        if ($val === false || $val < 0) {
            return 2;
        }
        return $val;
    }

    public function sanitizeWidgetBorderRadius($value): int
    {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        if ($val === false || $val < 0) {
            return 8;
        }
        return $val;
    }

    public function sanitizeCheckboxBorderRadius($value): int
    {
        $val = filter_var($value, FILTER_VALIDATE_INT);
        if ($val === false || $val < 0) {
            return 5;
        }
        return $val;
    }

    public function handleResetSettings(): void
    {
        if (! isset($_POST['tpow_reset_settings'])) {
            return;
        }

        check_admin_referer('tpow_reset_settings_nonce', 'tpow_reset_nonce');

        if (! current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'capconnect-for-wp'));
        }

        $options_to_delete = [
            'tpow_instance_url',
            'tpow_site_key',
            'tpow_secret',
            'tpow_timeout',
            'tpow_fail_open',
            'tpow_alert_email',
            'tpow_alert_sent',
            'tpow_connection_failed_notice',
            'tpow_hide_attribution',
            'tpow_mode',
            'tpow_background',
            'tpow_color',
            'tpow_border_color',
            'tpow_border_radius',
            'tpow_checkbox_background',
            'tpow_checkbox_checkmark_color',
            'tpow_checkbox_border_color',
            'tpow_checkbox_border_style',
            'tpow_checkbox_border_width',
            'tpow_checkbox_border_radius',
            'tpow_spinner_color',
            'tpow_spinner_background',
            'tpow_protect_login',
            'tpow_protect_register',
            'tpow_protect_lostpassword',
            'tpow_protect_comments',
            'tpow_protect_woocommerce',
            'tpow_protect_gravityforms',
            'tpow_initial_state_label',
            'tpow_initial_state_label_translations',
            'tpow_verifying_label',
            'tpow_verifying_label_translations',
            'tpow_solved_label',
            'tpow_solved_label_translations',
            'tpow_required_label',
            'tpow_required_label_translations',
            'tpow_verified_aria_label',
            'tpow_verified_aria_label_translations',
            'tpow_verifying_aria_label',
            'tpow_verifying_aria_label_translations',
            'tpow_verify_aria_label',
            'tpow_verify_aria_label_translations',
            'tpow_error_label',
            'tpow_error_label_translations',
            'tpow_error_aria_label',
            'tpow_error_aria_label_translations',
            'tpow_wasm_disabled_label',
            'tpow_wasm_disabled_label_translations',
            'tpow_troubleshoot_label',
            'tpow_troubleshoot_label_translations',
        ];

        foreach ($options_to_delete as $opt) {
            delete_option($opt);
        }

        wp_safe_redirect(add_query_arg('settings-updated', 'reset', admin_url('options-general.php?page=tpow-settings')));
        exit;
    }

    public function handleResetStyleSettings(): void
    {
        if (! isset($_POST['tpow_reset_style_settings'])) {
            return;
        }

        check_admin_referer('tpow_reset_style_settings_nonce', 'tpow_reset_style_nonce');

        if (! current_user_can('manage_options')) {
            wp_die(__('Unauthorized.', 'capconnect-for-wp'));
        }

        $options_to_delete = [
            'tpow_background',
            'tpow_color',
            'tpow_border_color',
            'tpow_border_radius',
            'tpow_checkbox_background',
            'tpow_checkbox_checkmark_color',
            'tpow_checkbox_border_color',
            'tpow_checkbox_border_style',
            'tpow_checkbox_border_width',
            'tpow_checkbox_border_radius',
            'tpow_spinner_color',
            'tpow_spinner_background',
            'tpow_hide_attribution',
        ];

        foreach ($options_to_delete as $opt) {
            delete_option($opt);
        }

        wp_safe_redirect(add_query_arg('settings-updated', 'reset-styles', admin_url('options-general.php?page=tpow-settings') . '#styling'));
        exit;
    }

    public static function checkConnection(string $instance_url = '', string $site_key = '', string $secret = ''): array
    {
        if ($instance_url === '') {
            $instance_url = (string) get_option('tpow_instance_url', '');
        }
        if ($site_key === '') {
            $site_key = (string) get_option('tpow_site_key', '');
        }
        if ($secret === '') {
            $secret = (string) get_option('tpow_secret', '');
        }

        if (empty($instance_url)) {
            return [
                'success' => false,
                'message' => __('No instance URL configured.', 'capconnect-for-wp'),
            ];
        }

        if (empty($site_key)) {
            return [
                'success' => false,
                'message' => __('No Site Key configured.', 'capconnect-for-wp'),
            ];
        }

        if (empty($secret)) {
            return [
                'success' => false,
                'message' => __('Secret Key is empty.', 'capconnect-for-wp'),
            ];
        }

        $endpoint = rtrim($instance_url, '/') . '/' . ltrim($site_key, '/');

        // Construct Origin and Referer headers from home_url()
        $origin = home_url();
        $parsed = wp_parse_url($origin);
        $origin_header = '';
        if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
            $origin_header = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $origin_header .= ':' . $parsed['port'];
            }
        }
        $referer_header = home_url('/');

        $headers = [
            'Content-Type' => 'application/json',
        ];
        if (!empty($origin_header)) {
            $headers['Origin'] = $origin_header;
            $headers['Referer'] = $referer_header;
        }

        // 1. Check challenge endpoint (verifies URL and Site Key)
        $url = rtrim($endpoint, '/') . '/challenge';

        $response = wp_remote_post($url, [
            'timeout'     => 10,
            'headers'     => $headers,
            'body'        => '{}',
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200 || empty($data['token'])) {
            $error_msg = '';
            if ($code === 403) {
                $error_msg = __('Forbidden (HTTP 403). The request was blocked. This can occur if "Block Non-Browser User Agents" is enabled on your Cap server, as connection checks from the WordPress backend do not use a browser User-Agent.', 'capconnect-for-wp');
            } elseif (is_array($data) && !empty($data['error'])) {
                $error_msg = $data['error'];
            } else {
                $error_msg = sprintf(
                    /* translators: 1: HTTP status code */
                    __('Server responded with HTTP %d — check your endpoint URL and Site Key.', 'capconnect-for-wp'),
                    $code
                );
            }
            return [
                'success' => false,
                'message' => $error_msg,
            ];
        }

        // Verify CORS header for restricted origin
        $allow_origin = wp_remote_retrieve_header($response, 'access-control-allow-origin');
        if (!empty($origin_header)) {
            if (empty($allow_origin) || ($allow_origin !== '*' && strcasecmp($allow_origin, $origin_header) !== 0)) {
                return [
                    'success' => false,
                    'message' => __('Unauthorized origin. The Cap server has restricted access to other domains.', 'capconnect-for-wp'),
                ];
            }
        }

        // 2. Check siteverify endpoint (verifies Secret Key)
        $verify_url = rtrim($endpoint, '/') . '/siteverify';
        $verify_response = wp_remote_post($verify_url, [
            'timeout' => 10,
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/json',
            ]),
            'body'    => wp_json_encode([
                'secret'   => $secret,
                'response' => $site_key . ':dummy:dummy',
            ]),
            'data_format' => 'body',
        ]);

        if (is_wp_error($verify_response)) {
            return [
                'success' => false,
                'message' => __('Failed to contact siteverify endpoint.', 'capconnect-for-wp') . ' ' . $verify_response->get_error_message(),
            ];
        }

        $verify_code = wp_remote_retrieve_response_code($verify_response);
        $verify_body = wp_remote_retrieve_body($verify_response);
        $verify_data = json_decode($verify_body, true);

        if ($verify_code === 404) {
            if (is_array($verify_data) && isset($verify_data['error']) && $verify_data['error'] === 'Token not found') {
                return [
                    'success' => true,
                    'message' => __('✓ Connection successful — Cap server is reachable and responding correctly.', 'capconnect-for-wp'),
                ];
            }
            return [
                'success' => false,
                'message' => __('Siteverify endpoint not found (HTTP 404). Check your instance URL.', 'capconnect-for-wp'),
            ];
        }

        if ($verify_code === 403) {
            $error_msg = __('Invalid Site Key or Secret Key.', 'capconnect-for-wp');
            if (is_array($verify_data) && !empty($verify_data['error'])) {
                $error_msg = $verify_data['error'];
            }
            return [
                'success' => false,
                'message' => $error_msg,
            ];
        }

        $error_msg = '';
        if (is_array($verify_data) && !empty($verify_data['error'])) {
            $error_msg = $verify_data['error'];
        } else {
            $error_msg = sprintf(
                /* translators: 1: HTTP status code */
                __('Siteverify responded with HTTP %d.', 'capconnect-for-wp'),
                $verify_code
            );
        }

        return [
            'success' => false,
            'message' => $error_msg,
        ];
    }

    public static function sendAlertEmail(string $errorMessage): void
    {
        // Set/update the persistent connection failure notice option
        update_option('tpow_connection_failed_notice', $errorMessage);

        $email = get_option('tpow_alert_email', '');
        if (empty($email)) {
            return;
        }

        // Prevent email spam by checking if alert was already sent for the current downtime
        if (get_option('tpow_alert_sent')) {
            return;
        }

        $subject = sprintf(
            '[%s] Cap Connection Failed',
            get_bloginfo('name')
        );

        $body = sprintf(
            "Hello,\n\nThe connection to your self-hosted Cap server failed.\n\nError details: %s\n\nPlease check your server and configuration.\n\nBest regards,\nCapConnect for WP",
            $errorMessage
        );

        $sent = wp_mail($email, $subject, $body);
        if ($sent) {
            update_option('tpow_alert_sent', time());
        }
    }

    public function runHourlyCheck(): void
    {
        $result = self::checkConnection();
        if ($result['success']) {
            $this->clearAlertSent();
            delete_option('tpow_connection_failed_notice');
        } else {
            self::sendAlertEmail($result['message']);
        }
    }

    public function clearAlertSent(): void
    {
        delete_option('tpow_alert_sent');
    }

    public function handleCredentialUpdate(): void
    {
        $this->clearAlertSent();
        $result = self::checkConnection();
        if ($result['success']) {
            delete_option('tpow_connection_failed_notice');
        } else {
            update_option('tpow_connection_failed_notice', $result['message']);
        }
    }

    public function displayConnectionFailedNotice(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $error = get_option('tpow_connection_failed_notice');
        if (empty($error)) {
            return;
        }

        $settings_url = admin_url('options-general.php?page=tpow-settings');
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e('CapConnect Alert:', 'capconnect-for-wp'); ?></strong>
                <?php
                echo sprintf(
                    /* translators: 1: error message, 2: settings link HTML */
                    esc_html__('Connection to the Cap server is failing. Error: %1$s. Please check your %2$s.', 'capconnect-for-wp'),
                    esc_html($error),
                    '<a href="' . esc_url($settings_url) . '">' . esc_html__('settings', 'capconnect-for-wp') . '</a>'
                );
                ?>
            </p>
        </div>
        <?php
    }

    public function handleTestConnection(): void
    {
        check_ajax_referer('tpow_test_connection', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized.', 'capconnect-for-wp')], 403);
        }

        $instance_url = isset($_POST['instance_url']) ? esc_url_raw(wp_unslash($_POST['instance_url'])) : '';
        $site_key     = isset($_POST['site_key']) ? sanitize_text_field(wp_unslash($_POST['site_key'])) : '';
        $secret       = isset($_POST['secret']) ? sanitize_text_field(wp_unslash($_POST['secret'])) : '';

        $result = self::checkConnection($instance_url, $site_key, $secret);
        if ($result['success']) {
            $this->clearAlertSent();
            delete_option('tpow_connection_failed_notice');
            wp_send_json_success($result);
        } else {
            update_option('tpow_connection_failed_notice', $result['message']);
            wp_send_json_error($result);
        }
    }

    public function handleReportFrontendError(): void
    {
        check_ajax_referer('tpow_report_error', 'nonce');

        $error_message = isset($_POST['error_message']) ? sanitize_text_field(wp_unslash($_POST['error_message'])) : '';

        if (! empty($error_message)) {
            self::sendAlertEmail($error_message);
            wp_send_json_success();
        }

        wp_send_json_error(['message' => __('Empty error message.', 'capconnect-for-wp')], 400);
    }

    public function renderLabelsSectionDescription(): void
    {
        echo '<p>' . esc_html__('Customize the text labels shown on the widget. Leave them empty to use the localized defaults based on WordPress language settings.', 'capconnect-for-wp') . '</p>';
    }

    public function renderLabelField(array $args): void
    {
        $field       = $args['field'];
        $placeholder = $args['placeholder'];
        $description = $args['description'];
        $value       = (string) get_option($field, '');

        echo '<input type="text" name="' . esc_attr($field) . '" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '" class="regular-text" />';
        echo '<p class="description">' . esc_html($description) . '</p>';
    }

    public static function getCurrentLanguage(): string
    {
        // 1. Check WPML
        $wpml_lang = apply_filters('wpml_current_language', null);
        if ($wpml_lang !== null && is_string($wpml_lang)) {
            return $wpml_lang;
        }

        // 2. Check Polylang
        if (function_exists('pll_current_language')) {
            $pll_lang = pll_current_language();
            if ($pll_lang) {
                return $pll_lang;
            }
        }

        // 3. Fallback to standard WordPress locale
        return get_locale();
    }
}
