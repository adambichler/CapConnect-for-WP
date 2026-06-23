=== CapConnect for WP ===
Contributors: adambichler
Tags: captcha, spam, proof-of-work, comments, login
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.3.1
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrates Cap (self-hosted proof-of-work CAPTCHA) into WordPress comments, login, registration, lost password, WooCommerce checkout, and Gravity Forms.

== Description ==

CapConnect for WP integrates [TryCap](https://trycap.dev/) — the open-source version of Cap — into your WordPress site.

Unlike traditional CAPTCHAs, Cap does not rely on third-party services or user interaction: it runs a small computation in the visitor's browser to prove they are human, without tracking or collecting personal data.

**External service — Cap server**

This plugin communicates with your own self-hosted Cap server to verify proof-of-work tokens. No data is sent to any third-party service. You control the Cap server entirely. See [github.com/tiagozip/cap](https://github.com/tiagozip/cap) for setup instructions.

**WebAssembly module**

The Cap widget uses a WebAssembly (WASM) module to perform the proof-of-work computation in the browser. This plugin bundles the WASM file locally (`assets/wasm/cap_wasm_bg.wasm`) — no external CDN is required at runtime.

**Features:**

* Protects comment forms
* Protects login form
* Protects registration form
* Protects lost password and password reset forms
* Protects WooCommerce checkout (if WooCommerce is active)
* Protects Gravity Forms (if Gravity Forms is active)
* Shortcode `[tpow_widget]` for use in any page or form builder
* Shortcode `[tpow_programmatic]` for programmatic Cap usage (headless mode)
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

After activation, navigate to **Settings > CapConnect**. The settings are divided into three tabs:

* **Connection Tab:**
    * **Verification Mode** — Toggle between visible widget and programmatic (invisible) mode.
    * **Instance URL** — URL of your Cap instance (e.g. `https://cap.example.com`).
    * **Site Key** — The site key from your Cap dashboard.
    * **Secret Key** — The secret key from your Cap dashboard.
    * **Timeout** — Seconds before abandoning the request to `/siteverify` (default: `5`).
    * **Fail Open** — Allow requests through when the Cap server is unreachable.
* **Forms Tab:**
    * Enable or disable protection for: Login Form, Registration Form, Lost Password Form, Comments Form, WooCommerce Checkout, and Gravity Forms.
* **Styling Tab:**
    * Customize background colors, text colors, borders, checkmark, and spinner styles for the visible widget (only active in Widget mode).
    * **Hide Attribution Link** — Toggle to hide the "Cap" link at the bottom-right of the widget.

== Frequently Asked Questions ==

= Does this plugin work without a Cap server? =

No. You need to run your own Cap instance. See [github.com/tiagozip/cap](https://github.com/tiagozip/cap) for setup instructions.

= Does Cap track users or collect personal data? =

No. Cap is a proof-of-work system: it runs a computation in the browser and does not collect personal data, set cookies, or make requests to third-party servers.

= Is WooCommerce support automatic? =

Yes. If WooCommerce is active when the plugin loads, the Cap widget is automatically added to the checkout billing form.

= Is Gravity Forms support automatic? =

Yes. If Gravity Forms is active, the Cap widget is automatically prepended to the submit button of every Gravity Forms form. Validation errors are displayed as a form-level message.

= What is the shortcode? =

Use `[tpow_widget]` to embed the Cap widget in any page or custom form.

== Changelog ==

= 1.3.1 =
* Added a connection test button in the admin – checks that the Cap endpoint is reachable and responds correctly

= 1.3.0 =
* New: programmatic verification mode (invisible) – silent PoW solving in the background, no widget shown to the user
* Admin option "Verification Mode": toggle between visible widget and programmatic mode

* Renaming: plugin published under the name CapConnect for WP (slug capconnect-for-wp)
* Automatic updates via GitHub Releases of the CapConnect for WP fork (Plugin Update Checker v5.6)

= 1.2.0 =
* Programmatic mode: injection of window.TPOW_CONFIG (apiEndpoint, tokenField) via enqueueAssets()
* New shortcode [tpow_programmatic]: loads assets and inserts a hidden field ready to receive the solved token via new Cap({...})

= 1.1.0 =
* Widget i18n: all Cap widget labels are now translated via WordPress translation system (data-cap-i18n-* attributes)
* New setting: Hide Attribution Link — hides the "Cap" link in the bottom-right corner of the widget

= 1.0.0 =
* Initial release
* Comment, login, registration, and WooCommerce checkout protection
* Shortcode `[tpow_widget]`
* Settings page under Settings > CapConnect
