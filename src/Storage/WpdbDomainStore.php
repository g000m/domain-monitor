<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class WpdbDomainStore implements DomainStore
{
    /** @var object */
    private $wpdb;
    private string $tableName;

    /** @param object $wpdb WordPress wpdb-like object. */
    public function __construct($wpdb, string $tableName)
    {
        $this->wpdb = $wpdb;
        $this->tableName = $tableName;
    }

    public function upsertDomain(string $domain, string $source): int
    {
        $hash = hash('sha256', $domain);
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableName} WHERE domain_hash = %s LIMIT 1",
            $hash
        ));

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = $this->now();
        $this->wpdb->insert($this->tableName, [
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
            'last_checked_at' => null,
            'next_due_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) ($this->wpdb->insert_id ?? 0);
    }

    public function all(): array
    {
        $rows = $this->wpdb->get_results("SELECT * FROM {$this->tableName} ORDER BY domain ASC");
        if (! is_array($rows)) {
            return [];
        }

        return array_map([$this, 'rowToArray'], $rows);
    }

    public function find(int $id): ?array
    {
        $row = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$this->tableName} WHERE id = %d LIMIT 1",
            $id
        ));

        return $row === null ? null : $this->rowToArray($row);
    }

    public function saveCheckResult(int $id, array $result): void
    {
        $record = $this->find($id);
        if ($record === null) {
            return;
        }

        $snapshot = ArrayDomainStore::snapshotFromResult((string) ($record['domain'] ?? ''), $result);
        $data = [
            'snapshot' => $this->encodeJson($snapshot),
            'status' => ArrayDomainStore::statusFromResult($result),
            'status_reason' => ArrayDomainStore::statusReasonFromResult($result),
            'last_checked_at' => $result['last_checked_at'] ?? null,
            'updated_at' => $this->now(),
        ];

        $this->wpdb->update($this->tableName, $data, ['id' => $id]);
    }

    /** @return array<string,mixed> */
    private function rowToArray($row): array
    {
        if (is_array($row)) {
            return $row;
        }

        if (is_object($row)) {
            return get_object_vars($row);
        }

        return [];
    }

    /** @param array<string,mixed> $value */
    private function encodeJson(array $value): string
    {
        if (function_exists('wp_json_encode')) {
            return (string) wp_json_encode($value);
        }

        return (string) json_encode($value);
    }

    private function now(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
