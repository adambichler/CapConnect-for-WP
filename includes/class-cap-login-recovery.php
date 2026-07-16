<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

class Tpow_Login_Recovery
{
    private const ACTION = 'tpow_recovery';
    private const COOKIE_NAME = 'tpow_login_recovery';
    private const TOKEN_TTL = 15 * MINUTE_IN_SECONDS;
    private const SESSION_TTL = 10 * MINUTE_IN_SECONDS;
    private const RATE_LIMIT_TTL = HOUR_IN_SECONDS;
    private const ACCOUNT_RATE_LIMIT = 3;
    private const IP_RATE_LIMIT = 10;
    private bool $captchaValidationFailed = false;

    /**
     * Registers the recovery request and login form hooks.
     */
    public function init(): void
    {
        add_action('login_form_' . self::ACTION, [$this, 'handleRecoveryAction']);
        add_action('login_form', [$this, 'renderLoginRecoveryControl'], 20);
        add_action('login_enqueue_scripts', [$this, 'enqueueRecoveryStyles']);
    }

    /**
     * Loads the shared plugin stylesheet on WordPress login and recovery pages.
     */
    public function enqueueRecoveryStyles(): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        wp_enqueue_style(
            'tpow-widget',
            TPOW_PLUGIN_URL . 'assets/css/tpow-widget.css',
            [],
            TPOW_VERSION
        );
    }

    /**
     * Marks the current login request as a failed captcha validation.
     */
    public function markCaptchaValidationFailed(): void
    {
        $this->captchaValidationFailed = true;
    }

    /**
     * Returns whether login recovery can currently be used.
     */
    public function isAvailable(): bool
    {
        return Tpow_Settings::isConfigured()
            && (bool) get_option('tpow_protect_login', true)
            && ! $this->isLoginCaptchaDisabled();
    }

    /**
     * Renders either the recovery link or an active-session notice.
     */
    public function renderLoginRecoveryControl(): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        if ($this->hasActiveSession()) {
            echo '<p class="message tpow-recovery-active-message">'
                . esc_html__('Captcha recovery is active for one administrator account. Enter that account’s username and password to continue.', 'capconnect-for-wp')
                . '</p>';
            return;
        }

        if (! $this->captchaValidationFailed) {
            return;
        }

        $url = add_query_arg('action', self::ACTION, wp_login_url());
        echo '<p><a href="' . esc_url($url) . '">'
            . esc_html__('Captcha failed? Try administrator recovery.', 'capconnect-for-wp')
            . '</a></p>';
    }

    /**
     * Handles recovery requests and link confirmations on wp-login.php.
     */
    public function handleRecoveryAction(): void
    {
        if (! $this->isAvailable()) {
            wp_safe_redirect(wp_login_url());
            exit;
        }

        nocache_headers();
        header('Referrer-Policy: no-referrer');

        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : 'GET';

        if ($method === 'POST' && isset($_POST['tpow_request_recovery'])) {
            check_admin_referer('tpow_request_recovery', 'tpow_recovery_nonce');
            $identifier = isset($_POST['user_login'])
                ? sanitize_text_field(wp_unslash($_POST['user_login']))
                : '';
            $this->requestRecovery($identifier);
            $this->renderRequestForm(true);
            exit;
        }

        if ($method === 'POST' && isset($_POST['tpow_confirm_recovery'])) {
            $userId = isset($_POST['tpow_user_id']) ? absint($_POST['tpow_user_id']) : 0;
            check_admin_referer('tpow_confirm_recovery_' . $userId, 'tpow_recovery_nonce');
            $token = isset($_POST['tpow_recovery_token'])
                ? sanitize_text_field(wp_unslash($_POST['tpow_recovery_token']))
                : '';

            if ($this->confirmRecovery($userId, $token)) {
                wp_safe_redirect(add_query_arg('tpow-recovery', 'active', wp_login_url()));
                exit;
            }

            $this->renderInvalidLink();
            exit;
        }

        $userId = isset($_GET['user']) ? absint($_GET['user']) : 0;
        $token = isset($_GET['token'])
            ? sanitize_text_field(wp_unslash($_GET['token']))
            : '';

        if ($userId > 0 || $token !== '') {
            if ($this->isValidRecoveryToken($userId, $token)) {
                $this->renderConfirmationForm($userId, $token);
            } else {
                $this->renderInvalidLink();
            }
            exit;
        }

        $this->renderRequestForm(false);
        exit;
    }

    /**
     * Returns whether a valid recovery session is present in this browser.
     */
    public function hasActiveSession(): bool
    {
        return $this->getActiveSession() !== null;
    }

    /**
     * Consumes the recovery session when it belongs to the authenticated administrator.
     */
    public function consumeForUser(WP_User $user): bool
    {
        $session = $this->getActiveSession();
        if ($session === null || ! user_can($user, 'manage_options')) {
            return false;
        }

        if ((int) $session['user_id'] !== $user->ID || (int) $session['blog_id'] !== get_current_blog_id()) {
            return false;
        }

        $sessionKey = $this->sessionKey((string) $session['session_hash']);
        delete_transient($sessionKey);
        delete_transient($this->userSessionKey($user->ID));
        $this->clearSessionCookie();
        return true;
    }

    /**
     * Returns whether the emergency login captcha bypass is enabled.
     */
    public function isLoginCaptchaDisabled(): bool
    {
        return defined('TPOW_DISABLE_LOGIN_CAPTCHA') && TPOW_DISABLE_LOGIN_CAPTCHA === true;
    }

    /**
     * Sends a generic, rate-limited recovery email when the account is eligible.
     */
    private function requestRecovery(string $identifier): void
    {
        if ($identifier === '' || ! $this->incrementRateLimit($this->ipRateLimitKey(), self::IP_RATE_LIMIT)) {
            return;
        }

        $user = $this->findUser($identifier);
        if (! $user instanceof WP_User || ! user_can($user, 'manage_options') || ! is_email($user->user_email)) {
            return;
        }

        if (! $this->incrementRateLimit($this->accountRateLimitKey($user->ID), self::ACCOUNT_RATE_LIMIT)) {
            return;
        }

        $token = $this->generateToken();
        set_transient(
            $this->userTokenKey($user->ID),
            [
                'token_hash' => $this->hashValue($token),
                'blog_id'    => get_current_blog_id(),
                'expires'    => time() + self::TOKEN_TTL,
            ],
            self::TOKEN_TTL
        );

        $url = add_query_arg(
            [
                'action' => self::ACTION,
                'user'   => $user->ID,
                'token'  => $token,
            ],
            wp_login_url()
        );
        $siteName = sanitize_text_field(wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES));
        $subject = sprintf(
            /* translators: %s: Website name. */
            __('[%s] Administrator captcha recovery', 'capconnect-for-wp'),
            $siteName
        );
        $body = sprintf(
            /* translators: 1: Website name, 2: Recovery URL. */
            __("A captcha recovery was requested for an administrator account on %1\$s.\n\nConfirm the request using this link:\n%2\$s\n\nThe link expires in 15 minutes. It does not log anyone in; the correct username and password are still required. If you did not request this email, you can ignore it.", 'capconnect-for-wp'),
            $siteName,
            $url
        );

        if (! wp_mail($user->user_email, $subject, $body)) {
            delete_transient($this->userTokenKey($user->ID));
            return;
        }

        $this->invalidateUserSession($user->ID);
    }

    /**
     * Activates a short-lived recovery session after a valid confirmation.
     */
    private function confirmRecovery(int $userId, string $token): bool
    {
        if (! $this->isValidRecoveryToken($userId, $token)) {
            return false;
        }

        delete_transient($this->userTokenKey($userId));
        $this->invalidateUserSession($userId);

        $sessionToken = $this->generateToken();
        $sessionHash = $this->hashValue($sessionToken);
        $session = [
            'session_hash' => $sessionHash,
            'user_id'      => $userId,
            'blog_id'      => get_current_blog_id(),
            'expires'      => time() + self::SESSION_TTL,
        ];

        set_transient($this->sessionKey($sessionHash), $session, self::SESSION_TTL);
        set_transient($this->userSessionKey($userId), $sessionHash, self::SESSION_TTL);
        $this->setSessionCookie($sessionToken);
        return true;
    }

    /**
     * Validates a recovery link without consuming it.
     */
    private function isValidRecoveryToken(int $userId, string $token): bool
    {
        if ($userId <= 0 || $token === '') {
            return false;
        }

        $record = get_transient($this->userTokenKey($userId));
        if (! is_array($record)
            || ! isset($record['token_hash'], $record['blog_id'], $record['expires'])
            || (int) $record['blog_id'] !== get_current_blog_id()
            || (int) $record['expires'] < time()
        ) {
            return false;
        }

        return hash_equals((string) $record['token_hash'], $this->hashValue($token));
    }

    /**
     * Returns the validated server-side session represented by the recovery cookie.
     *
     * @return array<string, int|string>|null
     */
    private function getActiveSession(): ?array
    {
        if (! isset($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $sessionToken = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE_NAME]));
        if ($sessionToken === '') {
            return null;
        }

        $sessionHash = $this->hashValue($sessionToken);
        $session = get_transient($this->sessionKey($sessionHash));
        if (! is_array($session)
            || ! isset($session['session_hash'], $session['user_id'], $session['blog_id'], $session['expires'])
            || ! hash_equals((string) $session['session_hash'], $sessionHash)
            || (int) $session['blog_id'] !== get_current_blog_id()
            || (int) $session['expires'] < time()
        ) {
            return null;
        }

        $currentSessionHash = get_transient($this->userSessionKey((int) $session['user_id']));
        if (! is_string($currentSessionHash) || ! hash_equals($currentSessionHash, $sessionHash)) {
            return null;
        }

        return $session;
    }

    /**
     * Finds a WordPress user by email address or login name.
     */
    private function findUser(string $identifier): WP_User|false
    {
        $user = is_email($identifier) ? get_user_by('email', $identifier) : false;
        return $user instanceof WP_User ? $user : get_user_by('login', sanitize_user($identifier));
    }

    /**
     * Atomically increments the rate limit when a persistent object cache is available.
     */
    private function incrementRateLimit(string $key, int $limit): bool
    {
        if (wp_using_ext_object_cache()) {
            $group = 'tpow_rate_limit';

            if (wp_cache_add($key, 1, $group, self::RATE_LIMIT_TTL)) {
                return $limit >= 1;
            }

            $count = wp_cache_incr($key, 1, $group);
            if ($count === false) {
                return false;
            }

            return $count <= $limit;
        }

        // WordPress' default transient backend has no compare-and-swap primitive.
        // Keep the existing best-effort behavior when no persistent object cache exists.
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }

        set_transient($key, $count + 1, self::RATE_LIMIT_TTL);
        return true;
    }

    /**
     * Renders the recovery request form with a non-enumerating response.
     */
    private function renderRequestForm(bool $submitted): void
    {
        login_header(__('Captcha Recovery', 'capconnect-for-wp'));
        if ($submitted) {
            echo '<div class="message"><p>'
                . esc_html__('If an eligible administrator account matches the information provided, a recovery email has been sent.', 'capconnect-for-wp')
                . '</p></div>';
            echo '<p id="nav"><a href="' . esc_url(wp_login_url()) . '">'
                . esc_html__('Back to login', 'capconnect-for-wp')
                . '</a></p>';
            login_footer();
            return;
        }
        ?>
        <form name="tpow-recovery-form" id="tpow-recovery-form" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post">
            <p>
                <label for="user_login"><?php esc_html_e('Administrator username or email address', 'capconnect-for-wp'); ?></label>
                <input type="text" name="user_login" id="user_login" class="input" value="" size="20" autocapitalize="none" autocomplete="username" required="required" />
            </p>
            <p class="description"><?php esc_html_e('We will send a short-lived captcha recovery link to the email address stored on the administrator account.', 'capconnect-for-wp'); ?></p>
            <?php wp_nonce_field('tpow_request_recovery', 'tpow_recovery_nonce'); ?>
            <p class="submit tpow-recovery-submit">
                <button type="submit" name="tpow_request_recovery" id="wp-submit" class="button button-primary button-large tpow-recovery-button" value="1"><?php esc_html_e('Send recovery email', 'capconnect-for-wp'); ?></button>
            </p>
        </form>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to login', 'capconnect-for-wp'); ?></a></p>
        <?php
        login_footer('user_login');
    }

    /**
     * Renders the confirmation form without consuming the email link on GET.
     */
    private function renderConfirmationForm(int $userId, string $token): void
    {
        login_header(__('Confirm Captcha Recovery', 'capconnect-for-wp'));
        ?>
        <div class="message"><p><?php esc_html_e('Confirming creates a ten-minute captcha exception for this administrator account. You must still enter the correct username and password.', 'capconnect-for-wp'); ?></p></div>
        <form name="tpow-recovery-confirm-form" id="tpow-recovery-confirm-form" action="<?php echo esc_url(add_query_arg('action', self::ACTION, wp_login_url())); ?>" method="post">
            <input type="hidden" name="tpow_user_id" value="<?php echo esc_attr((string) $userId); ?>" />
            <input type="hidden" name="tpow_recovery_token" value="<?php echo esc_attr($token); ?>" />
            <?php wp_nonce_field('tpow_confirm_recovery_' . $userId, 'tpow_recovery_nonce'); ?>
            <p class="description tpow-recovery-button-description"><?php esc_html_e('Use this button to activate the one-time captcha recovery for this administrator account.', 'capconnect-for-wp'); ?></p>
            <p class="submit tpow-recovery-submit">
                <button type="submit" name="tpow_confirm_recovery" id="wp-submit" class="button button-primary button-large tpow-recovery-button" value="1"><?php esc_html_e('Activate captcha recovery', 'capconnect-for-wp'); ?></button>
            </p>
        </form>
        <p id="nav"><a href="<?php echo esc_url(wp_login_url()); ?>"><?php esc_html_e('Back to login', 'capconnect-for-wp'); ?></a></p>
        <?php
        login_footer();
    }

    /**
     * Renders a generic invalid or expired recovery link error.
     */
    private function renderInvalidLink(): void
    {
        login_header(__('Captcha Recovery', 'capconnect-for-wp'));
        echo '<div id="login_error" class="tpow-recovery-error"><p>'
            . esc_html__('This captcha recovery link is invalid, expired, or has already been used.', 'capconnect-for-wp')
            . '</p></div>';
        echo '<p class="submit tpow-recovery-submit-flush"><a class="button button-primary button-large tpow-recovery-link-button" href="' . esc_url(add_query_arg('action', self::ACTION, wp_login_url())) . '">'
            . esc_html__('Request a new recovery link', 'capconnect-for-wp')
            . '</a></p>';
        echo '<p id="nav"><a href="' . esc_url(wp_login_url()) . '">'
            . esc_html__('Back to login', 'capconnect-for-wp')
            . '</a></p>';
        login_footer();
    }

    /**
     * Sets the short-lived recovery session cookie.
     */
    private function setSessionCookie(string $sessionToken): void
    {
        setcookie(self::COOKIE_NAME, $sessionToken, [
            'expires'  => time() + self::SESSION_TTL,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_NAME] = $sessionToken;
    }

    /**
     * Removes the recovery session cookie from the browser.
     */
    private function clearSessionCookie(): void
    {
        setcookie(self::COOKIE_NAME, '', [
            'expires'  => time() - HOUR_IN_SECONDS,
            'path'     => defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[self::COOKIE_NAME]);
    }

    /**
     * Invalidates the current server-side recovery session for an administrator.
     */
    private function invalidateUserSession(int $userId): void
    {
        $sessionHash = get_transient($this->userSessionKey($userId));
        if (is_string($sessionHash) && $sessionHash !== '') {
            delete_transient($this->sessionKey($sessionHash));
        }
        delete_transient($this->userSessionKey($userId));
    }

    /**
     * Generates a URL-safe cryptographically secure token.
     */
    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Creates a keyed hash for recovery secrets and identifiers.
     */
    private function hashValue(string $value): string
    {
        return hash_hmac('sha256', $value, wp_salt('auth'));
    }

    /**
     * Returns the transient key for the current account recovery token.
     */
    private function userTokenKey(int $userId): string
    {
        return 'tpow_rt_' . get_current_blog_id() . '_' . $userId;
    }

    /**
     * Returns the transient key for a recovery session.
     */
    private function sessionKey(string $sessionHash): string
    {
        return 'tpow_rs_' . substr($sessionHash, 0, 40);
    }

    /**
     * Returns the transient key for the current user session pointer.
     */
    private function userSessionKey(int $userId): string
    {
        return 'tpow_rsu_' . get_current_blog_id() . '_' . $userId;
    }

    /**
     * Returns the rate-limit key for an administrator account.
     */
    private function accountRateLimitKey(int $userId): string
    {
        return 'tpow_ra_' . substr($this->hashValue(get_current_blog_id() . ':user:' . $userId), 0, 40);
    }

    /**
     * Returns the rate-limit key for the direct client address.
     */
    private function ipRateLimitKey(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR'])
            ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))
            : 'unknown';
        return 'tpow_ri_' . substr($this->hashValue(get_current_blog_id() . ':ip:' . $ip), 0, 40);
    }
}
