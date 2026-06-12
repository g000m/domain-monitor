<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

/**
 * Checks email-related DNS health for an apex domain.
 *
 * Performs targeted TXT lookups only — it does NOT turn on full TXT
 * snapshotting in DnsChecker/SnapshotDiffer. Each lookup is isolated
 * so a resolver error on one record type does not abort the rest.
 *
 * Responsibilities:
 *   - SPF:   exactly-one TXT at apex starting with "v=spf1" (RFC 7208).
 *   - DMARC: TXT at _dmarc.<apex> starting with "v=DMARC1" containing a "p=" tag.
 *   - DKIM:  presence-only check against common selectors (informational, never a failure).
 *   - MX:    one or more MX records exist at the apex.
 *
 * The resolver dependency follows the same duck-typing pattern as DnsChecker:
 * any object with records(string $domain, string $type): array is accepted.
 *
 * @phpstan-type ResolverLike object
 */
final class EmailDnsChecker
{
    /**
     * Common DKIM selectors to probe. Absence of all of them is informational only.
     */
    private const DKIM_SELECTORS = ['default', 'google', 'selector1', 'selector2', 'k1'];

    /** @var object */
    private $resolver;

    /** @param object $resolver Object exposing records(string $domain, string $type): array. */
    public function __construct($resolver)
    {
        $this->resolver = $resolver;
    }

    public function check(string $domain): EmailDnsResult
    {
        [$spfState, $spfRecord] = $this->checkSpf($domain);
        [$dmarcState, $dmarcRecord] = $this->checkDmarc($domain);
        $mxState            = $this->checkMx($domain);
        $dkimFoundSelectors = $this->checkDkim($domain);

        $messageParts = [];

        if ($spfState === EmailDnsResult::SPF_MISSING) {
            $messageParts[] = 'No SPF record found.';
        } elseif ($spfState === EmailDnsResult::SPF_MULTIPLE) {
            $messageParts[] = 'Multiple SPF records found (RFC 7208 violation).';
        } elseif ($spfState === EmailDnsResult::SPF_INVALID) {
            $messageParts[] = 'SPF record has invalid syntax.';
        }

        if ($dmarcState === EmailDnsResult::DMARC_MISSING) {
            $messageParts[] = 'No DMARC record found.';
        } elseif ($dmarcState === EmailDnsResult::DMARC_INVALID) {
            $messageParts[] = 'DMARC record found but missing required p= tag.';
        }

        if ($mxState === EmailDnsResult::MX_MISSING) {
            $messageParts[] = 'No MX records found — mail may be handled elsewhere or broken.';
        }

        if (count($dkimFoundSelectors) > 0) {
            $messageParts[] = 'DKIM found at selector(s): ' . implode(', ', $dkimFoundSelectors) . '.';
        }

        $message = implode(' ', $messageParts);

        return new EmailDnsResult(
            $spfState,
            $spfRecord,
            $dmarcState,
            $dmarcRecord,
            $mxState,
            $dkimFoundSelectors,
            $message
        );
    }

    /**
     * Check SPF at the apex domain.
     * Returns [spfState, firstSpfRecord].
     *
     * @return array{string,string}
     */
    private function checkSpf(string $domain): array
    {
        try {
            $txtRecords = $this->resolver->records($domain, 'TXT');
        } catch (\Throwable $e) {
            return [EmailDnsResult::SPF_MISSING, ''];
        }

        if (! is_array($txtRecords)) {
            return [EmailDnsResult::SPF_MISSING, ''];
        }

        $spfRecords = [];
        foreach ($txtRecords as $row) {
            $txt = $this->extractTxtValue($row);
            if ($txt !== '' && stripos($txt, 'v=spf1') === 0) {
                $spfRecords[] = $txt;
            }
        }

        if (count($spfRecords) === 0) {
            return [EmailDnsResult::SPF_MISSING, ''];
        }

        if (count($spfRecords) > 1) {
            return [EmailDnsResult::SPF_MULTIPLE, $spfRecords[0]];
        }

        $record = $spfRecords[0];

        // Basic syntax sanity: must end with a recognizable all-qualifier or redirect.
        if (! $this->isSpfSyntaxValid($record)) {
            return [EmailDnsResult::SPF_INVALID, $record];
        }

        return [EmailDnsResult::SPF_PRESENT, $record];
    }

