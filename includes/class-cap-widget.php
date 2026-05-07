<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Cap_Widget
{
    public function init(): void
    {
        add_shortcode('cap_widget', [$this, 'renderShortcode']);
    }

    public function enqueueAssets(): void
    {
        wp_enqueue_script(
            'cap-widget',
            WORDPRESSCAP_PLUGIN_URL . 'assets/js/cap-widget.js',
            [],
            WORDPRESSCAP_VERSION,
            ['strategy' => 'defer', 'in_footer' => true]
        );

        wp_enqueue_style(
            'cap-widget',
            WORDPRESSCAP_PLUGIN_URL . 'assets/css/cap-widget.css',
            [],
            WORDPRESSCAP_VERSION
        );
    }

    public function renderWidget(?string $nonce = null): string
    {
        $endpoint = esc_attr((string) get_option('cap_endpoint', ''));
        $attrs = 'data-cap-api-endpoint="' . $endpoint . '"';

        if ($nonce !== null) {
            $attrs .= ' data-cap-csp-nonce="' . esc_attr($nonce) . '"';
        }

        return '<cap-widget ' . $attrs . '></cap-widget>';
    }

    public function renderShortcode(array $atts): string
    {
        $atts = shortcode_atts(['nonce' => null], $atts, 'cap_widget');

        $this->enqueueAssets();

        return $this->renderWidget($atts['nonce'] ?: null);
    }
}
