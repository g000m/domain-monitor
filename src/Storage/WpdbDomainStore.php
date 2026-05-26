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
        $existing = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT id FROM {$this->tableName} WHERE domain = %s LIMIT 1",
            $domain
        ));

        if ($existing !== null) {
            return (int) $existing;
        }

        $now = $this->now();
        $this->wpdb->insert($this->tableName, [
            'domain' => $domain,
            'source' => $source,
            'dns_status' => 'unknown',
            'dns_message' => '',
            'rdap_status' => 'unknown',
            'rdap_message' => '',
            'rdap_registrar' => null,
            'rdap_expires_at' => null,
            'last_checked_at' => null,
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
        $allowed = [
            'dns_status',
            'dns_message',
            'rdap_status',
            'rdap_message',
            'rdap_registrar',
            'rdap_expires_at',
            'last_checked_at',
        ];
        $data = [];

        foreach ($allowed as $key) {
            if (array_key_exists($key, $result)) {
                $data[$key] = $result[$key];
            }
        }

        $data['updated_at'] = $this->now();

        $this->wpdb->update($this->tableName, $data, ['id' => $id]);
    }

    /** @return array<string,string|int|null> */
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

    private function now(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
