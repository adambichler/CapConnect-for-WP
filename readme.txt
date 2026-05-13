=== tilivier Proof-of-Work for Cap ===
Contributors: tilivier
Tags: captcha, spam, proof-of-work, comments, login
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 8.2
Stable tag: 1.1.0
License: MIT
License URI: https://opensource.org/licenses/MIT

Integrates Cap (self-hosted proof-of-work CAPTCHA) into WordPress comments, login, registration, WooCommerce checkout, and Gravity Forms.

== Description ==

tilivier Proof-of-Work for Cap integrates [Cap](https://github.com/tiagozip/cap) — a self-hosted, privacy-friendly proof-of-work CAPTCHA — into your WordPress site.

Unlike traditional CAPTCHAs, Cap does not rely on third-party services or user interaction: it runs a small computation in the visitor's browser to prove they are human, without tracking or collecting personal data.

**External service — Cap server**

This plugin communicates with your own self-hosted Cap server to verify proof-of-work tokens. No data is sent to any third-party service. You control the Cap server entirely. See [github.com/tiagozip/cap](https://github.com/tiagozip/cap) for setup instructions.

**WebAssembly module**

The Cap widget uses a WebAssembly (WASM) module to perform the proof-of-work computation in the browser. This plugin bundles the WASM file locally (`assets/wasm/cap_wasm_bg.wasm`) — no external CDN is required at runtime.

**Features:**

* Protects comment forms
* Protects login form
* Protects registration form
* Protects WooCommerce checkout (if WooCommerce is active)
* Protects Gravity Forms (if Gravity Forms is active)
* Shortcode `[tpow_widget]` for use in any page or form builder
* Settings page under **Settings > PoW for Cap**
* Fail-open mode: lets requests through if the Cap server is unreachable
* No external dependencies — uses native WordPress HTTP API and bundled WASM

**Requirements:**

A self-hosted Cap instance is required. See the [Cap documentation](https://github.com/tiagozip/cap) for setup instructions.

== Installation ==

1. Upload the `tilivier-pow-for-cap` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings > PoW for Cap** and enter your Cap endpoint URL and secret key.

== Configuration ==

After activation, navigate to **Settings > PoW for Cap**:

* **Endpoint URL** — Full URL of your Cap instance, including the site key (e.g. `https://cap.example.com/your-site-key/`)
* **Secret Key** — The secret key from your Cap dashboard
* **Token Field Name** — Name of the hidden field injected by the widget (default: `cap-token`)
* **Timeout** — Seconds before abandoning the request to `/siteverify` (default: `5`)
* **Fail Open** — Allow requests through when the Cap server is unreachable

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

= 1.1.0 =
* Widget i18n: all Cap widget labels are now translated via WordPress translation system (data-cap-i18n-* attributes)
* New setting: Hide Attribution Link — hides the "Cap" link in the bottom-right corner of the widget

= 1.0.0 =
* Initial release
* Comment, login, registration, and WooCommerce checkout protection
* Shortcode `[tpow_widget]`
* Settings page under Settings > PoW for Cap
