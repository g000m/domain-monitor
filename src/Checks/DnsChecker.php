<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DnsChecker
{
    /** @var object */
    private $resolver;

    /** @param object $resolver Object exposing records(string $domain, string $type): array. */
    public function __construct($resolver)
    {
        $this->resolver = $resolver;
    }

    public function check(string $domain): DnsResult
    {
        $raw    = [];
        $counts = [];
        foreach (['A', 'AAAA', 'NS', 'MX'] as $type) {
            $records       = $this->resolver->records($domain, $type);
            $raw[$type]    = is_array($records) ? $records : [];
            $counts[$type] = count($raw[$type]);
        }

        // Status is determined by A/AAAA/NS only (MX is optional).
        $addressTotal = $counts['A'] + $counts['AAAA'] + $counts['NS'];
        if ($addressTotal < 1) {
            return new DnsResult('degraded', 'No A, AAAA, or NS records were found.');
        }

        $parts = [];
        foreach (['A', 'AAAA', 'NS'] as $type) {
            if ($counts[$type] > 0) {
                $parts[] = sprintf('%d %s record%s', $counts[$type], $type, $counts[$type] === 1 ? '' : 's');
            }
        }

        $apexRecords = [
            'a'    => $this->extractValues($raw['A'],    'ip'),
            'aaaa' => $this->extractValues($raw['AAAA'], 'ipv6'),
            'ns'   => $this->extractHostValues($raw['NS']),
            'mx'   => $this->extractMxValues($raw['MX']),
        ];

        return new DnsResult('ok', 'DNS lookup found ' . implode(', ', $parts) . '.', $apexRecords);
    }

    /**
     * Extract a scalar field from dns_get_record rows and normalise to lowercase strings.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function extractValues(array $rows, string $field): array
    {
        $values = [];
        foreach ($rows as $row) {
            if (isset($row[$field]) && (is_string($row[$field]) || is_int($row[$field]))) {
                $values[] = strtolower((string) $row[$field]);
            }
        }
        return $values;
    }

    /**
     * Extract 'target' or 'host' field from NS rows, strip trailing dots,
     * and normalise to lowercase.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<string>
     */
    private function extractHostValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $raw = $row['target'] ?? $row['host'] ?? null;
            if (is_string($raw) && $raw !== '') {
                $values[] = strtolower(rtrim($raw, '.'));
            }
        }
        return $values;
    }

    /**
     * Extract MX rows into ['priority' => int, 'host' => string] entries.
     * Normalises host to lowercase with trailing dot stripped.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array{priority:int,host:string}>
     */
    private function extractMxValues(array $rows): array
    {
        $values = [];
        foreach ($rows as $row) {
            $host = $row['target'] ?? $row['host'] ?? '';
            if (! is_string($host) || $host === '') {
                continue;
            }
            $values[] = [
                'priority' => (int) ($row['pri'] ?? $row['priority'] ?? 0),
                'host'     => strtolower(rtrim($host, '.')),
            ];
        }
        return $values;
    }
}
