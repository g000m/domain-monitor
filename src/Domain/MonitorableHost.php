<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

/**
 * Determines whether a host string represents a publicly-monitorable domain.
 *
 * A host is NOT monitorable when it is:
 *   - empty or contains no dot (e.g. localhost, intranet names)
 *   - an IPv4 literal (e.g. 192.168.1.1)
 *   - an IPv6 literal, including bracketed form (e.g. ::1, [::1])
 *   - a reserved/dev TLD: .localhost, .local, .test, .example, .invalid, .internal
 *   - a host whose public suffix is not found in the PSL data
 *
 * Manual domain adds bypass this guard. It is only used in the auto-detection path.
 */
final class MonitorableHost
{
    /** @var list<string> Reserved/dev TLDs that should never be monitored. */
    private const RESERVED_TLDS = ['localhost', 'local', 'test', 'example', 'invalid', 'internal'];

    /**
     * Returns true when the host represents a publicly-monitorable domain.
     *
     * The $host may contain a port or scheme remnant; both are stripped before
     * evaluation so callers do not need to pre-process the value.
     */
    public static function isMonitorable(string $host): bool
    {
        $host = self::normalise($host);

        if ($host === '') {
            return false;
        }

        // IPv6 literal (with or without brackets).
        if (self::isIpv6($host)) {
            return false;
        }

        // IPv4 literal.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return false;
        }

        // Must contain at least one dot.
        if (strpos($host, '.') === false) {
            return false;
        }

        // Reject reserved/dev TLDs.
        $tld = self::tld($host);
        if (in_array($tld, self::RESERVED_TLDS, true)) {
            return false;
        }

        // Reject hosts whose TLD (or any PSL suffix) is not in the PSL.
        if (! self::hasPslSuffix($host)) {
            return false;
        }

        return true;
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    /**
     * Strip scheme, port, leading/trailing whitespace, and bracket from IPv6.
     */
    private static function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        // Strip scheme (http:// or https://).
        $host = (string) preg_replace('/^https?:\/\//', '', $host);

        // Strip path, query, fragment.
        $host = explode('/', $host)[0];
        $host = explode('?', $host)[0];
        $host = explode('#', $host)[0];

        // Strip port (handle both normal hosts and [ipv6]:port).
        if (strpos($host, '[') === 0) {
            // Bracketed IPv6 — strip brackets and optional port.
            $host = (string) preg_replace('/^\[([^\]]+)\](:\d+)?$/', '$1', $host);
        } else {
            // Normal host:port — only strip if the last segment is numeric.
            $host = (string) preg_replace('/:\d+$/', '', $host);
        }

        return trim($host);
    }

    /** Returns true for IPv6 address strings (with or without brackets). */
    private static function isIpv6(string $host): bool
    {
        // After normalise(), brackets are already stripped.
        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
    }

    /** Returns the rightmost label (TLD) of the host. */
    private static function tld(string $host): string
    {
        $parts = explode('.', rtrim($host, '.'));
        return end($parts);
    }

    /**
     * Returns true when the host has at least one suffix present in the PSL data.
     * Reuses the PSL loading logic from ApexDomain via its public accessor.
     */
    private static function hasPslSuffix(string $host): bool
    {
        $labels = array_values(
            array_filter(explode('.', $host), static fn (string $l): bool => $l !== '')
        );
        $count = count($labels);

        $psl = ApexDomain::pslData();

        // Walk from the rightmost single label up to the full host.
        // If any suffix candidate exists in the PSL, the host has a known suffix.
        for ($i = 1; $i <= $count; $i++) {
            $candidate = implode('.', array_slice($labels, $count - $i));
            if (isset($psl[$candidate])) {
                return true;
            }
        }

        return false;
    }
}
