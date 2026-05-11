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
            'window.CAP_CUSTOM_WASM_URL = ' . wp_json_encode(TPOW_PLUGIN_URL . 'assets/wasm/cap_wasm_bg.wasm') . ';',
            'before'
        );

        wp_enqueue_style(
            'tpow-widget',
            TPOW_PLUGIN_URL . 'assets/css/tpow-widget.css',
            [],
            TPOW_VERSION
        );
    }

    public function renderWidget(?string $nonce = null): string
    {
        $endpoint = esc_attr((string) get_option('tpow_endpoint', ''));
        $attrs = 'data-cap-api-endpoint="' . $endpoint . '"';

        if ($nonce !== null) {
            $attrs .= ' data-cap-csp-nonce="' . esc_attr($nonce) . '"';
        }

        return '<cap-widget ' . $attrs . '></cap-widget>';
    }

    public function renderShortcode(array $atts): string
    {
        $atts = shortcode_atts(['nonce' => null], $atts, 'tpow_widget');

        $this->enqueueAssets();

        return $this->renderWidget($atts['nonce'] ?: null);
    }
}
