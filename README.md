# CapConnect for WP

WordPress plugin to integrate [TryCap](https://trycap.dev/) — the open-source version of Cap — into WordPress forms.

No external dependencies: everything relies on native WordPress APIs. The WebAssembly module is bundled locally in the plugin.

---

## Prerequisites

- PHP 8.2+
- WordPress 6.4+
- A self-hosted Cap instance

---

## Installation

1. Copy the `capconnect-for-wp/` folder to `wp-content/plugins/`
2. Activate the plugin from **Plugins** in the WordPress administration panel
3. Go to **Settings > CapConnect** and fill in the endpoint and secret key

The plugin includes all necessary assets (JS, CSS, WASM). No extra build step or download is required.

---

## Configuration

Access **Settings > CapConnect** in the WordPress administration panel.

| Field | Description | Default |
|-------|-------------|--------|
| Instance URL | URL of your self-hosted Cap instance (e.g., `https://cap.example.com`) | — |
| Site Key | Site key configured on your Cap instance | — |
| Secret Key | Secret key generated in the Cap dashboard (never expose on the client side) | — |
| Timeout (seconds) | Delay before abandoning the request to `/siteverify` | `5` |
| Fail Open | If checked, lets the request through in case of communication error with Cap | unchecked |
| Hide Attribution Link | If checked, hides the "Cap" link in the bottom-right of the widget | unchecked |

### Fail-open mode

By default, any communication error with the Cap instance (network, timeout, 5xx error) blocks the request. Enabling **Fail Open** reverses this behavior: infrastructure errors will let the request through.

**An explicitly invalid token (`success: false`) is always rejected**, regardless of this setting.

---

## Usage

### Native integrations

The plugin automatically integrates with the following WordPress forms upon activation:

| Form | Widget Hook | Validation Hook |
|------------|-----------------|------------|
| Comments | `comment_form_after_fields` | `preprocess_comment` |
| Login | `login_form` | `wp_authenticate_user` |
| Registration | `register_form` | `registration_errors` |
| WooCommerce checkout | `woocommerce_after_checkout_billing_form` | `woocommerce_checkout_process` |
| Gravity Forms | `gform_submit_button` | `gform_validation` |

WooCommerce and Gravity Forms integrations are only active if the corresponding plugins are installed and active.

### Shortcode `[tpow_widget]`

Insert the Cap widget in any page, post, or form builder:

```
[tpow_widget]
```

With CSP nonce:

```
[tpow_widget nonce="your-nonce"]
```

The shortcode automatically enqueues the widget's JS, CSS, and WASM, as well as `window.TPOW_CONFIG`.

### Programmatic Mode — Shortcode `[tpow_programmatic]`

For cases where you want to trigger Cap verification without displaying a visible widget (SPA, multi-step form, custom integration), use `[tpow_programmatic]`:

```
[tpow_programmatic field="cap-token" id="tpow-token"]
```

| Attribute | Description | Default |
|----------|-------------|--------|
| `field` | Name of the `<input type="hidden">` | `cap-token` |
| `id` | HTML ID of the field | `tpow-token` |

The shortcode enqueues the assets and inserts a hidden field. The endpoint and the field name are exposed in `window.TPOW_CONFIG`, available as soon as the script loads.

**Example:**

```html
[tpow_programmatic field="cap-token" id="my-cap-token"]

<script type="module">
document.getElementById('submit-btn').addEventListener('click', async (e) => {
    e.preventDefault();

    const cap = new Cap({ apiEndpoint: window.TPOW_CONFIG.apiEndpoint });

    cap.addEventListener('progress', (event) => {
        console.log(`Solving… ${event.detail.progress}%`);
    });

    const { token } = await cap.solve();
    document.getElementById('my-cap-token').value = token;
    e.target.closest('form').submit();
});
</script>
```

`window.TPOW_CONFIG` is automatically injected by `wp_add_inline_script` when enqueuing assets (via `[tpow_widget]`, `[tpow_programmatic]`, or one of the native integrations):

```javascript
window.TPOW_CONFIG = {
    apiEndpoint: "https://cap.example.com/your-site-key/",
    tokenField:  "cap-token"
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

Uninstalling via the WordPress interface automatically deletes all saved options from the database:

- `tpow_instance_url`
- `tpow_site_key`
- `tpow_secret`
- `tpow_token_field`
- `tpow_timeout`
- `tpow_fail_open`

---

## License

GPL-2.0-or-later
