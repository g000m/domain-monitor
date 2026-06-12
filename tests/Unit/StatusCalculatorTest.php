<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DateTimeImmutable;
use DomainMonitor\Domain\StatusCalculator;
use PHPUnit\Framework\TestCase;

final class StatusCalculatorTest extends TestCase
{
    public function test_all_clear_snapshot_without_alerts_is_green(): void
    {
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2027-01-01T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_rdap_lookup_trouble_is_amber_with_clear_message(): void
    {
        $status = (new StatusCalculator())->calculate([
            'rdap' => [
                'status' => 'degraded',
                'message' => 'We had trouble looking up registration details for this TLD.',
            ],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('trouble looking up registration details', $status->message());
    }

    public function test_expired_domain_is_red(): void
    {
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-05-24T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_FAIL, $status->code());
    }

    public function test_recently_resolved_alert_remains_amber_for_default_three_days(): void
    {
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2027-01-01T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [
            ['severity' => StatusCalculator::STATUS_FAIL, 'is_active' => false, 'resolved_at' => '2026-05-23T00:00:00Z'],
        ], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('Recently recovered', $status->message());
    }

    public function test_domain_expiring_within_7_days_is_red(): void
    {
        // Expiry is 5 days away; inside the 7-day red threshold.
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-05-30T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_FAIL, $status->code());
        self::assertStringContainsString('expires within 7 days', $status->message());
    }

    public function test_domain_expiring_between_7_and_30_days_is_amber(): void
    {
        // Expiry is 20 days away; outside red (7d), inside amber (30d).
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-06-14T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('expires within 30 days', $status->message());
    }

    public function test_domain_expiring_beyond_30_days_is_green(): void
    {
        // Expiry is 60 days away; outside both thresholds.
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-07-24T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_OK, $status->code());
    }

    public function test_domain_expiring_exactly_at_7_day_boundary_is_red(): void
    {
        // Expiry is exactly 7 days away; at the boundary, should be red.
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-06-01T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_FAIL, $status->code());
    }

    public function test_domain_expiring_exactly_at_30_day_boundary_is_amber(): void
    {
        // Expiry is exactly 30 days away; at the amber boundary (<= comparison), should be warn.
        // now = 2026-05-25, expiry = 2026-06-24 (exactly 30 days later).
        $status = (new StatusCalculator())->calculate([
            'rdap' => ['status' => 'ok', 'expires_at' => '2026-06-24T00:00:00Z'],
            'dns' => ['status' => 'ok'],
            'http' => ['status' => 'ok'],
            'errors' => [],
        ], [], new DateTimeImmutable('2026-05-25T00:00:00Z'));

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code());
        self::assertStringContainsString('expires within 30 days', $status->message());
    }
}
