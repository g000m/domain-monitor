<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class SslChecker
{
    /** @var CertificateFetcher */
    private $fetcher;

    public function __construct(CertificateFetcher $fetcher)
    {
        $this->fetcher = $fetcher;
    }

    public function check(string $domain): SslResult
    {
        $parsed = $this->fetcher->fetch($domain);

        if ($parsed === null) {
            return new SslResult(
                'degraded',
                null,
                null,
                'Could not connect to ' . $domain . ' on port 443. The site may not serve HTTPS.'
            );
        }

        $validTo  = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null;
        $expiresAt = $validTo !== null ? gmdate('Y-m-d\TH:i:s\Z', $validTo) : null;
        $issuer   = $this->extractIssuer($parsed);

        return new SslResult('ok', $expiresAt, $issuer, '');
    }

    /** @param array<string,mixed> $parsed */
    private function extractIssuer(array $parsed): ?string
    {
        $issuerData = $parsed['issuer'] ?? null;
        if (! is_array($issuerData)) {
            return null;
        }

        if (isset($issuerData['CN']) && is_string($issuerData['CN'])) {
            return $issuerData['CN'];
        }

        if (isset($issuerData['O']) && is_string($issuerData['O'])) {
            return $issuerData['O'];
        }

        return null;
    }
}
