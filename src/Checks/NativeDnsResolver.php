<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class NativeDnsResolver
{
    /** @return list<array<string,mixed>> */
    public function records(string $domain, string $type): array
    {
        if (! function_exists('dns_get_record')) {
            return [];
        }

        $constant = defined('DNS_' . $type) ? constant('DNS_' . $type) : null;
        if (! is_int($constant)) {
            return [];
        }

        $records = @dns_get_record($domain, $constant);
        return is_array($records) ? $records : [];
    }
}
