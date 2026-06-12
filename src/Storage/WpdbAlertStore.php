<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class WpdbAlertStore implements AlertStore
{
    /** @var object */
    private $wpdb;
    private string $tableName;

    /** @param object $wpdb */
    public function __construct($wpdb)
    {
        $this->wpdb      = $wpdb;
        $this->tableName = DomainTable::alertsTableName((string) ($wpdb->prefix ?? 'wp_'));
    }

    /** @param array<string,mixed>|null $details */
    public function createAlert(int $domainId, string $type, string $message, ?array $details = null): int
    {
        $this->wpdb->insert($this->tableName, [
            'domain_id'   => $domainId,
            'type'        => $type,
            'message'     => $message,
            'details'     => $details !== null ? json_encode($details) : null,
            'created_at'  => $this->now(),
            'resolved_at' => null,
        ]);

        return (int) ($this->wpdb->insert_id ?? 0);
    }

    /** @return list<array<string,mixed>> */
    public function openAlertsForDomain(int $domainId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} WHERE domain_id = %d AND resolved_at IS NULL ORDER BY created_at ASC",
                $domainId
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    public function resolveAlertsForDomain(int $domainId, string $type, string $resolvedAt): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->tableName} SET resolved_at = %s WHERE domain_id = %d AND type = %s AND resolved_at IS NULL",
                $resolvedAt,
                $domainId,
                $type
            )
        );
    }

    public function resolveAlert(int $alertId, string $resolvedAt): void
    {
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->tableName} SET resolved_at = %s WHERE id = %d AND resolved_at IS NULL",
                $resolvedAt,
                $alertId
            )
        );
    }

    /** @return list<array<string,mixed>> */
    public function recentlyResolvedAlertsForDomain(int $domainId, int $withinDays, string $now): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} WHERE domain_id = %d AND resolved_at IS NOT NULL AND resolved_at >= DATE_SUB(%s, INTERVAL %d DAY) ORDER BY resolved_at DESC",
                $domainId,
                $now,
                $withinDays
            ),
            ARRAY_A
        );

        return is_array($rows) ? array_values($rows) : [];
    }

    private function now(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
