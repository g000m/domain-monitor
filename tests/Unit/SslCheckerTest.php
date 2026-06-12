<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\CertificateFetcher;
use DomainMonitor\Checks\SslChecker;
use PHPUnit\Framework\TestCase;

final class SslCheckerTest extends TestCase
{
    public function test_valid_cert_returns_ok_with_expiry_and_issuer(): void
    {
        $validTo = mktime(0, 0, 0, 1, 1, 2027);
        $fetcher = new FakeCertificateFetcher([
            'validTo_time_t' => $validTo,
            'issuer'         => ['CN' => 'Let\'s Encrypt Authority X3', 'O' => 'Let\'s Encrypt'],
        ]);

        $result = (new SslChecker($fetcher))->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $validTo), $result->expiresAt());
        self::assertSame('Let\'s Encrypt Authority X3', $result->issuer());
        self::assertSame('', $result->message());
    }

    public function test_expiring_cert_returns_ok_with_expiry(): void
    {
        // SslChecker itself does not evaluate thresholds; StatusCalculator does.
        $validTo = mktime(0, 0, 0, 1, 10, 2026); // 10 days from some reference
        $fetcher = new FakeCertificateFetcher([
            'validTo_time_t' => $validTo,
            'issuer'         => ['O' => 'DigiCert Inc'],
        ]);

        $result = (new SslChecker($fetcher))->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $validTo), $result->expiresAt());
        self::assertSame('DigiCert Inc', $result->issuer());
    }

    public function test_expired_cert_still_returns_ok_from_checker_status_calculator_evaluates(): void
    {
        // The checker reports whatever the cert says; StatusCalculator decides red/amber/green.
        $validTo = mktime(0, 0, 0, 1, 1, 2020); // in the past
        $fetcher = new FakeCertificateFetcher([
            'validTo_time_t' => $validTo,
            'issuer'         => ['CN' => 'Old CA'],
        ]);

        $result = (new SslChecker($fetcher))->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertSame(gmdate('Y-m-d\TH:i:s\Z', $validTo), $result->expiresAt());
    }

    public function test_unreachable_host_returns_degraded(): void
    {
        $fetcher = new FakeCertificateFetcher(null);

        $result = (new SslChecker($fetcher))->check('unreachable.example');

        self::assertSame('degraded', $result->status());
        self::assertNull($result->expiresAt());
        self::assertNull($result->issuer());
        self::assertStringContainsString('unreachable.example', $result->message());
    }

    public function test_issuer_falls_back_to_organisation_when_cn_missing(): void
    {
        $fetcher = new FakeCertificateFetcher([
            'validTo_time_t' => time() + 86400 * 60,
            'issuer'         => ['O' => 'ACME Corp'],
        ]);

        $result = (new SslChecker($fetcher))->check('example.com');

        self::assertSame('ACME Corp', $result->issuer());
    }

    public function test_missing_issuer_data_returns_null_issuer(): void
    {
        $fetcher = new FakeCertificateFetcher([
            'validTo_time_t' => time() + 86400 * 60,
        ]);

        $result = (new SslChecker($fetcher))->check('example.com');

        self::assertNull($result->issuer());
    }
}

final class FakeCertificateFetcher implements CertificateFetcher
{
    /** @var array<string,mixed>|null */
    private $parsedCert;

    /** @param array<string,mixed>|null $parsedCert */
    public function __construct(?array $parsedCert)
    {
        $this->parsedCert = $parsedCert;
    }

    /** @return array<string,mixed>|null */
    public function fetch(string $domain): ?array
    {
        return $this->parsedCert;
    }
}
