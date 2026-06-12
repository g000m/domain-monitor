<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\DnsResult;
use DomainMonitor\Checks\RdapResult;
use DomainMonitor\Checks\SslResult;
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
        // DnsResult with no records -> dns_apex omitted.
        self::assertArrayNotHasKey('dns_apex', $result);
    }

    public function test_dns_apex_is_included_when_checker_returns_records(): void
    {
        $apexRecords = [
            'a'    => ['1.2.3.4'],
            'aaaa' => [],
            'ns'   => ['ns1.example.com', 'ns2.example.com'],
            'mx'   => [],
        ];

        $runner = new DomainCheckRunner(
            new FakeDnsCheckerForRunner(new DnsResult('ok', 'DNS ok.', $apexRecords)),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Example Registrar', 'RDAP ok.')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertArrayHasKey('dns_apex', $result);
        $decoded = json_decode($result['dns_apex'], true);
        self::assertSame(['1.2.3.4'], $decoded['a']);
        self::assertSame(['ns1.example.com', 'ns2.example.com'], $decoded['ns']);
        self::assertSame([], $decoded['mx']);
    }

    public function test_dns_apex_is_absent_when_dns_checker_throws(): void
    {
        $runner = new DomainCheckRunner(
            new ThrowingCheckerForRunner(new \RuntimeException('DNS timeout')),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Registrar', 'RDAP ok.')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertSame('degraded', $result['dns_status']);
        self::assertArrayNotHasKey('dns_apex', $result);
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

    /**
     * Bug 1 regression: SslChecker must be wired in production. This test verifies
     * that DomainCheckRunner correctly routes an SslChecker instance (passed as the
     * third argument) and populates ssl_* keys in the result. The Plugin factory
     * path is private, but this covers the constructor-level contract that the fix
     * relies on.
     */
    public function test_ssl_checker_passed_as_third_arg_populates_ssl_keys(): void
    {
        $runner = new DomainCheckRunner(
            new FakeDnsCheckerForRunner(new DnsResult('ok', 'DNS ok.')),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Example Registrar', 'RDAP ok.')),
            new FakeSslCheckerForRunner(new SslResult('ok', '2027-06-01T00:00:00Z', 'Let\'s Encrypt', '')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertSame('ok', $result['ssl_status'], 'ssl_status must be populated when SslChecker is provided.');
        self::assertSame('2027-06-01T00:00:00Z', $result['ssl_expires_at']);
        self::assertSame("Let's Encrypt", $result['ssl_issuer']);
    }

    public function test_ssl_keys_are_null_when_no_ssl_checker_provided(): void
    {
        // Passing a callable as arg 3 is the old clock-only form; no SSL checker.
        $runner = new DomainCheckRunner(
            new FakeDnsCheckerForRunner(new DnsResult('ok', 'DNS ok.')),
            new FakeRdapCheckerForRunner(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Example Registrar', 'RDAP ok.')),
            static fn (): string => '2026-05-26 21:30:00'
        );

        $result = $runner->check('example.com');

        self::assertNull($result['ssl_status'], 'ssl_status must be null when no SslChecker is injected.');
        self::assertNull($result['ssl_expires_at']);
        self::assertNull($result['ssl_issuer']);
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

final class FakeSslCheckerForRunner
{
    private SslResult $result;

    public function __construct(SslResult $result)
    {
        $this->result = $result;
    }

    public function check(string $domain): SslResult
    {
        return $this->result;
    }
}
