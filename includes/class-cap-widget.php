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
                'tokenField'  => (string) get_option('tpow_token_field', 'cap-token'),
            ]) . ';',
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

        $i18n = [
            'initial-state'        => __("Verify you're human", 'capconnect-for-wp'),
            'required-label'       => __("Please verify you're human", 'capconnect-for-wp'),
            'verifying-label'      => __('Verifying...', 'capconnect-for-wp'),
            'verifying-aria-label' => __("Verifying you're a human, please wait", 'capconnect-for-wp'),
            'verified-aria-label'  => __("We have verified you're a human, you may now continue", 'capconnect-for-wp'),
            'error-label'          => __('Error', 'capconnect-for-wp'),
            'error-aria-label'     => __('An error occurred, please try again', 'capconnect-for-wp'),
            'wasm-disabled'        => __('Enable WASM for significantly faster solving', 'capconnect-for-wp'),
            'verify-aria-label'    => __("Click to verify you're a human", 'capconnect-for-wp'),
            'troubleshooting-label' => __('Troubleshoot', 'capconnect-for-wp'),
            'solved-label'         => __("You're a human", 'capconnect-for-wp'),
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
        $field = (string) get_option('tpow_token_field', 'cap-token');
        return '<input type="hidden" name="' . esc_attr($field) . '">';
    }

    public function renderShortcode(array $atts): string
    {
        $atts = shortcode_atts(['nonce' => null], $atts, 'tpow_widget');

        $this->enqueueAssets();

        return $this->renderWidget($atts['nonce'] ?: null);
    }

    /**
     * Shortcode [tpow_programmatic] pour le mode programmatic Cap.
     *
     * Charge les assets (JS/CSS/WASM + window.TPOW_CONFIG) et insère
     * un champ hidden prêt à recevoir le token résolu via new Cap({...}).
     *
     * Attributs :
     *   field : nom du champ hidden (défaut : cap-token)
     *   id    : id HTML du champ   (défaut : tpow-token)
     *
     * Exemple JS :
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
