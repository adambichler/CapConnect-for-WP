<?php
/**
 * Plugin Name:       CapConnect for WP
 * Plugin URI:        https://github.com/adambichler/CapConnect-for-WP
 * Description:       Integrates the open-source TryCap widget into WordPress comments, login, registration, and WooCommerce checkout.
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

define('TPOW_VERSION', '1.3.1');
define('TPOW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TPOW_PLUGIN_URL', plugin_dir_url(__FILE__));

/**
 * Lädt die Übersetzungen für das Plugin.
 */
function tpow_load_textdomain(): void
{
    load_plugin_textdomain('capconnect-for-wp', false, dirname(plugin_basename(__FILE__)) . '/languages');
}

add_action('plugins_loaded', 'tpow_load_textdomain');

require_once TPOW_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

// Verweist auf das Fork-Repository für automatische GitHub-Releases.
$tpowUpdateCheckerUrl = 'https://github.com/adambichler/CapConnect-for-WP/';
$pucChecker = PucFactory::buildUpdateChecker(
    $tpowUpdateCheckerUrl,
    __FILE__,
    'capconnect-for-wp'
);
$pucChecker->getVcsApi()->enableReleaseAssets();

require_once TPOW_PLUGIN_DIR . 'includes/class-cap-verifier.php';
require_once TPOW_PLUGIN_DIR . 'includes/class-cap-settings.php';
require_once TPOW_PLUGIN_DIR . 'includes/class-cap-widget.php';
require_once TPOW_PLUGIN_DIR . 'includes/class-cap-integrations.php';

(new Tpow_Settings())->init();
(new Tpow_Widget())->init();
(new Tpow_Integrations())->init();
