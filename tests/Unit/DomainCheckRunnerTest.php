<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\DnsResult;
use DomainMonitor\Checks\RdapResult;
use PHPUnit\Framework\TestCase;

final class DomainCheckRunnerTest extends TestCase
{
    public function test_it_combines_dns_and_rdap_results_for_storage(): void
    {
        $runner = new DomainCheckRunner(
            new FakeDnsCheckerForRunner(new DnsResult('ok', 'DNS found records.')),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Example Registrar', 'RDAP ok.')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertSame('ok', $result['dns_status']);
        self::assertSame('DNS found records.', $result['dns_message']);
        self::assertSame('ok', $result['rdap_status']);
        self::assertSame('RDAP ok.', $result['rdap_message']);
        self::assertSame('Example Registrar', $result['rdap_registrar']);
        self::assertSame('2027-01-01T00:00:00Z', $result['rdap_expires_at']);
        self::assertSame('2026-05-26 21:30:00', $result['last_checked_at']);
    }
}

final class FakeDnsCheckerForRunner
{
    private DnsResult $result;

    public function __construct(DnsResult $result)
    {
        $this->result = $result;
    }

    public function check(string $domain): DnsResult
    {
        return $this->result;
    }
}

final class FakeRdapCheckerForRunner
{
    private RdapResult $result;

    public function __construct(RdapResult $result)
    {
        $this->result = $result;
    }

    public function check(string $domain): RdapResult
    {
        return $this->result;
    }
}
