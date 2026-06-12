<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DateTimeImmutable;
use DomainMonitor\Domain\StatusCalculator;
use PHPUnit\Framework\TestCase;

final class StatusCalculatorSslTest extends TestCase
{
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

    public function test_ssl_cert_expired_is_red(): void
    {
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-05-01T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_FAIL, $status->code());
        self::assertStringContainsString('SSL certificate has expired', $status->message());
    }

    public function test_ssl_cert_expiring_within_3_days_is_red(): void
    {
        // Expiry 2 days away - inside the 3-day red threshold.
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-06-03T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_FAIL, $status->code());
        self::assertStringContainsString('3 days', $status->message());
    }

    public function test_ssl_cert_expiring_within_14_days_is_amber(): void
    {
        // Expiry 10 days away - between 3-day red and 14-day amber threshold.
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-06-11T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('14 days', $status->message());
    }

    public function test_ssl_cert_beyond_14_days_is_green(): void
    {
        // Expiry 30 days away - outside the amber threshold.
        $snapshot = $this->baseSnapshot([
            'status'     => 'ok',
            'expires_at' => '2026-07-01T00:00:00Z',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_ssl_degraded_status_returns_warn(): void
    {
        $snapshot = $this->baseSnapshot([
            'status'  => 'degraded',
            'message' => 'Could not connect to example.com on port 443.',
        ]);

        $status = (new StatusCalculator())->calculate($snapshot, [], new DateTimeImmutable('2026-06-01T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('connect to example.com', $status->message());
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
