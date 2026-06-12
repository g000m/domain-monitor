<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\EmailDnsChecker;
use DomainMonitor\Checks\EmailDnsResult;
use PHPUnit\Framework\TestCase;

final class EmailDnsCheckerTest extends TestCase
{
    // -----------------------------------------------------------------
    // SPF tests
    // -----------------------------------------------------------------

    public function test_spf_present_with_valid_record(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'          => [['txt' => 'v=spf1 include:_spf.google.com ~all']],
            '_dmarc.example.com:TXT'   => [['txt' => 'v=DMARC1; p=none; rua=mailto:dmarc@example.com']],
            'example.com:MX'           => [['txt' => '']],  // non-empty array = MX present
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_PRESENT, $result->spfState());
        self::assertSame('v=spf1 include:_spf.google.com ~all', $result->spfRecord());
    }

    public function test_spf_missing_when_no_txt_records(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_MISSING, $result->spfState());
    }

    public function test_spf_missing_when_txt_records_exist_but_no_spf(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'some-other-txt-record']],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_MISSING, $result->spfState());
    }

    public function test_spf_multiple_when_more_than_one_spf_record(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT' => [
                ['txt' => 'v=spf1 include:_spf.google.com ~all'],
                ['txt' => 'v=spf1 include:mailchimp.com ~all'],
            ],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_MULTIPLE, $result->spfState());
        self::assertStringContainsString('Multiple SPF', $result->message());
    }

    public function test_spf_invalid_when_no_all_qualifier_and_no_redirect(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 include:_spf.google.com']],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_INVALID, $result->spfState());
    }

    public function test_spf_present_with_redirect_mechanism(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 redirect=_spf.example.com']],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_PRESENT, $result->spfState());
    }

    public function test_spf_present_with_hard_fail_all(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 ip4:192.0.2.0/24 -all']],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_PRESENT, $result->spfState());
    }

    // -----------------------------------------------------------------
    // DMARC tests
    // -----------------------------------------------------------------

    public function test_dmarc_present_with_p_none(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 ~all']],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=none']],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::DMARC_PRESENT, $result->dmarcState());
        self::assertSame('v=DMARC1; p=none', $result->dmarcRecord());
    }

    public function test_dmarc_present_with_p_reject(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 ~all']],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=reject; rua=mailto:r@example.com']],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::DMARC_PRESENT, $result->dmarcState());
    }

    public function test_dmarc_missing_when_no_txt_at_dmarc_host(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::DMARC_MISSING, $result->dmarcState());
        self::assertStringContainsString('No DMARC', $result->message());
    }

    public function test_dmarc_invalid_when_v_dmarc1_but_no_p_tag(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; rua=mailto:dmarc@example.com']],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::DMARC_INVALID, $result->dmarcState());
    }

    // -----------------------------------------------------------------
    // MX tests
    // -----------------------------------------------------------------

    public function test_mx_present_when_mx_records_exist(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::MX_PRESENT, $result->mxState());
    }

    public function test_mx_missing_when_no_mx_records(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::MX_MISSING, $result->mxState());
        self::assertStringContainsString('No MX', $result->message());
    }

    // -----------------------------------------------------------------
    // DKIM tests (informational)
    // -----------------------------------------------------------------

    public function test_dkim_found_selectors_reported_when_records_exist(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'                        => [],
            '_dmarc.example.com:TXT'                 => [],
            'example.com:MX'                         => [],
            'google._domainkey.example.com:TXT'      => [['txt' => 'v=DKIM1; k=rsa; p=MIIB...']],
            'selector1._domainkey.example.com:TXT'   => [['txt' => 'v=DKIM1; k=rsa; p=MIIC...']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertContains('google', $result->dkimFoundSelectors());
        self::assertContains('selector1', $result->dkimFoundSelectors());
        self::assertStringContainsString('DKIM found', $result->message());
    }

    public function test_dkim_empty_selectors_when_none_found(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame([], $result->dkimFoundSelectors());
        // Absence of DKIM is not a warning.
        self::assertNotSame('fail', $result->overallStatus());
    }

    // -----------------------------------------------------------------
    // Overall status tests
    // -----------------------------------------------------------------

    public function test_overall_status_ok_when_spf_dmarc_present(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 include:example.net ~all']],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=quarantine']],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame('ok', $result->overallStatus());
    }

    public function test_overall_status_warn_when_spf_missing(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=none']],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame('warn', $result->overallStatus());
    }

    public function test_overall_status_warn_when_dmarc_missing(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 ~all']],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame('warn', $result->overallStatus());
    }

    public function test_overall_status_degraded_when_spf_invalid(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 include:mailhost.example.com']],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=none']],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame('degraded', $result->overallStatus());
    }

    // -----------------------------------------------------------------
    // toArray() / serialisation
    // -----------------------------------------------------------------

    public function test_to_array_contains_all_keys(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['txt' => 'v=spf1 ~all']],
            '_dmarc.example.com:TXT' => [['txt' => 'v=DMARC1; p=none']],
            'example.com:MX'         => [['pri' => 10, 'target' => 'mail.example.com']],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');
        $array  = $result->toArray();

        self::assertArrayHasKey('spf_state', $array);
        self::assertArrayHasKey('spf_record', $array);
        self::assertArrayHasKey('dmarc_state', $array);
        self::assertArrayHasKey('dmarc_record', $array);
        self::assertArrayHasKey('mx_state', $array);
        self::assertArrayHasKey('dkim_found_selectors', $array);
        self::assertArrayHasKey('message', $array);
        self::assertArrayHasKey('status', $array);
    }

    // -----------------------------------------------------------------
    // Resolver error isolation
    // -----------------------------------------------------------------

    public function test_resolver_exception_degrades_to_missing(): void
    {
        $resolver = new ThrowingEmailDnsResolver();

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        // All states should degrade to missing, not throw.
        self::assertSame(EmailDnsResult::SPF_MISSING, $result->spfState());
        self::assertSame(EmailDnsResult::DMARC_MISSING, $result->dmarcState());
        self::assertSame(EmailDnsResult::MX_MISSING, $result->mxState());
    }

    // -----------------------------------------------------------------
    // entries[] format (multi-string TXT records per RFC 4408)
    // -----------------------------------------------------------------

    public function test_spf_extracted_from_entries_array(): void
    {
        $resolver = new FakeEmailDnsResolver([
            'example.com:TXT'        => [['entries' => ['v=spf1 include:_spf.example.net ', '~all']]],
            '_dmarc.example.com:TXT' => [],
            'example.com:MX'         => [],
        ]);

        $result = (new EmailDnsChecker($resolver))->check('example.com');

        self::assertSame(EmailDnsResult::SPF_PRESENT, $result->spfState());
    }
}

// -----------------------------------------------------------------
// Test doubles
// -----------------------------------------------------------------

/**
 * Fake resolver that returns pre-seeded records per "domain:TYPE" key.
 * Any key not seeded returns an empty array (no record found).
 */
final class FakeEmailDnsResolver
{
    /** @var array<string, list<array<string,mixed>>> */
    private array $records;

    /** @param array<string, list<array<string,mixed>>> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    /** @return list<array<string,mixed>> */
    public function records(string $domain, string $type): array
    {
        $key = $domain . ':' . $type;
        return $this->records[$key] ?? [];
    }
}

/**
 * Resolver that throws on every call — tests error isolation.
 */
final class ThrowingEmailDnsResolver
{
    public function records(string $domain, string $type): array
    {
        throw new \RuntimeException('DNS lookup failed for ' . $domain);
    }
}
