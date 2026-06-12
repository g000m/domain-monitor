<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

/**
 * Derives the single most-important fact line and orb colour for the ambient-status
 * orb widget rendered when exactly one domain is monitored.
 *
 * Priority order (highest first):
 *   1. Domain registration expired
 *   2. SSL certificate expired
 *   3. NS change / hijack alert open
 *   4. Domain expiring in N days
 *   5. SSL expiring in N days
 *   6. Registrar transfer-lock removed alert open
 *   7. SPF or DMARC regression alert open
 *   8. Generic check failed (catch-all for fail status)
 *
 * The class is intentionally stateless (pure function per call) so it is trivially
 * testable without any WordPress bootstrap.
 *
 * Row shape expected (all keys optional, defaults to empty string / empty array):
 *   'domain'           string
 *   'status'           string  ok | warn | fail | '' (never-checked)
 *   'message'          string  Human-readable reason from StatusCalculator
 *   'checked_at'       string  MySQL datetime of last check
 *   'expires_at'       string  ISO-8601 RDAP expiry (alias of rdap_expires_at for compat)
 *   'rdap_expires_at'  string  RDAP registration expiry
 *   'ssl_expires_at'   string  SSL certificate expiry
 *   'open_alert_types' string[]  e.g. ['ns_changed', 'spf_missing']
 */
final class OrbStatusPresenter
{
    // Orb colour tokens matching the design card CSS modifier classes.
    public const COLOR_GREEN = 'green';
    public const COLOR_AMBER = 'amber';
    public const COLOR_RED   = 'red';
    public const COLOR_GRAY  = 'gray';

    /** Days-remaining thresholds for expiry warnings (must match StatusCalculator). */
    private const DOMAIN_RED_DAYS   = 7;
    private const DOMAIN_AMBER_DAYS = 30;
    private const SSL_RED_DAYS      = 3;
    private const SSL_AMBER_DAYS    = 14;

    /**
     * Returns the CSS colour token for the orb.
     *
     * @param array<string,mixed> $row
     */
    public function color(array $row): string
    {
        $status = (string) ($row['status'] ?? '');

        if ($status === '') {
            return self::COLOR_GRAY;
        }

        if ($status === 'ok') {
            return self::COLOR_GREEN;
        }

        if ($status === 'warn') {
            return self::COLOR_AMBER;
        }

        // 'fail' or any other non-empty, non-ok value.
        return self::COLOR_RED;
    }

    /**
     * Returns the single most-important human-readable fact line for amber/red states.
     * Returns an empty string for green (no fact line needed) and gray.
     *
     * @param array<string,mixed> $row
     */
    public function factLine(array $row): string
    {
        $color = $this->color($row);

        if ($color === self::COLOR_GREEN || $color === self::COLOR_GRAY) {
            return '';
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));

        // --- Priority 1: Domain registration expired ---
        $rdapExpiry = $this->resolveExpiry($row, ['rdap_expires_at', 'expires_at']);
        if ($rdapExpiry !== null && $rdapExpiry < $now) {
            return 'Domain registration expired';
        }

        // --- Priority 2: SSL certificate expired ---
        $sslExpiry = $this->resolveExpiry($row, ['ssl_expires_at']);
        if ($sslExpiry !== null && $sslExpiry < $now) {
            return 'SSL certificate expired';
        }

        // --- Priority 3: NS change / hijack alert ---
        $alertTypes = $this->openAlertTypes($row);
        if (in_array('ns_changed', $alertTypes, true)) {
            return 'Nameserver change detected — possible hijack';
        }

        // --- Priority 4: Domain expiring in N days ---
        if ($rdapExpiry !== null) {
            $daysLeft = $this->daysUntil($rdapExpiry, $now);
            if ($daysLeft >= 0 && $daysLeft <= self::DOMAIN_RED_DAYS) {
                return 'Domain expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
            }
            if ($daysLeft >= 0 && $daysLeft <= self::DOMAIN_AMBER_DAYS) {
                return 'Domain expires in ' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's');
            }
        }

        // --- Priority 5: SSL expiring in N days ---
        if ($sslExpiry !== null) {
            $sslDaysLeft = $this->daysUntil($sslExpiry, $now);
            if ($sslDaysLeft >= 0 && $sslDaysLeft <= self::SSL_RED_DAYS) {
                return 'SSL expires in ' . $sslDaysLeft . ' day' . ($sslDaysLeft === 1 ? '' : 's');
            }
            if ($sslDaysLeft >= 0 && $sslDaysLeft <= self::SSL_AMBER_DAYS) {
                return 'SSL expires in ' . $sslDaysLeft . ' day' . ($sslDaysLeft === 1 ? '' : 's');
            }
        }

        // --- Priority 6: Transfer lock removed ---
        if (in_array('transfer_lock_removed', $alertTypes, true)) {
            return 'Registrar transfer lock removed';
        }

        // --- Priority 7: SPF or DMARC regression ---
        if (in_array('spf_missing', $alertTypes, true)) {
            return 'SPF record missing';
        }
        if (in_array('dmarc_missing', $alertTypes, true)) {
            return 'DMARC record missing';
        }

        // --- Priority 8: Generic fallback (StatusCalculator message or generic) ---
        $message = (string) ($row['message'] ?? '');
        if ($message !== '') {
            return $message;
        }

        return 'Check failed';
    }

    /**
     * Returns a human-readable time difference for the "last checked" subtitle.
     * Uses human_time_diff() when the WordPress function is available.
     *
     * @param array<string,mixed> $row
     */
    public function checkedAgoText(array $row): string
    {
        $checkedAt = (string) ($row['checked_at'] ?? '');
        if ($checkedAt === '') {
            return '';
        }

        $timestamp = strtotime($checkedAt);
        if ($timestamp === false) {
            return '';
        }

        if (function_exists('human_time_diff')) {
            return human_time_diff($timestamp, time()) . ' ago';
        }

        $diff = time() - $timestamp;
        if ($diff < 60) {
            return 'moments ago';
        }
        if ($diff < 3600) {
            $mins = (int) round($diff / 60);
            return $mins . ' min' . ($mins === 1 ? '' : 's') . ' ago';
        }
        if ($diff < 86400) {
            $hours = (int) round($diff / 3600);
            return $hours . ' hour' . ($hours === 1 ? '' : 's') . ' ago';
        }
        $days = (int) round($diff / 86400);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    /**
     * Try each key in order; return a parsed DateTimeImmutable on the first non-empty
     * ISO-8601 string found, or null if none parse.
     *
     * @param array<string,mixed> $row
     * @param list<string> $keys
     */
    private function resolveExpiry(array $row, array $keys): ?\DateTimeImmutable
    {
        foreach ($keys as $key) {
            $val = isset($row[$key]) ? (string) $row[$key] : '';
            if ($val === '') {
                continue;
            }
            try {
                return new \DateTimeImmutable($val, new \DateTimeZone('UTC'));
            } catch (\Exception $e) {
                // Try next key.
            }
        }
        return null;
    }

    /**
     * Returns whole days until $expiry from $now; negative if already past.
     */
    private function daysUntil(\DateTimeImmutable $expiry, \DateTimeImmutable $now): int
    {
        $diff = $now->diff($expiry);
        // diff->invert = 1 means expiry is in the past.
        return $diff->invert === 1 ? -((int) $diff->days) : (int) $diff->days;
    }

    /**
     * @param array<string,mixed> $row
     * @return list<string>
     */
    private function openAlertTypes(array $row): array
    {
        $types = $row['open_alert_types'] ?? [];
        if (! is_array($types)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $types)));
    }
}
