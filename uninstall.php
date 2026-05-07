<?php
/**
 * Fired when the plugin is uninstalled.
 * Removes all plugin options from the database.
 */

if (! defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('cap_endpoint');
delete_option('cap_secret');
delete_option('cap_token_field');
delete_option('cap_timeout');
delete_option('cap_fail_open');
