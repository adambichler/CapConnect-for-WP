<?php
/**
 * Plugin Name:       CapConnect for WP
 * Plugin URI:        https://github.com/adambichler/CapConnect-for-WP
 * Description:       Integrates the open-source TryCap widget into WordPress comments, login, registration, lost password, WooCommerce checkout, and Gravity Forms.
 * Version:           1.3.1
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Adam Bichler
 * Author URI:        https://github.com/adambichler
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       capconnect-for-wp
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('CAPCONNECT_VERSION', '1.3.1');
define('CAPCONNECT_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CAPCONNECT_PLUGIN_URL', plugin_dir_url(__FILE__));

// Backward-compatible constants for integrations built against earlier versions.
define('TPOW_VERSION', CAPCONNECT_VERSION);
define('TPOW_PLUGIN_DIR', CAPCONNECT_PLUGIN_DIR);
define('TPOW_PLUGIN_URL', CAPCONNECT_PLUGIN_URL);

/**
 * Loads the translations for the plugin.
 */
function capconnect_load_textdomain(): void
{
    load_plugin_textdomain('capconnect-for-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('plugins_loaded', 'capconnect_load_textdomain');

/**
 * Loads translations for integrations that still use the legacy callback.
 */
function tpow_load_textdomain(): void
{
    capconnect_load_textdomain();
}

require_once CAPCONNECT_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// References the fork repository for automatic GitHub releases.
$capconnectUpdateCheckerUrl = 'https://github.com/adambichler/CapConnect-for-WP/';
$pucChecker = PucFactory::buildUpdateChecker(
    $capconnectUpdateCheckerUrl,
    __FILE__,
    'capconnect-for-wp'
);
$pucChecker->getVcsApi()->enableReleaseAssets();

require_once CAPCONNECT_PLUGIN_DIR . 'includes/class-cap-verifier.php';
require_once CAPCONNECT_PLUGIN_DIR . 'includes/class-cap-settings.php';
require_once CAPCONNECT_PLUGIN_DIR . 'includes/class-cap-widget.php';
require_once CAPCONNECT_PLUGIN_DIR . 'includes/class-cap-login-recovery.php';
require_once CAPCONNECT_PLUGIN_DIR . 'includes/class-cap-integrations.php';

foreach ([
    'Tpow_Settings'       => Capconnect_Settings::class,
    'Tpow_Widget'         => Capconnect_Widget::class,
    'Tpow_Verifier'       => Capconnect_Verifier::class,
    'Tpow_Login_Recovery' => Capconnect_Login_Recovery::class,
    'Tpow_Integrations'   => Capconnect_Integrations::class,
] as $legacyClass => $capconnectClass) {
    if (! class_exists($legacyClass, false)) {
        class_alias($capconnectClass, $legacyClass);
    }
}

(new Capconnect_Settings())->init();
(new Capconnect_Widget())->init();

add_action('plugins_loaded', function(): void {
    $loginRecovery = new Capconnect_Login_Recovery();
    $loginRecovery->init();
    (new Capconnect_Integrations($loginRecovery))->init();
});


register_activation_hook(__FILE__, 'capconnect_activate_plugin');
register_deactivation_hook(__FILE__, 'capconnect_deactivate_plugin');

function capconnect_activate_plugin(): void
{
    wp_clear_scheduled_hook('tpow_hourly_connection_check');

    if (! wp_next_scheduled('capconnect_hourly_connection_check')) {
        wp_schedule_event(time(), 'hourly', 'capconnect_hourly_connection_check');
    }
}

function capconnect_deactivate_plugin(): void
{
    wp_clear_scheduled_hook('capconnect_hourly_connection_check');
    wp_clear_scheduled_hook('tpow_hourly_connection_check');
}

/**
 * Activates the plugin for integrations that still call the legacy callback.
 */
function tpow_activate_plugin(): void
{
    capconnect_activate_plugin();
}

/**
 * Deactivates the plugin for integrations that still call the legacy callback.
 */
function tpow_deactivate_plugin(): void
{
    capconnect_deactivate_plugin();
}
