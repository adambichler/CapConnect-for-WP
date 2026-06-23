<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin options from the database.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tpow_endpoint');
delete_option('tpow_instance_url');
delete_option('tpow_site_key');
delete_option('tpow_secret');
delete_option('tpow_token_field');
delete_option('tpow_timeout');
delete_option('tpow_fail_open');
delete_option('tpow_hide_attribution');
delete_option('tpow_mode');

delete_option('tpow_background');
delete_option('tpow_color');
delete_option('tpow_border_color');
delete_option('tpow_checkbox_background');
delete_option('tpow_spinner_color');
delete_option('tpow_spinner_background');
delete_option('tpow_checkbox_border_color');
delete_option('tpow_checkbox_border_style');
delete_option('tpow_checkbox_border_width');
delete_option('tpow_border_radius');
delete_option('tpow_checkbox_border_radius');
delete_option('tpow_checkbox_checkmark_color');
delete_option('tpow_protect_login');
delete_option('tpow_protect_register');
delete_option('tpow_protect_lostpassword');
delete_option('tpow_protect_comments');
delete_option('tpow_protect_woocommerce');
delete_option('tpow_protect_gravityforms');
