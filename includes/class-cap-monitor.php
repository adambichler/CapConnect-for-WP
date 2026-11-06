<?php

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

/** Records diagnostics and confirms connection failures before notifying administrators. */
class Capconnect_Monitor
{
    private const LOG = 'capconnect_debug_log';
    private const STATE = 'capconnect_monitor_state';
    private const HOOK = 'capconnect_monitor_check';

    /** Registers delayed checks and log maintenance. */
    public function init(): void
    {
        add_action(self::HOOK, [self::class, 'check']);
        add_action('capconnect_hourly_connection_check', [self::class, 'prune']);
    }

    /** Maps untrusted browser messages to a bounded set of safe diagnostics. */
    public static function browserError(string $message): void
    {
        $message = strtolower($message);
        if (str_contains($message, 'blocked by instrumentation')) {
            $event = 'Blocked by instrumentation';
        } elseif (str_contains($message, 'hashwx') && str_contains($message, 'timed out')) {
            $event = 'HashWX worker timed out';
        } elseif (str_contains($message, 'failed to fetch')) {
            $event = 'Failed to fetch';
        } else {
            $event = 'Other widget error';
        }
        $log_key = 'capconnect_log_' . md5($event);
        if (! get_transient($log_key)) {
            set_transient($log_key, 1, 10);
            self::log('browser', $event);
        }
        if ($event === 'Failed to fetch' && ! get_transient('capconnect_probe_throttle')) {
            set_transient('capconnect_probe_throttle', 1, 60);
            self::schedule(1);
        }
    }

    /** Stores a bounded, aggregated diagnostic without visitor or credential data. */
    public static function log(string $category, string $message): void
    {
        // Only trusted callers supply messages; never persist arbitrary remote responses.
        $entries = get_option(self::LOG, []);
        $entries = is_array($entries) ? $entries : [];
        $now = time();
        $cutoff = $now - self::retention() * DAY_IN_SECONDS;
        $entries = array_values(array_filter($entries, static fn($entry) => is_array($entry) && ($entry['last'] ?? 0) >= $cutoff));
        $found = false;
        foreach ($entries as &$entry) {
            if (($entry['category'] ?? '') === $category && ($entry['message'] ?? '') === $message && ($entry['first'] ?? 0) >= $now - HOUR_IN_SECONDS) {
                $entry['last'] = $now;
                $entry['count'] = (int) ($entry['count'] ?? 1) + 1;
                $found = true;
                break;
            }
        }
        unset($entry);
        if (! $found) {
            $entries[] = ['category' => $category, 'message' => $message, 'first' => $now, 'last' => $now, 'count' => 1];
        }
        update_option(self::LOG, array_slice($entries, -500), false);
    }

    /** Returns the configured retention period in days. */
    public static function retention(): int
    {
        return max(1, min(3650, (int) get_option('capconnect_log_retention', 30)));
    }

    /** Removes expired diagnostic entries. */
    public static function prune(): void
    {
        $entries = get_option(self::LOG, []);
        if (! is_array($entries)) {
            return;
        }
        $cutoff = time() - self::retention() * DAY_IN_SECONDS;
        update_option(self::LOG, array_values(array_filter($entries, static fn($entry) => is_array($entry) && ($entry['last'] ?? 0) >= $cutoff)), false);
    }

    /** Schedules one check without duplicating pending events. */
    public static function schedule(int $delay = 1): void
    {
        if (! wp_next_scheduled(self::HOOK)) {
            wp_schedule_single_event(time() + $delay, self::HOOK);
        }
    }

    /** Marks a successful verification as recovery and cancels stale retry events. */
    public static function recovered(): void
    {
        delete_option(self::STATE);
        delete_option('capconnect_connection_failed_notice');
        delete_option('capconnect_alert_sent');
        wp_clear_scheduled_hook(self::HOOK);
    }

    /** Serializes checks so concurrent requests cannot send duplicate alerts. */
    public static function check(): void
    {
        $lock = 'capconnect_monitor_lock';
        $existing = (int) get_option($lock, 0);
        if ($existing && $existing < time() - 120) {
            delete_option($lock);
        }
        if (! add_option($lock, time(), '', false)) {
            return;
        }
        try {
            self::runCheck();
        } finally {
            delete_option($lock);
        }
    }

