<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Domain\ExpirationDate;
use PHPUnit\Framework\TestCase;

final class ExpirationDateTest extends TestCase
{
    public function test_it_formats_rdap_timestamp_as_readable_utc_date(): void
    {
        self::assertSame('2027-01-01', ExpirationDate::label('2027-01-01T00:00:00Z'));
    }

    public function test_it_returns_unknown_when_rdap_does_not_provide_expiration(): void
    {
        self::assertSame('Unknown', ExpirationDate::label(''));
    }
}
