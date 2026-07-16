<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Verifier
{
    /**
     * Verify a Cap token against the configured Cap instance.
     */
    public function verify(string $token): bool
    {
        // A missing token must never be allowed or sent to the verification endpoint.
        if (trim($token) === '') {
            return false;
        }

        $response = $this->sendVerificationRequest($token);

        if (is_wp_error($response)) {
            Tpow_Settings::sendAlertEmail($response->get_error_message());
            return $this->failOpen();
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($this->isTransientStatusCode($statusCode)) {
            Tpow_Settings::sendAlertEmail(sprintf(
                /* translators: 1: HTTP status code */
                __('Server responded with HTTP status code %d', 'capconnect-for-wp'),
                $statusCode
            ));
            return $this->failOpen();
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            Tpow_Settings::sendAlertEmail(sprintf(
                /* translators: 1: HTTP status code */
                __('Server responded with HTTP status code %d', 'capconnect-for-wp'),
                $statusCode
            ));
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Tpow_Settings::sendAlertEmail(__('Cap server returned invalid JSON.', 'capconnect-for-wp'));
            return false;
        }

        $contentType = wp_remote_retrieve_header($response, 'content-type');
        if (! $this->isValidCapResponse($data, $contentType)) {
            Tpow_Settings::sendAlertEmail(__('Cap server returned an unexpected response.', 'capconnect-for-wp'));
            return false;
        }

        // Connection succeeded (we got a valid response from the server):
        // clear the failure notice and alert sent state.
        delete_option('tpow_connection_failed_notice');
        delete_option('tpow_alert_sent');

        return $data['success'];
    }

    /**
     * Sends a token verification request to the configured Cap instance.
     *
     * @return array<string, mixed>|WP_Error
     */
    private function sendVerificationRequest(string $token): array|WP_Error
    {
        // Construct Origin and Referer headers from home_url().
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

        return wp_remote_post($this->siteVerifyUrl(), [
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
    }

    /**
     * Determines whether an HTTP status represents a transient service failure.
     */
    private function isTransientStatusCode(int $statusCode): bool
    {
        return $statusCode === 408 || $statusCode === 429 || ($statusCode >= 500 && $statusCode < 600);
    }

    /**
     * Validates the expected JSON response schema from a Cap siteverify endpoint.
     *
     * @param mixed $data Decoded response data.
     */
    private function isValidCapResponse(mixed $data, string $contentType): bool
    {
        return stripos($contentType, 'application/json') !== false
            && is_array($data)
            && array_key_exists('success', $data)
            && is_bool($data['success']);
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
