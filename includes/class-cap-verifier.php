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

        $response = wp_remote_post($this->siteVerifyUrl(), [
            'body'    => [
                'secret'   => $this->secret(),
                'response' => $token,
            ],
            'timeout' => $this->timeout(),
        ]);

        if (is_wp_error($response)) {
            return $this->failOpen();
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode < 200 || $statusCode >= 300) {
            return $this->failOpen();
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

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
