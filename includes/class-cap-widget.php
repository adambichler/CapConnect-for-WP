<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Widget
{
    public function init(): void
    {
        add_shortcode('tpow_widget', [$this, 'renderShortcode']);
        add_shortcode('tpow_programmatic', [$this, 'renderProgrammaticShortcode']);
    }

    public function enqueueAssets(): void
    {
        wp_enqueue_script(
            'tpow-widget',
            TPOW_PLUGIN_URL . 'assets/js/tpow-widget.js',
            [],
            TPOW_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_add_inline_script(
            'tpow-widget',
            'window.CAP_CUSTOM_WASM_URL = ' . wp_json_encode(TPOW_PLUGIN_URL . 'assets/wasm/cap_wasm_bg.wasm') . ';'
            . 'window.TPOW_CONFIG = ' . wp_json_encode([
                'apiEndpoint' => Tpow_Settings::getEndpoint(),
                'tokenField'  => 'cap-token',
                'ajaxUrl'     => admin_url('admin-ajax.php'),
                'nonce'       => wp_create_nonce('tpow_report_error'),
            ]) . ';'
            . 'document.addEventListener("error", function (e) {'
            . '    if (e.detail && e.detail.isCap) {'
            . '        var msg = e.detail.message || "Unknown widget error";'
            . '        var cfg = window.TPOW_CONFIG;'
            . '        if (cfg && cfg.ajaxUrl) {'
            . '            var body = "action=tpow_report_frontend_error&nonce=" + encodeURIComponent(cfg.nonce) + "&error_message=" + encodeURIComponent(msg);'
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

        if (get_option('tpow_mode', 'widget') === 'programmatic') {
            wp_add_inline_script('tpow-widget', $this->getProgrammaticScript(), 'after');
        } else {
            wp_enqueue_style(
                'tpow-widget',
                TPOW_PLUGIN_URL . 'assets/css/tpow-widget.css',
                [],
                TPOW_VERSION
            );

            if (get_option('tpow_hide_attribution', false)) {
                wp_add_inline_style('tpow-widget', 'cap-widget::part(attribution){display:none}');
            }

            $styling_map = [
                'tpow_background'          => '--cap-background',
                'tpow_color'               => '--cap-color',
                'tpow_border_color'        => '--cap-border-color',
                'tpow_checkbox_background' => '--cap-checkbox-background',
                'tpow_spinner_color'       => '--cap-spinner-color',
                'tpow_spinner_background'  => '--cap-spinner-background-color',
            ];

            $rules = [];
            foreach ($styling_map as $opt => $var) {
                $val = get_option($opt, '');
                if (! empty($val)) {
                    $rules[] = sprintf('%s: %s;', $var, $val);
                }
            }

            $border_color = get_option('tpow_checkbox_border_color', '');
            $border_style = get_option('tpow_checkbox_border_style', 'solid');
            $border_width = get_option('tpow_checkbox_border_width', 2);

            if ($border_style === 'none') {
                $rules[] = '--cap-checkbox-border: none;';
            } elseif (! empty($border_color)) {
                $rules[] = sprintf('--cap-checkbox-border: %dpx %s %s;', (int) $border_width, $border_style, $border_color);
            }

            $widget_radius   = get_option('tpow_border_radius', 8);
            $checkbox_radius = get_option('tpow_checkbox_border_radius', 5);
            $rules[]         = sprintf('--cap-border-radius: %dpx;', (int) $widget_radius);
            $rules[]         = sprintf('--cap-checkbox-border-radius: %dpx;', (int) $checkbox_radius);

            $checkmark_color = get_option('tpow_checkbox_checkmark_color', '#374151');
            if (! empty($checkmark_color)) {
                $svg_color = str_replace('#', '%23', $checkmark_color);
                $rules[]   = "--cap-checkmark: url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24'%3E%3Cstyle%3E@keyframes anim%7B0%25%7Bstroke-dashoffset:23.21320343017578px%7Dto%7Bstroke-dashoffset:0%7D%7D%3C/style%3E%3Cpath fill='none' stroke='{$svg_color}' stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='m5 12 5 5L20 7' style='stroke-dashoffset:0;stroke-dasharray:23.21320343017578px;animation:anim .5s ease'/%3E%3C/svg%3E\");";
            }

            if (! empty($rules)) {
                $css = 'cap-widget {' . implode(' ', $rules) . '}';
                wp_add_inline_style('tpow-widget', $css);
            }
        }
    }

    private function getProgrammaticScript(): string
    {
        return <<<'JS'
(function () {
    var cfg = window.TPOW_CONFIG;
    if (!cfg || !cfg.apiEndpoint || typeof window.Cap === 'undefined') return;
    var cap = new window.Cap({ apiEndpoint: cfg.apiEndpoint });
    var tokenPromise = cap.solve();
    tokenPromise.then(function (r) {
        document.querySelectorAll('input[name="' + cfg.tokenField + '"]').forEach(function (f) {
            f.value = r.token;
        });
    }).catch(function () {});
    document.addEventListener('submit', function (e) {
        var field = e.target.querySelector('input[name="' + cfg.tokenField + '"]');
        if (!field || field.value) return;
        e.preventDefault();
        var form = e.target;
        tokenPromise.then(function (r) {
            field.value = r.token;
            form.requestSubmit ? form.requestSubmit() : form.submit();
        }).catch(function () {
            form.requestSubmit ? form.requestSubmit() : form.submit();
        });
    }, true);
})();
JS;
    }

    public function renderWidget(?string $nonce = null): string
    {
        $endpoint = esc_attr(Tpow_Settings::getEndpoint());
        $attrs = 'data-cap-api-endpoint="' . $endpoint . '"';

        if ($nonce !== null) {
            $attrs .= ' data-cap-csp-nonce="' . esc_attr($nonce) . '"';
        }

        $initial_state  = (string) get_option('tpow_initial_state_label', '');
        $verifying      = (string) get_option('tpow_verifying_label', '');
        $solved         = (string) get_option('tpow_solved_label', '');
        $required       = (string) get_option('tpow_required_label', '');
        $verified_aria  = (string) get_option('tpow_verified_aria_label', '');
        $verifying_aria = (string) get_option('tpow_verifying_aria_label', '');
        $verify_aria    = (string) get_option('tpow_verify_aria_label', '');
        $error          = (string) get_option('tpow_error_label', '');
        $error_aria     = (string) get_option('tpow_error_aria_label', '');
        $wasm_disabled  = (string) get_option('tpow_wasm_disabled_label', '');
        $troubleshoot   = (string) get_option('tpow_troubleshoot_label', '');

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
        if (get_option('tpow_mode', 'widget') === 'programmatic') {
            return $this->renderProgrammaticWidget();
        }
        return $this->renderWidget();
    }

    public function renderProgrammaticWidget(): string
    {
        $field = 'cap-token';
        return '<input type="hidden" name="' . esc_attr($field) . '">';
    }

    public function renderShortcode(array $atts): string
    {
        $atts = shortcode_atts(['nonce' => null], $atts, 'tpow_widget');

        $this->enqueueAssets();

        return $this->renderWidget($atts['nonce'] ?: null);
    }

    /**
     * Shortcode [tpow_programmatic] for the programmatic Cap mode.
     *
     * Loads the assets (JS/CSS/WASM + window.TPOW_CONFIG) and inserts
     * a hidden field ready to receive the solved token via new Cap({...}).
     *
     * Attributes:
     *   field : name of the hidden field (default: cap-token)
     *   id    : HTML id of the field     (default: tpow-token)
     *
     * JS Example:
     *   const cap = new Cap({ apiEndpoint: window.TPOW_CONFIG.apiEndpoint });
     *   const { token } = await cap.solve();
     *   document.getElementById('tpow-token').value = token;
     */
    public function renderProgrammaticShortcode(array $atts): string
    {
        $atts = shortcode_atts(['field' => 'cap-token', 'id' => 'tpow-token'], $atts, 'tpow_programmatic');

        $this->enqueueAssets();

        return '<input type="hidden" name="' . esc_attr($atts['field']) . '" id="' . esc_attr($atts['id']) . '">';
    }
}
