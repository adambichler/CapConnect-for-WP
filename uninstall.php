<?php

/**
 * Fired when the plugin is uninstalled.
 * Removes CapConnect settings and their legacy equivalents.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('capconnect_hourly_connection_check');
wp_clear_scheduled_hook('tpow_hourly_connection_check');

$option_suffixes = [
    'endpoint', 'instance_url', 'site_key', 'secret', 'token_field', 'timeout', 'fail_open',
    'alert_email', 'alert_sent', 'connection_failed_notice', 'hide_attribution', 'mode',
    'background', 'color', 'border_color', 'border_radius', 'checkbox_background',
    'checkbox_checkmark_color', 'checkbox_border_color', 'checkbox_border_style',
    'checkbox_border_width', 'checkbox_border_radius', 'spinner_color', 'spinner_background',
    'error_background', 'error_color', 'error_border_color', 'protect_login', 'protect_register',
    'protect_lostpassword', 'protect_comments', 'protect_woocommerce', 'protect_gravityforms',
    'protect_forminator', 'initial_state_label', 'initial_state_label_translations',
    'verifying_label', 'verifying_label_translations', 'solved_label', 'solved_label_translations',
    'required_label', 'required_label_translations', 'verified_aria_label',
    'verified_aria_label_translations', 'verifying_aria_label',
    'verifying_aria_label_translations', 'verify_aria_label', 'verify_aria_label_translations',
    'error_label', 'error_label_translations', 'verification_failed_label',
    'verification_failed_label_translations', 'error_aria_label',
    'error_aria_label_translations', 'wasm_disabled_label', 'wasm_disabled_label_translations',
    'troubleshoot_label', 'troubleshoot_label_translations',
];

foreach (['capconnect_', 'tpow_'] as $prefix) {
    foreach ($option_suffixes as $suffix) {
        delete_option($prefix . $suffix);
    }
}

delete_option('capconnect_prefix_migration_version');
