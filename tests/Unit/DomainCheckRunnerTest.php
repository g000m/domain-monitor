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

    public function test_dns_checker_exception_degrades_gracefully(): void
    {
        $runner = new DomainCheckRunner(
            new ThrowingCheckerForRunner(new \RuntimeException('DNS timeout')),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Registrar', 'RDAP ok.')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertSame('degraded', $result['dns_status']);
        self::assertSame('DNS timeout', $result['dns_message']);
        // RDAP should still succeed independently.
        self::assertSame('ok', $result['rdap_status']);
    }

    public function test_rdap_checker_exception_degrades_gracefully(): void
    {
        $runner = new DomainCheckRunner(
            new FakeDnsCheckerForRunner(new DnsResult('ok', 'DNS ok.')),
            new ThrowingCheckerForRunner(new \RuntimeException('RDAP unreachable')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        // DNS should still succeed independently.
        self::assertSame('ok', $result['dns_status']);
        self::assertSame('degraded', $result['rdap_status']);
        self::assertSame('RDAP unreachable', $result['rdap_message']);
        self::assertNull($result['rdap_expires_at']);
    }

    public function test_both_checkers_throwing_produces_two_degraded_results(): void
    {
        $runner = new DomainCheckRunner(
            new ThrowingCheckerForRunner(new \RuntimeException('DNS down')),
            new ThrowingCheckerForRunner(new \RuntimeException('RDAP down')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertSame('degraded', $result['dns_status']);
        self::assertSame('degraded', $result['rdap_status']);
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

final class ThrowingCheckerForRunner
{
    private \Throwable $exception;

    public function __construct(\Throwable $exception)
    {
        $this->exception = $exception;
    }

    public function check(string $domain): void
    {
        throw $this->exception;
    }
}
