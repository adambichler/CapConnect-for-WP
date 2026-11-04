=== CapConnect for WP ===
Contributors: adambichler
Tags: captcha, spam, proof-of-work, comments, login
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.4.5
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Cap (self-hosted proof-of-work CAPTCHA) into WordPress comments, login, registration, lost password, WooCommerce checkout and customer accounts, and Gravity Forms.

== Description ==

CapConnect for WP integrates [TryCap](https://trycap.dev/) — the open-source version of Cap — into your WordPress site.

= Upstream compatibility and prefixes =

CapConnect for WP uses the `capconnect_*` prefix convention to align WordPress-specific integration points with the upstream Cap ecosystem where possible. The plugin is only partially compatible with the upstream repository because its settings, hooks, and form integrations are specific to WordPress.

The `capconnect_*` options, hooks, shortcodes, and `window.CAPCONNECT_CONFIG` are the preferred public interface. On upgrade, all known `tpow_*` options are migrated automatically. Legacy `tpow_*` hooks, `window.TPOW_CONFIG`, `[tpow_widget]`, and `[tpow_programmatic]` remain available for compatibility with previous versions.

Unlike traditional CAPTCHAs, Cap does not rely on third-party services or user interaction: it runs a small computation in the visitor's browser to prove they are human, without tracking or collecting personal data.

**External service — Cap server**

This plugin communicates with your own self-hosted Cap server to verify proof-of-work tokens. No data is sent to any third-party service. You control the Cap server entirely. See [github.com/tiagozip/cap](https://github.com/tiagozip/cap) for setup instructions.

**WebAssembly module**

The Cap widget uses WebAssembly (WASM) for proof-of-work. This plugin bundles the Cap widget 0.1.58 and both WASM solvers locally (`assets/wasm/cap_wasm_bg.wasm` and `assets/wasm/hashwx.wasm`) — no external CDN is required at runtime.

**Features:**

* Protects comment forms
* Protects login form
* Protects registration form
* Protects lost password and password reset forms
* Protects WooCommerce classic and Block Checkout, customer login and registration, and password recovery with independent controls
* Protects Gravity Forms (if Gravity Forms is active)
* Protects Forminator forms (if Forminator is active)
* Shortcode `[capconnect_widget]` for use in any page or form builder
* Shortcode `[capconnect_programmatic]` for programmatic Cap usage (headless mode)
* Settings page under **Settings > CapConnect**
* Fail-open mode: lets requests through if the Cap server is unreachable
* No external dependencies — uses native WordPress HTTP API and bundled WASM

**Requirements:**

A self-hosted Cap instance is required. See the [Cap documentation](https://github.com/tiagozip/cap) for setup instructions.

== Installation ==

1. Upload the `capconnect-for-wp` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings > CapConnect** and enter your Instance URL, Site Key, and Secret Key.

== Configuration ==

After activation, navigate to **Settings > CapConnect**. The settings are organized into the following tabs:

* **Connection Tab:**
    * **Verification Mode** — Toggle between visible widget and programmatic (invisible) mode.
    * **Instance URL** — URL of your Cap instance (e.g. `https://cap.example.com`).
    * **Site Key** — The site key from your Cap dashboard.
    * **Secret Key** — The secret key from your Cap dashboard.
    * **Timeout** — Seconds before abandoning the request to `/siteverify` (default: `5`).
    * **Fail Open** — Allow requests through when the Cap server is unreachable.
    * **Alert Email** — Email address to receive failure notifications. A cron job checks connection hourly, and frontend failures trigger real-time alerts.
* **Integrations Tab:**
    * Enable or disable protection for WordPress forms, Gravity Forms, Forminator, and WooCommerce.
* **WooCommerce Tab:**
    * Independently protect classic checkout, Block Checkout, My Account login, checkout login, My Account registration, checkout registration, post-checkout registration, lost password, and password reset.
    * Checkout protection applies to guests. Checkout registration can be protected even when order protection is off; enabling both requires only one verification per attempt.
    * WooCommerce protection is off until the integration switch is enabled. All individual areas are selected by default; you can disable them independently. Turning off the integration switch preserves individual settings.
    * Express guest payments require a valid token when protection applies. Additional account or social-login plugins require separate integrations.
* **Styling Tab:**
    * Customize widget colors, borders, checkmark and spinner styles, and integration error message colors.
    * **Hide Attribution Link** — Toggle to hide the "Cap" link at the bottom-right of the widget.
* **Labels Tab:**
    * Customize widget status, validation, accessibility, and troubleshooting text, with per-language translations.

= Administrator login recovery =

When login protection is enabled, administrators can request a captcha recovery email from the WordPress login form. The email is sent only to the address stored on an eligible administrator account, not to the configured alert address.

The email link is valid for 15 minutes. After explicit confirmation it creates a captcha exception for that account and site for up to 10 minutes. It does not log the administrator in: the correct username or email address and password are still required. The exception is consumed by the first successful login. Requests are limited to three emails per administrator account and ten requests per direct IP address per hour, and only the latest link remains valid.

Recovery requires working WordPress email delivery. HTTPS is strongly recommended so the recovery cookie can use the `Secure` attribute. Test changed Cap credentials before saving them when login protection is active. Untested values can still be saved, but the settings page displays a lockout warning.

As a mail-independent emergency fallback, temporarily add `define('CAPCONNECT_DISABLE_LOGIN_CAPTCHA', true);` to `wp-config.php`. This disables Cap only for the WordPress login form. Remove the constant immediately after access has been restored.

= Two-factor authentication compatibility =

Login protection is compatible with the two-factor authentication flows of Wordfence, the official Two-Factor plugin, and All In One WP Security (AIOS). CapConnect verifies the visitor once during the login transaction and preserves the verification while the second-factor form is displayed.

== Frequently Asked Questions ==

= Does this plugin work without a Cap server? =

No. You need to run your own Cap instance. See [github.com/tiagozip/cap](https://github.com/tiagozip/cap) for setup instructions.

= Does Cap track users or collect personal data? =

Cap itself is a proof-of-work system: it runs a computation in the browser and does not collect personal data or make requests to third-party servers. The optional administrator login recovery flow uses a short-lived cookie after recovery is explicitly confirmed.

= Is WooCommerce support automatic? =

Enable **Protect WooCommerce** under **Settings > CapConnect > Integrations**, review the selected areas in the WooCommerce tab, and save. All WooCommerce areas are selected by default and can be disabled independently.

= Are Gravity Forms and Forminator supported? =

Yes. If either plugin is active and its integration is enabled in **Settings > CapConnect > Integrations**, CapConnect adds verification to its forms and rejects submissions without a valid token.

= What is the shortcode? =

Use `[capconnect_widget]` to embed the Cap widget in any page or custom form.

The legacy `[tpow_widget]` and `[tpow_programmatic]` shortcodes continue to work for existing content.

== Changelog ==

= 1.4.5 =
* Added independent protection settings for WooCommerce checkout, Block Checkout, account login and registration, and password recovery.
* Added WooCommerce token validation for guest checkout and registration flows.
* Fixed the widget attribution link display setting.

= 1.4.1 =
* Updated the bundled Cap widget to 0.1.58 and added local HashWX WASM support.
* Preserved the optional shortcode CSP nonce for the current Cap widget script and styles.

= 1.4.0 =
* Added compatibility with Wordfence, the official Two-Factor plugin, and AIOS two-factor login flows.
* Preserved the short-lived Cap verification across multi-step authentication and consumed it after successful login.

= 1.3.1 =
* Added a connection test button in the admin – checks that the Cap endpoint is reachable and responds correctly

= 1.3.0 =
* New: programmatic verification mode (invisible) – silent PoW solving in the background, no widget shown to the user
* Admin option "Verification Mode": toggle between visible widget and programmatic mode

* Renaming: plugin published under the name CapConnect for WP (slug capconnect-for-wp)
* Automatic updates via GitHub Releases of the CapConnect for WP fork (Plugin Update Checker v5.6)

= 1.2.0 =
* Programmatic mode: injection of window.CAPCONNECT_CONFIG (apiEndpoint, tokenField) via enqueueAssets()
* New shortcode [capconnect_programmatic]: loads assets and inserts a hidden field ready to receive the solved token via new Cap({...})

= 1.1.0 =
* Widget i18n: all Cap widget labels are now translated via WordPress translation system (data-cap-i18n-* attributes)
* New setting: Hide Attribution Link — hides the "Cap" link in the bottom-right corner of the widget

= 1.0.0 =
* Initial release
* Comment, login, registration, and WooCommerce checkout protection
* Shortcode `[capconnect_widget]`
* Settings page under Settings > CapConnect
