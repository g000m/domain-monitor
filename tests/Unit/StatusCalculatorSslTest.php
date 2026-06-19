<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DateTimeImmutable;
use DomainMonitor\Domain\StatusCalculator;
use PHPUnit\Framework\TestCase;

/**
 * SSL is intentionally NOT a headline driver. These tests lock in that contract:
 * no SSL condition (valid, expiring, expired, or an inconclusive connect failure)
 * may raise the dashboard status on its own. TLS detail lives in the settings page.
 */
final class StatusCalculatorSslTest extends TestCase
{
    /** @param array<string,mixed> $ssl */
    private function baseSnapshot(array $ssl = []): array
    {
        return [
            'rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z'],
            'dns'  => ['status' => 'ok'],
            'ssl'  => $ssl,
        ];
    }

    public function test_valid_ssl_cert_does_not_affect_status(): void
    {
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2027-01-01T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_expired_ssl_cert_does_not_raise_status(): void
    {
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-05-01T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        // Even a genuinely expired cert is not surfaced as a headline alarm; the
        // admin sees TLS detail in settings. This is a deliberate product call.
        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_ssl_cert_expiring_soon_does_not_raise_status(): void
    {
        // Two days from now - previously an SSL "red". No longer a headline driver.
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-06-03T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_inconclusive_ssl_check_does_not_raise_status(): void
    {
        // SslChecker reports 'unknown' when it cannot complete the check. That must
        // never alarm: a flaky monitor cannot make a healthy site look broken.
        $snapshot = $this->baseSnapshot([
            'status'  => 'unknown',
            'message' => 'Could not verify the TLS certificate. The monitor could not reach port 443.',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_legacy_degraded_ssl_status_does_not_raise_status(): void
    {
        // Guard against regressions for any snapshot still carrying the old
        // 'degraded' SSL status: SSL is out of the headline loop entirely.
        $snapshot = $this->baseSnapshot([
            'status'  => 'degraded',
            'message' => 'Could not connect to example.com on port 443.',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_no_ssl_section_in_snapshot_does_not_affect_status(): void
    {
        $snapshot = [
            'rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z'],
            'dns'  => ['status' => 'ok'],
        ];

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }
}
