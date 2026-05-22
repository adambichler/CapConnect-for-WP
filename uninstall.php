<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin options from the database.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('tpow_endpoint');
delete_option('tpow_secret');
delete_option('tpow_token_field');
delete_option('tpow_timeout');
delete_option('tpow_fail_open');
delete_option('tpow_hide_attribution');
delete_option('tpow_mode');
