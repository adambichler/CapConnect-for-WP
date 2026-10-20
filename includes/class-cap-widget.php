<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Capconnect_Widget
{
    public function init(): void
    {
        add_shortcode('capconnect_widget', [$this, 'renderShortcode']);
        add_shortcode('capconnect_programmatic', [$this, 'renderProgrammaticShortcode']);
        add_shortcode('tpow_widget', [$this, 'renderShortcode']);
        add_shortcode('tpow_programmatic', [$this, 'renderProgrammaticShortcode']);
    }

    /**
     * Enqueues the pinned Cap widget and local WASM solver assets.
     */
    public function enqueueAssets(): void
    {
        $failed_label = sanitize_text_field(get_option('capconnect_verification_failed_label', ''));
        if ($failed_label === '') {
            $failed_label = __('Captcha verification failed', 'capconnect-for-wp');
        }

        wp_enqueue_script(
            'capconnect-widget',
            CAPCONNECT_PLUGIN_URL . 'assets/js/capconnect-widget.js',
            [],
            CAPCONNECT_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        $assetConfig = 'window.CAP_CUSTOM_WASM_URL = ' . wp_json_encode(CAPCONNECT_PLUGIN_URL . 'assets/wasm/cap_wasm_bg.wasm') . ';'
            . 'window.CAP_CUSTOM_HASHWX_URL = ' . wp_json_encode(CAPCONNECT_PLUGIN_URL . 'assets/wasm/hashwx.wasm') . ';'
            . 'window.CAPCONNECT_HIDE_ATTRIBUTION = ' . wp_json_encode((bool) get_option('capconnect_hide_attribution', false)) . ';';

        wp_add_inline_script(
            'capconnect-widget',
            $assetConfig
            . 'window.CAPCONNECT_CONFIG = ' . wp_json_encode([
                'apiEndpoint' => Capconnect_Settings::getEndpoint(),
                'tokenField'  => 'cap-token',
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('capconnect_report_error'),
                'verificationFailedMsg' => $failed_label,
            ]) . ';'
            . 'window.TPOW_CONFIG = window.CAPCONNECT_CONFIG;'
            . 'document.addEventListener("error", function (e) {'
            . '    if (e.detail && e.detail.isCap) {'
            . '        var msg = e.detail.message || "Unknown widget error";'
            . '        var cfg = window.CAPCONNECT_CONFIG;'
            . '        if (cfg && cfg.ajaxUrl) {'
            . '            var body = "action=capconnect_report_frontend_error&nonce=" + encodeURIComponent(cfg.nonce) + "&error_message=" + encodeURIComponent(msg);'
            . '            fetch(cfg.ajaxUrl, {'
            . '                method: "POST",'
            . '                body: body,'
            . '                headers: { "Content-Type": "application/x-www-form-urlencoded" }'
            . '            }).catch(function(err) {'
            . '                console.error("[cap] Failed to report error:", err);'
            . '            });'
            . '        }'
            . '    }'
            . '}, true);',
            'before'
        );

        wp_enqueue_style(
            'capconnect-widget',
            CAPCONNECT_PLUGIN_URL . 'assets/css/capconnect-widget.css',
            [],
            CAPCONNECT_VERSION
        );

        $styling_map = [
            'capconnect_background'          => '--cap-background',
            'capconnect_color'               => '--cap-color',
            'capconnect_border_color'        => '--cap-border-color',
            'capconnect_checkbox_background' => '--cap-checkbox-background',
            'capconnect_spinner_color'       => '--cap-spinner-color',
            'capconnect_spinner_background'  => '--cap-spinner-background-color',
        ];

        $rules = [];
        foreach ($styling_map as $opt => $var) {
            $val = get_option($opt, '');
            if (! empty($val)) {
                $rules[] = sprintf('%s: %s;', $var, $val);
            }
        }

        $border_color = get_option('capconnect_checkbox_border_color', '');
        $border_style = get_option('capconnect_checkbox_border_style', 'solid');
        $border_width = get_option('capconnect_checkbox_border_width', 2);

        if ($border_style === 'none') {
            $rules[] = '--cap-checkbox-border: none;';
        } elseif (! empty($border_color)) {
            $rules[] = sprintf('--cap-checkbox-border: %dpx %s %s;', (int) $border_width, $border_style, $border_color);
        }

        $widget_radius   = get_option('capconnect_border_radius', 8);
        $checkbox_radius = get_option('capconnect_checkbox_border_radius', 5);
        $rules[]         = sprintf('--cap-border-radius: %dpx;', (int) $widget_radius);
        $rules[]         = sprintf('--cap-checkbox-border-radius: %dpx;', (int) $checkbox_radius);

        $checkmark_color = get_option('capconnect_checkbox_checkmark_color', '#374151');
        if (! empty($checkmark_color)) {
            $svg_color = str_replace('#', '%23', $checkmark_color);
            $rules[]   = "--cap-checkmark: url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cstyle%3E@keyframes anim%7B0%25%7Bstroke-dashoffset:23.21320343017578px%7Dto%7Bstroke-dashoffset:0%7D%7D%3C/style%3E%3Cpath fill='none' stroke='{$svg_color}' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m5 12 5 5L20 7' style='stroke-dashoffset:0;stroke-dasharray:23.21320343017578px;animation:anim .5s ease'/%3E%3C/svg%3E\");";
        }

        if (! empty($rules)) {
            $css = 'cap-widget {' . implode(' ', $rules) . '}';
            wp_add_inline_style('capconnect-widget', $css);
        }

        $error_background = get_option('capconnect_error_background', '#fef2f2') ?: '#fef2f2';
        $error_color      = get_option('capconnect_error_color', '#b91c1c') ?: '#b91c1c';
        $error_border     = get_option('capconnect_error_border_color', '#ef4444') ?: '#ef4444';
        $error_css        = sprintf(
            '.capconnect-error-message, .tpow-error-message { background-color: %1$s; color: %2$s; border: 1px solid %3$s; border-radius: %4$dpx; }',
            $error_background,
            $error_color,
            $error_border,
            (int) $widget_radius
        );
        wp_add_inline_style('capconnect-widget', $error_css);
        wp_add_inline_style(
            'capconnect-widget',
            'body:has(#wfls-prompt-overlay, #wfls-prompt-wrapper, #wfls-token, #simba_two_factor_auth, input[name="two_factor_code"]) .cap-widget-wrapper { display: none !important; }'
        );

        if (get_option('capconnect_mode', 'widget') === 'programmatic') {
            wp_add_inline_script('capconnect-widget', $this->getProgrammaticScript(), 'after');
        }

        wp_add_inline_script('capconnect-widget', $this->getLoginCompatibilityScript(), 'after');
    }

    private function getProgrammaticScript(): string
    {
        return <<<'JS'
(function () {
    var cfg = window.CAPCONNECT_CONFIG;
    if (!cfg || !cfg.apiEndpoint || typeof window.Cap === 'undefined') return;
    var cap = new window.Cap({ apiEndpoint: cfg.apiEndpoint });

    // The vendor stylesheet forces cap-widget to display, so override it for programmatic mode.
    if (!cap.widget || !cap.widget.style || typeof cap.widget.style.setProperty !== 'function' || typeof cap.widget.setAttribute !== 'function') {
        console.error('[cap] Programmatic widget is unavailable.');
        return;
    }
    cap.widget.style.setProperty('display', 'none', 'important');
    cap.widget.setAttribute('aria-hidden', 'true');

    var tokenPromise = cap.solve();
    var solveError = null;

    /**
     * Returns the programmatic token fields, including custom shortcode fields.
     */
    function getProgrammaticFields(root) {
        var scope = root || document;
        var fields = scope.querySelectorAll('.capconnect-programmatic-wrapper input[type="hidden"], .tpow-programmatic-wrapper input[type="hidden"]');

        if (!fields.length && cfg.tokenField) {
            fields = scope.querySelectorAll('input[name="' + cfg.tokenField + '"]');
        }

        return fields;
    }

    /**
     * Returns the programmatic token field belonging to a form.
     */
    function getFormField(form) {
        var fields = getProgrammaticFields(form);
        return fields.length ? fields[0] : null;
    }

    /**
     * Displays a verification error in the supplied form.
     */
    function showError(form, message) {
        var wrapper = form.querySelector('.capconnect-programmatic-wrapper, .tpow-programmatic-wrapper');
        var errDiv = wrapper ? wrapper.querySelector('.capconnect-error-message, .tpow-error-message') : null;
        if (!errDiv) {
            var field = getFormField(form);
            if (field) {
                errDiv = document.createElement('div');
                errDiv.className = 'capconnect-error-message tpow-error-message';
                errDiv.setAttribute('role', 'alert');
                if (field.parentNode) {
                    field.parentNode.insertBefore(errDiv, field.nextSibling);
                } else {
                    form.appendChild(errDiv);
                }
            } else {
                errDiv = document.createElement('div');
                errDiv.className = 'capconnect-error-message tpow-error-message';
                errDiv.setAttribute('role', 'alert');
                form.appendChild(errDiv);
            }
        }
        if (errDiv) {
            errDiv.textContent = message;
            errDiv.style.display = 'block';
        }

        var event = new CustomEvent('capconnectVerificationFailed', {
            detail: { form: form, message: message, error: solveError },
            bubbles: true,
            cancelable: true
        });
        form.dispatchEvent(event);
        form.dispatchEvent(new CustomEvent('tpowVerificationFailed', {
            detail: event.detail,
            bubbles: true,
            cancelable: true
        }));
    }

    /**
     * Displays an initial solve error in every affected form.
     */
    function showInitialErrors(message) {
        var forms = [];
        getProgrammaticFields().forEach(function (field) {
            var form = field.form || field.closest('form');
            if (form && forms.indexOf(form) === -1) {
                forms.push(form);
            }
        });

        forms.forEach(function (form) {
            showError(form, message);
        });
    }

    /**
     * Applies a token to all programmatic fields and clears their errors.
     */
    function applyTokenAndClearErrors(token) {
        getProgrammaticFields().forEach(function (f) {
            f.value = token;
            var wrapper = f.closest('.capconnect-programmatic-wrapper, .tpow-programmatic-wrapper');
            if (wrapper) {
                var errDiv = wrapper.querySelector('.capconnect-error-message, .tpow-error-message');
                if (errDiv) {
                    errDiv.style.display = 'none';
                    errDiv.textContent = '';
                }
            }
        });
    }

    tokenPromise.then(function (r) {
        applyTokenAndClearErrors(r.token);
    }).catch(function (err) {
        solveError = err;
        showInitialErrors(cfg.verificationFailedMsg || 'Captcha verification failed');
    });

    document.addEventListener('submit', function (e) {
        var field = getFormField(e.target);
        if (!field) return;
        if (field.value) return;

        e.preventDefault();
        var form = e.target;

        // If the previous attempt failed, retry solving
        if (solveError) {
            solveError = null;
            tokenPromise = cap.solve();
            tokenPromise.then(function (r) {
                applyTokenAndClearErrors(r.token);
            }).catch(function (err) {
                solveError = err;
            });
        }

        tokenPromise.then(function (r) {
            field.value = r.token;
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }).catch(function (err) {
            showError(form, cfg.verificationFailedMsg || 'Captcha verification failed');
        });
    }, true);
})();
JS;
    }

    /**
     * Keeps solved tokens available and hides visible widgets during 2FA prompts.
     */
    private function getLoginCompatibilityScript(): string
    {
        return <<<'JS'
(function () {
    var token = '';

    /**
     * Returns whether a supported plugin is currently showing its 2FA input.
     */
    function isTwoFactorPromptVisible() {
        return !!document.querySelector('#wfls-prompt-overlay, #wfls-prompt-wrapper, #wfls-token, #simba_two_factor_auth, input[name="two_factor_code"]');
    }

    /**
     * Prevents the completed visible widget from overlapping a 2FA submit button.
     */
    function syncWidgetVisibility() {
        var hideWidget = isTwoFactorPromptVisible();
        document.querySelectorAll('.cap-widget-wrapper').forEach(function (wrapper) {
            if (hideWidget) {
                wrapper.hidden = true;
                return;
            }

            wrapper.hidden = false;
        });
    }

    document.addEventListener('solve', function (event) {
        if (event.target && event.target.matches && event.target.matches('cap-widget') && event.detail && event.detail.token) {
            token = event.detail.token;
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!token || !form || !form.matches || !form.matches('#loginform, form.login, .woocommerce form.login')) {
            return;
        }

        if (form.querySelector('input[name="cap-token"]')) {
            return;
        }

        var replacement = document.createElement('input');
        replacement.type = 'hidden';
        replacement.name = 'cap-token';
        replacement.value = token;
        form.appendChild(replacement);
    }, true);

    syncWidgetVisibility();
    new MutationObserver(syncWidgetVisibility).observe(document.documentElement, {
        childList: true,
        subtree: true
    });
})();
JS;
    }

    public function renderWidget(?string $nonce = null): string
    {
        $endpoint = esc_attr(Capconnect_Settings::getEndpoint());
        $attrs = 'data-cap-api-endpoint="' . $endpoint . '"';

        if ($nonce !== null) {
            $attrs .= ' data-cap-csp-nonce="' . esc_attr($nonce) . '"';
        }

        $initial_state  = (string) get_option('capconnect_initial_state_label', '');
        $verifying      = (string) get_option('capconnect_verifying_label', '');
        $solved         = (string) get_option('capconnect_solved_label', '');
        $required       = (string) get_option('capconnect_required_label', '');
        $verified_aria  = (string) get_option('capconnect_verified_aria_label', '');
        $verifying_aria = (string) get_option('capconnect_verifying_aria_label', '');
        $verify_aria    = (string) get_option('capconnect_verify_aria_label', '');
        $error          = (string) get_option('capconnect_error_label', '');
        $error_aria     = (string) get_option('capconnect_error_aria_label', '');
        $wasm_disabled  = (string) get_option('capconnect_wasm_disabled_label', '');
        $troubleshoot   = (string) get_option('capconnect_troubleshoot_label', '');

        $i18n = [
            'initial-state'        => $initial_state !== '' ? $initial_state : __("Verify you're human", 'capconnect-for-wp'),
            'required-label'       => $required !== '' ? $required : __("Please verify you're human", 'capconnect-for-wp'),
            'verifying-label'      => $verifying !== '' ? $verifying : __('Verifying...', 'capconnect-for-wp'),
            'verifying-aria-label' => $verifying_aria !== '' ? $verifying_aria : __("Verifying you're a human, please wait", 'capconnect-for-wp'),
            'verified-aria-label'  => $verified_aria !== '' ? $verified_aria : __("We have verified you're a human, you may now continue", 'capconnect-for-wp'),
            'error-label'          => $error !== '' ? $error : __('Error', 'capconnect-for-wp'),
            'error-aria-label'     => $error_aria !== '' ? $error_aria : __('An error occurred, please try again', 'capconnect-for-wp'),
            'wasm-disabled'        => $wasm_disabled !== '' ? $wasm_disabled : __('Enable WASM for significantly faster solving', 'capconnect-for-wp'),
            'verify-aria-label'    => $verify_aria !== '' ? $verify_aria : __("Click to verify you're a human", 'capconnect-for-wp'),
            'troubleshooting-label' => $troubleshoot !== '' ? $troubleshoot : __('Troubleshoot', 'capconnect-for-wp'),
            'solved-label'         => $solved !== '' ? $solved : __("You're a human", 'capconnect-for-wp'),
        ];

        foreach ($i18n as $key => $text) {
            $attrs .= ' data-cap-i18n-' . $key . '="' . esc_attr($text) . '"';
        }

        return '<cap-widget ' . $attrs . '></cap-widget>';
    }

    public function renderForMode(): string
    {
        if (get_option('capconnect_mode', 'widget') === 'programmatic') {
            return $this->renderProgrammaticWidget();
        }
        return $this->renderWidget();
    }

    public function renderProgrammaticWidget(): string
    {
        $field = 'cap-token';
        return '<div class="capconnect-programmatic-wrapper tpow-programmatic-wrapper">'
            . '<input type="hidden" name="' . esc_attr($field) . '">'
            . '<div class="capconnect-error-message tpow-error-message" style="display: none;" role="alert"></div>'
            . '</div>';
    }

    /**
     * Renders the visible widget shortcode with its optional CSP nonce.
     */
    public function renderShortcode(array $atts): string
    {
        if (! Capconnect_Settings::isConfigured()) {
            return '';
        }

        $atts = shortcode_atts(['nonce' => null], $atts, 'capconnect_widget');
        $this->enqueueAssets();

        return $this->renderWidget($atts['nonce'] ?: null);
    }

    /**
     * Shortcode [capconnect_programmatic] for the programmatic Cap mode.
     *
     * Loads the assets (JS/CSS/WASM + window.CAPCONNECT_CONFIG) and inserts
     * a hidden field ready to receive the solved token via new Cap({...}).
     *
     * Attributes:
     *   field : name of the hidden field (default: cap-token)
     *   id    : HTML id of the field     (default: capconnect-token)
     *
     * JS Example:
     *   const cap = new Cap({ apiEndpoint: window.CAPCONNECT_CONFIG.apiEndpoint });
     *   const { token } = await cap.solve();
     *   document.getElementById('capconnect-token').value = token;
     */
    public function renderProgrammaticShortcode(array $atts): string
    {
        if (! Capconnect_Settings::isConfigured()) {
            return '';
        }

        $atts = shortcode_atts(['field' => 'cap-token', 'id' => 'capconnect-token'], $atts, 'capconnect_programmatic');

        $this->enqueueAssets();

        return '<div class="capconnect-programmatic-wrapper tpow-programmatic-wrapper">'
            . '<input type="hidden" name="' . esc_attr($atts['field']) . '" id="' . esc_attr($atts['id']) . '">'
            . '<div class="capconnect-error-message tpow-error-message" style="display: none;" role="alert"></div>'
            . '</div>';
    }
}
