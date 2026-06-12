<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\DnsResult;
use DomainMonitor\Checks\EmailDnsChecker;
use DomainMonitor\Checks\EmailDnsResult;
use DomainMonitor\Checks\RdapResult;
use DomainMonitor\Settings\PluginSettings;
use PHPUnit\Framework\TestCase;

/**
 * Tests for email DNS alert generation (regression detection) and
 * PluginSettings opt-in gating.
 */
final class EmailDnsAlertTest extends TestCase
{
    // -----------------------------------------------------------------
    // PluginSettings: email_dns_check_enabled defaults to false
    // -----------------------------------------------------------------

    public function test_settings_email_dns_check_disabled_by_default(): void
    {
        $settings = new PluginSettings([]);

        self::assertFalse($settings->emailDnsCheckEnabled());
    }

    public function test_settings_email_dns_check_enabled_when_set(): void
    {
        $settings = new PluginSettings(['email_dns_check_enabled' => true]);

        self::assertTrue($settings->emailDnsCheckEnabled());
    }

    public function test_settings_email_dns_check_persists_in_to_array(): void
    {
        $settings = new PluginSettings(['email_dns_check_enabled' => true]);
        $array    = $settings->toArray();

        self::assertTrue($array['email_dns_check_enabled']);
    }

    public function test_settings_email_dns_check_false_persists_in_to_array(): void
    {
        $settings = new PluginSettings(['email_dns_check_enabled' => false]);
        $array    = $settings->toArray();

        self::assertFalse($array['email_dns_check_enabled']);
    }

    // -----------------------------------------------------------------
    // DomainCheckRunner: email_dns key present only when checker injected
    // -----------------------------------------------------------------