    /** Confirms matching failures and retries failed mail delivery on subsequent checks. */
    private static function runCheck(): void
    {
        $result = Capconnect_Settings::checkConnection();
        if ($result['success']) {
            if (get_option(self::STATE)) {
                self::log('connection', 'Connection restored');
            }
            self::recovered();
            return;
        }
        $type = ($result['type'] ?? 'connection') === 'configuration' ? 'configuration' : 'connection';
        $message = $type === 'configuration' ? 'Configuration check failed' : 'Connection check failed';
        $reason = isset($result['reason']) ? (string) $result['reason'] : '';
        self::log($type, $message . ($reason !== '' ? ': ' . $reason : ''));
        $state = get_option(self::STATE, []);
        if (! is_array($state) || ($state['type'] ?? '') !== $type) {
            $state = ['type' => $type, 'first' => time(), 'sent' => false];
            update_option(self::STATE, $state, false);
            self::schedule(60);
            return;
        }
        if (time() < (int) $state['first'] + 60) {
            self::schedule(max(1, (int) $state['first'] + 60 - time()));
            return;
        }
        $detail = $type === 'configuration'
            ? __('The configured Cap endpoint, credentials, or allowed origin could not be verified.', 'capconnect-for-wp')
            : __('WordPress could not verify the configured Cap connection twice. Check the Cap service and the network connection from this website.', 'capconnect-for-wp');
        if ($reason !== '') {
            $detail .= ' ' . $reason;
        }
        update_option('capconnect_connection_failed_notice', $detail, false);
        $email = get_option('capconnect_alert_email', '');
        if (empty($email) || ! empty($state['sent'])) {
            return;
        }
        $subject = $type === 'configuration'
            ? __('Cap configuration problem', 'capconnect-for-wp')
            : __('Cap connection problem', 'capconnect-for-wp');
        $body = $detail . "\n\n" . sprintf(
            /* translators: %s: local date and time of the confirmed check */
            __('Confirmed at: %s', 'capconnect-for-wp'),
            wp_date(get_option('date_format') . ' ' . get_option('time_format'))
        );
        $sent = wp_mail($email, sprintf('[%s] %s', get_bloginfo('name'), $subject), $body);
        self::log('notification', $sent ? 'Alert accepted by mail system' : 'Alert mail failed');
        if ($sent) {
            $state['sent'] = true;
            update_option(self::STATE, $state, false);
        } else {
            self::schedule(60);
        }
    }

    /** Allows a new recipient to receive a still-confirmed incident. */
    public static function recipientChanged(): void
    {
        $state = get_option(self::STATE, []);
        if (is_array($state) && ! empty($state)) {
            $state['sent'] = false;
            update_option(self::STATE, $state, false);
            self::schedule();
        }
    }

    /** Renders the bounded diagnostic history for administrators. */
    public static function render(): void
    {
        if (! current_user_can('manage_options')) {
            return;
        }
        self::prune();
        $entries = get_option(self::LOG, []);
        echo '<h2>' . esc_html__('Debug log', 'capconnect-for-wp') . '</h2>';
        echo '<p>' . esc_html__('Browser errors do not by themselves indicate a server outage. Entries are grouped by type and time. Browser events are sampled at most once every 10 seconds per type; the log holds up to 500 groups.', 'capconnect-for-wp') . '</p>';
        echo '<table class="widefat striped"><thead><tr><th scope="col">' . esc_html__('Last occurrence', 'capconnect-for-wp') . '</th><th scope="col">' . esc_html__('Category', 'capconnect-for-wp') . '</th><th scope="col">' . esc_html__('Event', 'capconnect-for-wp') . '</th><th scope="col">' . esc_html__('Count', 'capconnect-for-wp') . '</th></tr></thead><tbody>';
        if (empty($entries) || ! is_array($entries)) {
            echo '<tr><td colspan="4">' . esc_html__('No events recorded.', 'capconnect-for-wp') . '</td></tr>';
        } else {
            foreach (array_reverse($entries) as $entry) {
                echo '<tr><td>' . esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $entry['last'])) . '</td><td>' . esc_html($entry['category']) . '</td><td>' . esc_html($entry['message']) . '</td><td>' . esc_html((string) $entry['count']) . '</td></tr>';
            }
        }
        echo '</tbody></table>';
    }
}
