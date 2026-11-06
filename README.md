# CapConnect for WP

WordPress plugin to integrate [TryCap](https://trycap.dev/) — the open-source version of Cap — into WordPress forms.

No external dependencies: everything relies on native WordPress APIs. The WebAssembly module is bundled locally in the plugin.

## Upstream compatibility and prefixes

CapConnect for WP follows the `capconnect_*` prefix convention to align its WordPress-specific integration points with the upstream Cap ecosystem where possible. It remains only partially compatible with the upstream repository because WordPress settings, hooks, and form integrations are specific to this plugin.

The current `capconnect_*` options, hooks, shortcodes, and `window.CAPCONNECT_CONFIG` are the preferred public interface. During an upgrade, all known `tpow_*` options are migrated automatically. Existing `tpow_*` hooks and `window.TPOW_CONFIG` remain available as compatibility aliases, and the legacy `[tpow_widget]` and `[tpow_programmatic]` shortcodes continue to work.

---

## Prerequisites

- PHP 8.2+
- WordPress 6.4+
- A self-hosted Cap instance

---

## Installation

1. Copy the `capconnect-for-wp/` folder to `wp-content/plugins/`
2. Activate the plugin from **Plugins** in the WordPress administration panel
3. Go to **Settings > CapConnect** and fill in the Instance URL, Site Key, and Secret Key

The plugin includes the version-pinned Cap widget 0.1.58 and both proof-of-work solvers (SHA-256 and HashWX) as local JS/WASM assets. No extra build step, runtime CDN, or download is required.

---

## Configuration

Access **Settings > CapConnect** in the WordPress administration panel. The options are divided into **Connection**, **Integrations**, **WooCommerce** (when WooCommerce is active), **Styling**, **Debug**, and **Labels** tabs.

### Connection

| Field             | Description                                                                                                             | Default            |
| ----------------- | ----------------------------------------------------------------------------------------------------------------------- | ------------------ |
| Verification Mode | Toggle between **Widget (visible)** (shows the Cap widget) and **Programmatic (invisible)** (silent background solving) | `Widget (visible)` |
| Instance URL      | URL of your self-hosted Cap instance (e.g., `https://cap.example.com`)                                                  | —                  |
| Site Key          | Site key configured on your Cap instance                                                                                | —                  |
| Secret Key        | Secret key generated in the Cap dashboard (never expose on the client side)                                             | —                  |
| Timeout (seconds) | Delay before abandoning the request to `/siteverify`                                                                    | `5`                |
| Fail Open         | If checked, lets the request through in case of communication error with Cap                                            | unchecked          |
| Alert Email       | Email address to receive notifications after a connection or configuration problem is confirmed. Leave blank to disable alerts. | —                  |
| Debug log retention (days) | Retention period for diagnostic entries, from 1 to 3650 days. | `30` |

#### Administrator login recovery

When login protection is enabled, the WordPress login form includes an administrator captcha recovery link. An administrator can request an email at the address stored on their WordPress user account. The email link is valid for 15 minutes and, after explicit confirmation, creates a captcha exception for that account for up to 10 minutes.

The recovery link does not authenticate the administrator. The correct username or email address and password are still required. The exception is bound to one administrator account and site and is consumed by its first successful login. Requests are limited to three emails per administrator account and ten requests per direct IP address per hour. Only the latest email link remains valid, and opening the link without confirming it does not consume it.

Recovery requires working WordPress email delivery and a valid email address on the administrator account. The **Alert Email** setting is not used for account recovery. HTTPS is strongly recommended so the short-lived recovery cookie can use the `Secure` attribute.

#### Two-factor authentication compatibility

Login protection is compatible with the two-factor authentication flows of Wordfence, the official Two-Factor plugin, and All In One WP Security (AIOS). CapConnect verifies the visitor once during the login transaction and preserves the short-lived verification while the second-factor form is displayed.

Before saving changed Cap credentials while login protection is active, use **Test connection**. The plugin warns before untested credentials are saved but does not block the save, because temporary outages and planned migrations must remain possible.

For a mail-independent emergency fallback, add the following temporary constant to `wp-config.php`:

```php
define('CAPCONNECT_DISABLE_LOGIN_CAPTCHA', true);
```

This disables Cap only on the WordPress login form. All other protected forms remain unchanged. Remove the constant immediately after administrator access and the Cap configuration have been restored.

#### Fail-open mode & Health Check

By default, any communication error with the Cap instance (network, timeout, 5xx error) blocks the request. Enabling **Fail Open** reverses this behavior: infrastructure errors will let the request through.

**An explicitly invalid token (`success: false`) is always rejected**, regardless of this setting.

The plugin checks both the challenge and verification endpoints hourly. Verification request errors schedule an additional check. Browser errors are recorded separately; a browser "Failed to fetch" error can schedule a server check, but browser errors alone do not trigger alerts.

Connection and configuration problems must fail two checks of the same category at least 60 seconds apart before an administrator notice is displayed. If an **Alert Email** is set, the confirmed incident also sends one email. Failed mail delivery is retried. Scheduled checks depend on working WordPress cron; on low-traffic sites they may run later than scheduled.

Further emails for the same incident are suppressed until a full connection check confirms recovery. Successful token validation during an incident schedules that recovery check without waiting for it before accepting the form. Saving changed credentials resets the incident and schedules a fresh check. The settings page's **Test connection** button tests the entered values without changing the stored incident state.

### Integrations

Toggle protection for individual native forms:

- **Login Form** (default: checked)
- **Registration Form** (default: checked)
- **Lost Password Form** (default: checked) - protects both the lost password request and the reset password forms
- **Comments Form** (default: checked)
- **WooCommerce** (default: unchecked; enabling it exposes independent WooCommerce settings, selected by default)
- **Gravity Forms** (default: checked; active only if Gravity Forms is enabled)
- **Forminator Forms** (default: checked; active only if Forminator is enabled)

When WooCommerce protection is enabled, its tab provides independent controls for classic checkout, Block Checkout, My Account and checkout login, My Account and checkout registration, post-checkout registration, lost password, and password reset. Checkout protection applies to guest customers. When both checkout and checkout registration protection are enabled, one verification covers the attempt. Express guest payments require a valid token where checkout protection applies.

### Styling

Customize the look and feel of the visible widget (only active when **Verification Mode** is set to **Widget (visible)**):

- **Widget Container Styles**: Background Color, Text Color, Border Color, and Border Radius (px)
- **Checkbox Styles**: Checkbox Background, Checkmark Color, Border Color, Border Style, Border Width (px), and Checkbox Border Radius (px)
- **Spinner Styles**: Spinner Background Color and Spinner Color
- **Error Message Styles**: background, text, and border colors for integration error messages
- **Hide Attribution Link**: Hides the "Cap" attribution link in the bottom-right of the widget

### Debug

The **Debug** tab shows administrator-only diagnostics grouped by category and time. Browser messages are mapped to fixed event names; arbitrary browser messages, visitor data, and credentials are not stored in this log. Browser events are sampled at most once every 10 seconds per event type. The log holds at most 500 groups and removes entries older than the configured retention period.

### Labels

The **Labels** tab lets you customize widget status, validation, accessibility, and troubleshooting text. Labels can be translated per language.

---

## Usage

### Native integrations

The plugin automatically integrates with the following WordPress forms upon activation:

| Form                 | Widget Hook                               | Validation Hook                |
| -------------------- | ----------------------------------------- | ------------------------------ |
| Comments             | `comment_form_after_fields`               | `preprocess_comment`           |
| Login                | `login_form`                              | `wp_authenticate_user`         |
| Registration         | `register_form`                           | `registration_errors`          |
| Lost Password        | `lostpassword_form`                       | `lostpassword_post`            |
| Password Reset       | `resetpass_form`                          | `validate_password_reset`      |
| Gravity Forms        | `gform_submit_button`                     | `gform_validation`             |
| Forminator           | Forminator submit button                  | Forminator submission errors  |

Gravity Forms and Forminator integrations are only active if the corresponding plugins are installed and active. WooCommerce is configured separately in the WooCommerce tab and supports classic checkout, Block Checkout, customer login and registration, and password recovery.

### Shortcode `[capconnect_widget]`

Insert the Cap widget in any page, post, or form builder:

```
[capconnect_widget]
```

With CSP nonce:

```
[capconnect_widget nonce="your-nonce"]
```

The shortcode automatically enqueues the widget's JS, CSS, and WASM, as well as `window.CAPCONNECT_CONFIG`.

The legacy `[tpow_widget]` shortcode remains supported for existing content.

### Programmatic Mode — Shortcode `[capconnect_programmatic]`

For cases where you want to trigger Cap verification without displaying a visible widget (SPA, multi-step form, custom integration), use `[capconnect_programmatic]`:

```
[capconnect_programmatic field="cap-token" id="capconnect-token"]
```

| Attribute | Description                         | Default            |
| --------- | ----------------------------------- | ------------------ |
| `field`   | Name of the `<input type="hidden">` | `cap-token`        |
| `id`      | HTML ID of the field                | `capconnect-token` |

The shortcode enqueues the assets and inserts a hidden field. The endpoint and the field name are exposed in `window.CAPCONNECT_CONFIG`, available as soon as the script loads.

The legacy `[tpow_programmatic]` shortcode remains supported for existing content.

For a custom AJAX form, reset its Cap state after the request completes, fails, or has an unknown outcome. Call `window.CAPCONNECT_FORMS.reset(form)` for the form that sent the request; this clears its token without starting another challenge or changing other forms. Do not start a new submission of that form until the previous request and its reset have finished. Built-in integrations manage their own resets.

```js
try {
  await fetch(form.action, { method: 'POST', body: new FormData(form) });
} finally {
  window.CAPCONNECT_FORMS.reset(form);
}
```

**Example:**

```html
[capconnect_programmatic field="cap-token" id="my-cap-token"]

<script type="module">
  document.getElementById("submit-btn").addEventListener("click", async (e) => {
    e.preventDefault();

    const cap = new Cap({ apiEndpoint: window.CAPCONNECT_CONFIG.apiEndpoint });

    cap.addEventListener("progress", (event) => {
      console.log(`Solving… ${event.detail.progress}%`);
    });

    const { token } = await cap.solve();
    document.getElementById("my-cap-token").value = token;
    e.target.closest("form").submit();
  });
</script>
```

`window.CAPCONNECT_CONFIG` is automatically injected by `wp_add_inline_script` when enqueuing assets (via `[capconnect_widget]`, `[capconnect_programmatic]`, or one of the native integrations):

```javascript
window.CAPCONNECT_CONFIG = {
  apiEndpoint: "https://cap.example.com/your-site-key/",
  tokenField: "cap-token",
};
```

---

## CSP

The widget uses Web Workers and WebAssembly. A strict CSP must include:

```
Content-Security-Policy:
  script-src 'nonce-{nonce}' 'strict-dynamic';
  worker-src blob:;
  wasm-unsafe-eval;
  connect-src 'self';
```

`worker-src blob:` — required because the widget creates workers via `Blob` URLs.
`wasm-unsafe-eval` — required for the WebAssembly computation.
`connect-src 'self'` — sufficient for the WASM, bundled locally in the plugin (no requests to an external CDN).

---

## Uninstallation

Uninstalling through WordPress removes the plugin's saved settings, diagnostic log, and monitor state, including legacy `tpow_*` settings, and clears its scheduled connection checks.

## Changelog

### 1.4.6

- Added an administrator debug log with aggregated events, bounded storage, and configurable retention.
- Confirm connection and configuration failures with delayed server checks before sending alerts; browser errors alone no longer trigger outage emails.
- Retry failed alert delivery and check for recovery after successful form validation so later incidents can trigger new alerts.
- Updated the isolated test environment for the monitor and added regression coverage for recovery and repeated outages.

### 1.4.5

- Added independent protection controls for WooCommerce checkout, Block Checkout, customer login and registration, and password recovery.
- Added WooCommerce token validation for guest checkout and registration flows.
- Fixed the widget attribution link display setting.

### 1.4.0

- Added compatibility with Wordfence, the official Two-Factor plugin, and AIOS two-factor login flows.
- Preserved the short-lived Cap verification across multi-step authentication and consumed it after successful login.

---

## License

GPL-2.0-or-later