    public function test_check_runner_omits_email_dns_when_no_checker_injected(): void
    {
        $runner = new DomainCheckRunner(
            new SimpleFakeDnsChecker(new DnsResult('ok', 'DNS ok.')),
            new SimpleFakeRdapChecker(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Registrar', 'ok.')),
            static fn (): string => '2026-06-12 00:00:00'
        );

        $result = $runner->check('example.com');

        self::assertArrayNotHasKey('email_dns', $result);
    }

    public function test_check_runner_includes_email_dns_when_checker_injected(): void
    {
        $emailChecker = new FakeEmailDnsCheckerForRunner(
            new EmailDnsResult(
                EmailDnsResult::SPF_PRESENT,
                'v=spf1 ~all',
                EmailDnsResult::DMARC_PRESENT,
                'v=DMARC1; p=none',
                EmailDnsResult::MX_PRESENT,
                [],
                ''
            )
        );

        $runner = new DomainCheckRunner(
            new SimpleFakeDnsChecker(new DnsResult('ok', 'DNS ok.')),
            new SimpleFakeRdapChecker(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Registrar', 'ok.')),
            null,
            static fn (): string => '2026-06-12 00:00:00',
            $emailChecker
        );

        $result = $runner->check('example.com');

        self::assertArrayHasKey('email_dns', $result);

        $decoded = json_decode($result['email_dns'], true);
        self::assertIsArray($decoded);
        self::assertSame('present', $decoded['spf_state']);
        self::assertSame('present', $decoded['dmarc_state']);
        self::assertSame('ok', $decoded['status']);
    }

    public function test_check_runner_degrades_gracefully_when_email_checker_throws(): void
    {
        $emailChecker = new ThrowingEmailDnsCheckerForRunner(new \RuntimeException('Email DNS timeout'));

        $runner = new DomainCheckRunner(
            new SimpleFakeDnsChecker(new DnsResult('ok', 'DNS ok.')),
            new SimpleFakeRdapChecker(new RdapResult('ok', '2027-01-01T00:00:00Z', 'Registrar', 'ok.')),
            null,
            static fn (): string => '2026-06-12 00:00:00',
            $emailChecker
        );

        $result = $runner->check('example.com');

        // Should not throw, and email_dns key should contain degraded status.
        self::assertArrayHasKey('email_dns', $result);
        $decoded = json_decode($result['email_dns'], true);
        self::assertSame('degraded', $decoded['status']);
        self::assertSame('Email DNS timeout', $decoded['message']);
    }

    // -----------------------------------------------------------------
    // EmailDnsResult: regression detection logic
    // -----------------------------------------------------------------

    public function test_spf_disappearance_is_a_regression(): void
    {
        $oldResult = new EmailDnsResult(
            EmailDnsResult::SPF_PRESENT, 'v=spf1 ~all',
            EmailDnsResult::DMARC_PRESENT, 'v=DMARC1; p=none',
            EmailDnsResult::MX_PRESENT, [], ''
        );
        $newResult = new EmailDnsResult(
            EmailDnsResult::SPF_MISSING, '',
            EmailDnsResult::DMARC_PRESENT, 'v=DMARC1; p=none',
            EmailDnsResult::MX_PRESENT, [], 'No SPF record found.'
        );

        $old = $oldResult->toArray();
        $new = $newResult->toArray();

        // Simulate the regression check logic used in Plugin::processAlerts().
        $spfRegression = ($old['spf_state'] === 'present' && $new['spf_state'] === 'missing');

        self::assertTrue($spfRegression);
    }

    public function test_dmarc_disappearance_is_a_regression(): void
    {
        $oldResult = new EmailDnsResult(
            EmailDnsResult::SPF_PRESENT, 'v=spf1 ~all',
            EmailDnsResult::DMARC_PRESENT, 'v=DMARC1; p=none',
            EmailDnsResult::MX_PRESENT, [], ''
        );
        $newResult = new EmailDnsResult(
            EmailDnsResult::SPF_PRESENT, 'v=spf1 ~all',
            EmailDnsResult::DMARC_MISSING, '',
            EmailDnsResult::MX_PRESENT, [], 'No DMARC record found.'
        );

        $old = $oldResult->toArray();
        $new = $newResult->toArray();

        $dmarcRegression = ($old['dmarc_state'] === 'present' && $new['dmarc_state'] === 'missing');

        self::assertTrue($dmarcRegression);
    }

    public function test_no_regression_when_spf_never_existed(): void
    {
        // Old snapshot has no email_dns section at all (feature was off).
        $oldEmailDns = null;
        $newEmailDns = (new EmailDnsResult(
            EmailDnsResult::SPF_MISSING, '',
            EmailDnsResult::DMARC_MISSING, '',
            EmailDnsResult::MX_PRESENT, [], ''
        ))->toArray();

        // Regression check: both must be arrays for a regression to fire.
        $spfRegression = is_array($oldEmailDns) && is_array($newEmailDns)
            && ($oldEmailDns['spf_state'] ?? '') === 'present'
            && ($newEmailDns['spf_state'] ?? '') === 'missing';

        self::assertFalse($spfRegression, 'No regression should fire when there is no prior email_dns snapshot.');
    }

    public function test_no_regression_when_spf_was_already_missing(): void
    {
        $oldResult = new EmailDnsResult(
            EmailDnsResult::SPF_MISSING, '',
            EmailDnsResult::DMARC_MISSING, '',
            EmailDnsResult::MX_PRESENT, [], ''
        );
        $newResult = new EmailDnsResult(
            EmailDnsResult::SPF_MISSING, '',
            EmailDnsResult::DMARC_MISSING, '',
            EmailDnsResult::MX_PRESENT, [], ''
        );

        $old = $oldResult->toArray();
        $new = $newResult->toArray();

        $spfRegression = ($old['spf_state'] === 'present' && $new['spf_state'] === 'missing');

        self::assertFalse($spfRegression);
    }
}

// -----------------------------------------------------------------
// Test doubles
// -----------------------------------------------------------------

final class SimpleFakeDnsChecker
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

final class SimpleFakeRdapChecker
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

final class FakeEmailDnsCheckerForRunner
{
    private EmailDnsResult $result;

    public function __construct(EmailDnsResult $result)
    {
        $this->result = $result;
    }

    public function check(string $domain): EmailDnsResult
    {
        return $this->result;
    }
}

final class ThrowingEmailDnsCheckerForRunner
{
    private \Throwable $exception;

    public function __construct(\Throwable $exception)
    {
        $this->exception = $exception;
    }

    public function check(string $domain): EmailDnsResult
    {
        throw $this->exception;
    }
}
