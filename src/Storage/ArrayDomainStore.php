<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class ArrayDomainStore implements DomainStore
{
    /** @var array<int,array<string,string|int|null>> */
    private array $rows = [];
    private int $nextId = 1;

    public function upsertDomain(string $domain, string $source): int
    {
        foreach ($this->rows as $id => $row) {
            if (($row['domain'] ?? '') === $domain) {
                return $id;
            }
        }

        $id = $this->nextId++;
        $now = 'now';
        $this->rows[$id] = [
            'id' => $id,
            'domain' => $domain,
            'source' => $source,
            'dns_status' => 'unknown',
            'dns_message' => '',
            'rdap_status' => 'unknown',
            'rdap_message' => '',
            'rdap_registrar' => '',
            'rdap_expires_at' => '',
            'last_checked_at' => '',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return $id;
    }

    public function all(): array
    {
        return array_values($this->rows);
    }

    public function find(int $id): ?array
    {
        return $this->rows[$id] ?? null;
    }

    public function saveCheckResult(int $id, array $result): void
    {
        if (! isset($this->rows[$id])) {
            return;
        }

        $allowed = [
            'dns_status',
            'dns_message',
            'rdap_status',
            'rdap_message',
            'rdap_registrar',
            'rdap_expires_at',
            'last_checked_at',
        ];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $result)) {
                $this->rows[$id][$key] = $result[$key];
            }
        }
    }
}