    /**
     * Minimal SPF syntax check:
     * - Must start with "v=spf1" (case-insensitive).
     * - Must contain a recognised all-qualifier (~all, -all, +all, ?all) OR a redirect= mechanism.
     *
     * This is intentionally lenient — full RFC 7208 validation is out of scope here.
     * We just want to catch obviously malformed records.
     */
    private function isSpfSyntaxValid(string $record): bool
    {
        $lower = strtolower($record);

        // Must start with v=spf1.
        if (strpos($lower, 'v=spf1') !== 0) {
            return false;
        }

        // Must have a known terminator or redirect mechanism.
        $hasTerminator = (bool) preg_match('/[~\-\+\?]all(\s|$)/i', $record);
        $hasRedirect   = strpos($lower, 'redirect=') !== false;

        return $hasTerminator || $hasRedirect;
    }

    /**
     * Check DMARC at _dmarc.<apex>.
     * Returns [dmarcState, dmarcRecord].
     *
     * @return array{string,string}
     */
    private function checkDmarc(string $domain): array
    {
        $dmarcHost = '_dmarc.' . ltrim($domain, '.');

        try {
            $txtRecords = $this->resolver->records($dmarcHost, 'TXT');
        } catch (\Throwable $e) {
            return [EmailDnsResult::DMARC_MISSING, ''];
        }

        if (! is_array($txtRecords)) {
            return [EmailDnsResult::DMARC_MISSING, ''];
        }

        foreach ($txtRecords as $row) {
            $txt = $this->extractTxtValue($row);
            if ($txt !== '' && stripos($txt, 'v=DMARC1') === 0) {
                // Valid DMARC1 record: must have a p= tag.
                if (preg_match('/\bp=\s*(none|quarantine|reject)/i', $txt)) {
                    return [EmailDnsResult::DMARC_PRESENT, $txt];
                }
                // Record starts with v=DMARC1 but no p= tag — treat as invalid.
                return [EmailDnsResult::DMARC_INVALID, $txt];
            }
        }

        return [EmailDnsResult::DMARC_MISSING, ''];
    }

    /**
     * Check MX records at the apex.
     */
    private function checkMx(string $domain): string
    {
        try {
            $mxRecords = $this->resolver->records($domain, 'MX');
        } catch (\Throwable $e) {
            return EmailDnsResult::MX_MISSING;
        }

        if (! is_array($mxRecords) || count($mxRecords) === 0) {
            return EmailDnsResult::MX_MISSING;
        }

        return EmailDnsResult::MX_PRESENT;
    }

    /**
     * Check common DKIM selectors at <selector>._domainkey.<apex>.
     * Absence of all selectors is informational; presence is noted.
     *
     * @return list<string>
     */
    private function checkDkim(string $domain): array
    {
        $found = [];

        foreach (self::DKIM_SELECTORS as $selector) {
            $dkimHost = $selector . '._domainkey.' . ltrim($domain, '.');

            try {
                $records = $this->resolver->records($dkimHost, 'TXT');
            } catch (\Throwable $e) {
                continue;
            }

            if (! is_array($records)) {
                continue;
            }

            foreach ($records as $row) {
                $txt = $this->extractTxtValue($row);
                // A DKIM record typically starts with "v=DKIM1" but some providers omit
                // the version tag. Any non-empty TXT at the selector host counts as found.
                if ($txt !== '') {
                    $found[] = $selector;
                    break; // One record for this selector is enough.
                }
            }
        }

        return $found;
    }

    /**
     * Extract the string value from a TXT dns_get_record row.
     * The 'txt' field holds the concatenated value; fall back to 'entries'
     * (array of strings per RFC 4408 multi-string records).
     *
     * @param array<string,mixed>|mixed $row
     */
    private function extractTxtValue($row): string
    {
        if (! is_array($row)) {
            return '';
        }

        if (isset($row['txt']) && is_string($row['txt'])) {
            return trim($row['txt']);
        }

        // Some resolvers return entries[] (array of string segments).
        if (isset($row['entries']) && is_array($row['entries'])) {
            return trim(implode('', $row['entries']));
        }

        return '';
    }
}
