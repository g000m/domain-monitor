<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class ArrayDomainStore implements DomainStore
{
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    public function upsertDomain(string $domain, string $source): int
    {
        $hash = hash('sha256', $domain);
        foreach ($this->rows as $id => $row) {
            if (($row['domain_hash'] ?? '') === $hash) {
                return $id;
            }
        }

        $id = $this->nextId++;
        $now = 'now';
        $this->rows[$id] = [
            'id' => $id,
            'domain' => $domain,
            'domain_hash' => $hash,
            'source' => $source,
            'is_self' => $source === 'auto' ? 1 : 0,
            'is_active' => 1,
            'owner_site_id' => 0,
            'rdap_tier' => 0,
            'status' => 1,
            'status_reason' => '',
            'snapshot' => null,
            'last_known_good_snapshot' => null,
            'last_checked_at' => '',
            'next_due_at' => null,
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

        $snapshot = self::snapshotFromResult((string) $this->rows[$id]['domain'], $result);
        $this->rows[$id]['snapshot'] = $snapshot;
        $this->rows[$id]['last_checked_at'] = $result['last_checked_at'] ?? '';
        $this->rows[$id]['status'] = self::statusFromResult($result);
        $this->rows[$id]['status_reason'] = self::statusReasonFromResult($result);
        $this->rows[$id]['updated_at'] = 'now';
    }

    /** @param array<string,string|null> $result @return array<string,mixed> */
    public static function snapshotFromResult(string $domain, array $result): array
    {
        return [
            'schema_version' => 1,
            'checked_at' => (string) ($result['last_checked_at'] ?? ''),
            'domain' => $domain,
            'rdap' => [
                'status' => (string) ($result['rdap_status'] ?? 'unknown'),
                'tier' => 0,
                'registrar' => (string) ($result['rdap_registrar'] ?? ''),
                'expires_at' => (string) ($result['rdap_expires_at'] ?? ''),
                'message' => (string) ($result['rdap_message'] ?? ''),
            ],
            'dns' => [
                'status' => (string) ($result['dns_status'] ?? 'unknown'),
                'message' => (string) ($result['dns_message'] ?? ''),
            ],
            'errors' => [],
        ];
    }

    /** @param array<string,string|null> $result */
    public static function statusFromResult(array $result): int
    {
        return (($result['dns_status'] ?? '') === 'ok' && ($result['rdap_status'] ?? '') === 'ok') ? 0 : 1;
    }

    /** @param array<string,string|null> $result */
    public static function statusReasonFromResult(array $result): string
    {
        $messages = array_filter([
            (string) ($result['dns_message'] ?? ''),
            (string) ($result['rdap_message'] ?? ''),
        ]);

        return substr(implode(' ', $messages), 0, 191);
    }
}
