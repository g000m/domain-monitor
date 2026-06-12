<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

final class ApexDomain
{
    /**
     * Lazily-loaded PSL data. Keys are suffix strings; values are one of:
     *   'normal'    -- standard public suffix (e.g. 'com', 'co.uk')
     *   'wildcard'  -- every immediate child is a public suffix (e.g. 'ck' covers *.ck)
     *   'exception' -- exempted from its wildcard parent (e.g. 'www.ck' is NOT a public suffix)
     *
     * @var array<string,string>|null
     */
    private static ?array $psl = null;

    /**
     * Return the apex (registered) domain for a given host string.
     *
     * Rules (PSL algorithm, simplified):
     *   1. Strip scheme, path, port, leading www.
     *   2. Walk the PSL to find the longest matching public suffix.
     *   3. Apex = one label to the left of the public suffix + the public suffix.
     *   4. Wildcard rules (*.ck): the suffix is <label>.ck; one more label to the
     *      left is the apex.
     *   5. Exception rules (!www.ck): the exception entry itself is a registrable
     *      domain, not a public suffix, so the apex is the exception host itself.
     *   6. If no PSL match is found, fall back to the rightmost two labels (same
     *      behaviour as the previous hardcoded implementation).
     */
    public static function fromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^https?:\/\//', '', $host) ?? $host;
        $host = preg_replace('/[\/?#].*$/', '', $host) ?? $host;
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = trim($host, '.');

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        $labels = array_values(
            array_filter(explode('.', $host), static fn (string $l): bool => $l !== '')
        );
        $count = count($labels);

        if ($count === 0) {
            return $host;
        }
        if ($count === 1) {
            return $labels[0];
        }

        $psl = self::loadPsl();

        // Try longest-match first: walk from all labels down to just the TLD.
        // For each candidate suffix (joining $i labels from the right), check:
        //   a) Is it an exception entry?  -> apex = the exception host itself.
        //   b) Is it a wildcard entry?    -> suffix = <next-label>.<wildcard-tld>;
        //      apex = one more label to the left of that.
        //   c) Is it a normal entry?      -> apex = one label to the left + suffix.

        for ($i = $count; $i >= 1; $i--) {
            $candidate = implode('.', array_slice($labels, $count - $i));

            if (! isset($psl[$candidate])) {
                continue;
            }

            $type = $psl[$candidate];

            if ($type === 'exception') {
                // The exception entry is itself a registrable domain (not a public suffix).
                // Return the candidate as the apex.
                return $candidate;
            }

            if ($type === 'wildcard') {
                // The real public suffix is <label-immediately-left>.<candidate>.
                // We need $i+1 labels for the suffix, so the apex has $i+2 labels.
                $suffixLabelIdx = $count - $i - 1; // index of the label that fills the wildcard
                if ($suffixLabelIdx < 0) {
                    // The host IS the wildcard TLD itself; return as-is.
                    return $candidate;
                }
                $wildcardSuffix = $labels[$suffixLabelIdx] . '.' . $candidate;
                $apexLabelIdx   = $suffixLabelIdx - 1;
                if ($apexLabelIdx < 0) {
                    // Host is exactly <label>.<wildcard-tld> with no further label; return it.
                    return $wildcardSuffix;
                }
                return $labels[$apexLabelIdx] . '.' . $wildcardSuffix;
            }

            // Normal public suffix.
            $apexLabelIdx = $count - $i - 1;
            if ($apexLabelIdx < 0) {
                // The entire host is itself a public suffix; return as-is.
                return $candidate;
            }
            return $labels[$apexLabelIdx] . '.' . $candidate;
        }

        // No PSL match: fall back to rightmost two labels.
        return $labels[$count - 2] . '.' . $labels[$count - 1];
    }

    /**
     * Load the PSL data array from the generated data file.
     * Result is cached in a static property so the file is only require'd once
     * per PHP process.
     *
     * @return array<string,string>
     */
    private static function loadPsl(): array
    {
        if (self::$psl === null) {
            $dataFile = __DIR__ . '/data/public-suffix-list.php';
            if (file_exists($dataFile)) {
                /** @var array<string,string> $loaded */
                $loaded    = require $dataFile;
                self::$psl = is_array($loaded) ? $loaded : [];
            } else {
                self::$psl = [];
            }
        }

        return self::$psl;
    }
}
