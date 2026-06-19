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
            // Inconclusive, not a fault: the monitor failed to complete its own
            // check. A connect failure from here (egress limits, IPv6, timeout, or
            // a self-connection block when the monitor runs on the same host) is
            // not evidence the site is broken, so we report 'unknown' and keep the
            // copy about the monitor, never the site. 'unknown' does not drive the
            // dashboard status; see StatusCalculator.
            return new SslResult(
                'unknown',
                null,
                null,
                'Could not verify the TLS certificate for ' . $domain . '. '
                . 'The monitor could not reach port 443. This is often a temporary '
                . 'network issue on the monitoring side and will retry on the next check.'
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
