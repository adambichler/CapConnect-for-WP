<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Verifier
{
    public function verify(string $token): bool
    {
        if (empty($token)) {
            return $this->failOpen();
        }

        // Construct Origin and Referer headers from home_url()
        $origin = home_url();
        $parsed = wp_parse_url($origin);
        $origin_header = '';
        if ($parsed && isset($parsed['scheme'], $parsed['host'])) {
            $origin_header = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $origin_header .= ':' . $parsed['port'];
            }
        }
        $referer_header = home_url('/');

        $headers = [];
        if (!empty($origin_header)) {
            $headers['Origin'] = $origin_header;
            $headers['Referer'] = $referer_header;
        }

        $verify_url = $this->siteVerifyUrl();

        $response = wp_remote_post($verify_url, [
            'headers' => array_merge($headers, [
                'Content-Type' => 'application/json',
            ]),
            'body'    => wp_json_encode([
                'secret'   => $this->secret(),
                'response' => $token,
            ]),
            'timeout' => $this->timeout(),
            'data_format' => 'body',
        ]);

        if (is_wp_error($response)) {
            Tpow_Settings::sendAlertEmail($response->get_error_message());
            return $this->failOpen();
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            Tpow_Settings::sendAlertEmail(sprintf(
                /* translators: 1: HTTP status code */
                __('Server responded with HTTP status code %d', 'capconnect-for-wp'),
                $statusCode
            ));
            return $this->failOpen();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        // Connection succeeded (we got a valid response from the server):
        // clear the failure notice and alert sent state.
        delete_option('tpow_connection_failed_notice');
        delete_option('tpow_alert_sent');

        return ! empty($data['success']);
    }

    private function siteVerifyUrl(): string
    {
        return rtrim(Tpow_Settings::getEndpoint(), '/') . '/siteverify';
    }

    private function secret(): string
    {
        return (string) get_option('tpow_secret', '');
    }

    private function timeout(): int
    {
        return (int) get_option('tpow_timeout', 5);
    }

    private function failOpen(): bool
    {
        return (bool) get_option('tpow_fail_open', false);
    }
}
