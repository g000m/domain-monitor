<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

use DateTimeImmutable;

final class StatusCalculator
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARN = 'warn';
    public const STATUS_FAIL = 'fail';

    private const RECENT_RECOVERY_DAYS  = 3;
    private const EXPIRY_RED_DAYS       = 7;
    private const EXPIRY_AMBER_DAYS     = 30;

    /**
     * @param array<string,mixed> $snapshot
     * @param list<array<string,mixed>> $alerts
     */
    public function calculate(array $snapshot, array $alerts, DateTimeImmutable $now): DomainStatus
    {
        foreach ($alerts as $alert) {
            if (($alert['is_active'] ?? false) === true) {
                $severity = (string) ($alert['severity'] ?? self::STATUS_WARN);
                return new DomainStatus($severity, 'Active domain alert detected.');
            }
        }

        $expiresAt = $snapshot['rdap']['expires_at'] ?? null;
        if (is_string($expiresAt) && $expiresAt !== '') {
            $expiry = new DateTimeImmutable($expiresAt);
            if ($expiry < $now) {
                return new DomainStatus(self::STATUS_FAIL, 'Domain registration appears to be expired.');
            }

            $redThreshold   = $now->modify('+' . self::EXPIRY_RED_DAYS . ' days');
            $amberThreshold = $now->modify('+' . self::EXPIRY_AMBER_DAYS . ' days');

            if ($expiry <= $redThreshold) {
                return new DomainStatus(self::STATUS_FAIL, 'Domain registration expires within ' . self::EXPIRY_RED_DAYS . ' days.');
            }

            if ($expiry <= $amberThreshold) {
                return new DomainStatus(self::STATUS_WARN, 'Domain registration expires within ' . self::EXPIRY_AMBER_DAYS . ' days.');
            }
        }

        // SSL is intentionally NOT a headline driver. TLS detail (validity,
        // expiry, issuer, and any inconclusive "could not verify" result) is
        // recorded on the snapshot and shown in the settings page, but it never
        // raises the dashboard status or the admin banner on its own. Rationale:
        //   - A connect failure from the monitor is inconclusive, not a fault, and
        //     proactively warning a healthy site is how a monitor loses trust.
        //   - Even a genuine cert problem is visible the moment an admin opens the
        //     plugin; it does not warrant an unsolicited site-wide warning here.
        // See SslChecker, which reports 'unknown' rather than 'degraded' for a
        // failed connection. ('ssl' is deliberately absent from the loop below.)

        foreach (['rdap', 'dns', 'http'] as $check) {
            $checkStatus = $snapshot[$check]['status'] ?? null;
            if ($checkStatus === 'fail') {
                return new DomainStatus(self::STATUS_FAIL, ucfirst($check) . ' check failed.');
            }

            if ($checkStatus === 'degraded') {
                $message = $snapshot[$check]['message'] ?? ucfirst($check) . ' check is degraded.';
                return new DomainStatus(self::STATUS_WARN, (string) $message);
            }
        }

        foreach ($alerts as $alert) {
            if (($alert['is_active'] ?? true) === false && isset($alert['resolved_at']) && is_string($alert['resolved_at'])) {
                $resolvedAt = new DateTimeImmutable($alert['resolved_at']);
                if ($resolvedAt->modify('+' . self::RECENT_RECOVERY_DAYS . ' days') >= $now) {
                    return new DomainStatus(self::STATUS_WARN, 'Recently recovered from a domain alert.');
                }
            }
        }

        return new DomainStatus(self::STATUS_OK, 'All domain checks are clear.');
    }
}
