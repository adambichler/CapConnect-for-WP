<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Capconnect_Login_Proof
{
    private const COOKIE_NAME = 'capconnect_login_proof';
    private const TTL = 10 * MINUTE_IN_SECONDS;

    /**
     * Issues a short-lived, signed proof for a successfully verified login.
     */
    public function issue(WP_User $user): void
    {
        if ($this->hasForUser($user)) {
            return;
        }

        $payload = wp_json_encode([
            'expires'  => time() + self::TTL,
            'nonce'    => wp_generate_password(32, false, false),
            'user_id'  => $user->ID,
        ]);

        if (! is_string($payload)) {
            return;
        }

        $encodedPayload = $this->base64UrlEncode($payload);
        $signature = hash_hmac('sha256', $encodedPayload, wp_salt('auth'));
        $this->setCookie($encodedPayload . '.' . $signature, time() + self::TTL);
    }

    /**
     * Returns whether the current browser holds a valid proof for the user.
     */
    public function hasForUser(WP_User $user): bool
    {
        $proof = $this->getProof();

        if ($proof === null) {
            return false;
        }

        if ($proof['expires'] < time() || $proof['user_id'] !== $user->ID) {
            $this->consume();
            return false;
        }

        return true;
    }

    /**
     * Removes the login proof after the final authentication succeeds.
     */
    public function consume(): void
    {
        $this->setCookie('', time() - HOUR_IN_SECONDS);
    }

    /**
     * Returns the verified proof payload from the current browser cookie.
     *
     * @return array{expires: int, user_id: int}|null
     */
    private function getProof(): ?array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Login proof is signed and read before a login nonce is available.
        $cookie = isset($_COOKIE[self::COOKIE_NAME]) ? wp_unslash($_COOKIE[self::COOKIE_NAME]) : '';
        if (! is_string($cookie) || substr_count($cookie, '.') !== 1) {
            return null;
        }

        [$encodedPayload, $signature] = explode('.', $cookie, 2);
        $expectedSignature = hash_hmac('sha256', $encodedPayload, wp_salt('auth'));
        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payload = $this->base64UrlDecode($encodedPayload);
        $data = is_string($payload) ? json_decode($payload, true) : null;
        if (! is_array($data) || ! isset($data['expires'], $data['user_id'], $data['nonce'])) {
            return null;
        }

        return [
            'expires' => (int) $data['expires'],
            'user_id' => (int) $data['user_id'],
        ];
    }

    /**
     * Sets the browser-bound proof cookie with secure login defaults.
     */
    private function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE_NAME, $value, [
            'expires'  => $expires,
            'httponly' => true,
            'path'     => COOKIEPATH ?: '/',
            'samesite' => 'Lax',
            'secure'   => is_ssl(),
        ]);

        if ($value === '') {
            unset($_COOKIE[self::COOKIE_NAME]);
            return;
        }

        $_COOKIE[self::COOKIE_NAME] = $value;
    }

    /**
     * Encodes cookie payload data without padding.
     */
    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    /**
     * Decodes cookie payload data without padding.
     */
    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
