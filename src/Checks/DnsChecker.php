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
        $counts = [];
        foreach (['A', 'AAAA', 'NS'] as $type) {
            $records = $this->resolver->records($domain, $type);
            $counts[$type] = is_array($records) ? count($records) : 0;
        }

        $total = array_sum($counts);
        if ($total < 1) {
            return new DnsResult('degraded', 'No A, AAAA, or NS records were found.');
        }

        $parts = [];
        foreach ($counts as $type => $count) {
            if ($count > 0) {
                $parts[] = sprintf('%d %s record%s', $count, $type, $count === 1 ? '' : 's');
            }
        }

        return new DnsResult('ok', 'DNS lookup found ' . implode(', ', $parts) . '.');
    }
}
