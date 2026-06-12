<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Storage\DomainRecord;
use PHPUnit\Framework\TestCase;

final class DomainRecordStatusCodeTest extends TestCase
{
    public function test_status_code_is_ok_for_healthy_domain(): void
    {
        $record = new DomainRecord([
            'id'     => 1,
            'domain' => 'example.com',
            'source' => 'auto',
            'snapshot' => [
                'rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z', 'message' => '', 'registrar' => '', 'tier' => 0],
                'dns'  => ['status' => 'ok', 'message' => ''],
            ],
        ]);

        self::assertSame(StatusCalculator::STATUS_OK, $record->statusCode());
    }

    public function test_status_code_is_fail_for_expired_domain(): void
    {
        $record = new DomainRecord([
            'id'     => 1,
            'domain' => 'example.com',
            'source' => 'auto',
            'snapshot' => [
                'rdap' => ['status' => 'ok', 'expires_at' => '2020-01-01T00:00:00Z', 'message' => '', 'registrar' => '', 'tier' => 0],
                'dns'  => ['status' => 'ok', 'message' => ''],
            ],
        ]);

        self::assertSame(StatusCalculator::STATUS_FAIL, $record->statusCode());
    }

    public function test_status_code_is_warn_for_degraded_dns(): void
    {
        $record = new DomainRecord([
            'id'     => 1,
            'domain' => 'example.com',
            'source' => 'auto',
            'snapshot' => [
                'rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z', 'message' => '', 'registrar' => '', 'tier' => 0],
                'dns'  => ['status' => 'degraded', 'message' => 'DNS lookup slow.'],
            ],
        ]);

        self::assertSame(StatusCalculator::STATUS_WARN, $record->statusCode());
    }

    public function test_status_code_is_warn_for_never_checked_domain(): void
    {
        // A record with no snapshot and no last_checked_at (freshly inserted,
        // not yet checked) should report warn so the admin knows it needs attention.
        $record = new DomainRecord([
            'id'     => 1,
            'domain' => 'example.com',
            'source' => 'auto',
        ]);

        self::assertSame(StatusCalculator::STATUS_WARN, $record->statusCode());
    }
}
