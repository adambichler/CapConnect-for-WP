<?php
/**
 * Plugin Name:       Cap CAPTCHA
 * Plugin URI:        https://github.com/oli217/wordpress-cap
 * Description:       Integrates Cap (self-hosted proof-of-work CAPTCHA) into WordPress comments, login, registration, and WooCommerce checkout.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            oliweb
 * Author URI:        https://github.com/oli217
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       wordpress-cap
 * Domain Path:       /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('WORDPRESSCAP_VERSION', '1.0.0');
define('WORDPRESSCAP_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WORDPRESSCAP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once WORDPRESSCAP_PLUGIN_DIR . 'includes/class-cap-verifier.php';
require_once WORDPRESSCAP_PLUGIN_DIR . 'includes/class-cap-settings.php';
require_once WORDPRESSCAP_PLUGIN_DIR . 'includes/class-cap-widget.php';
require_once WORDPRESSCAP_PLUGIN_DIR . 'includes/class-cap-integrations.php';

(new Cap_Settings())->init();
(new Cap_Widget())->init();
(new Cap_Integrations())->init();
