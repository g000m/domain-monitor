<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class ArrayAlertStore implements AlertStore
{
    /** @var array<int,array<string,mixed>> */
    private array $rows = [];
    private int $nextId = 1;

    /** @param array<string,mixed>|null $details */
    public function createAlert(int $domainId, string $type, string $message, ?array $details = null): int
    {
        $id = $this->nextId++;
        $this->rows[$id] = [
            'id'          => $id,
            'domain_id'   => $domainId,
            'type'        => $type,
            'message'     => $message,
            'details'     => $details !== null ? json_encode($details) : null,
            'created_at'  => gmdate('Y-m-d H:i:s'),
            'resolved_at' => null,
        ];

        return $id;
    }

    /** @return list<array<string,mixed>> */
    public function openAlertsForDomain(int $domainId): array
    {
        $results = [];
        foreach ($this->rows as $row) {
            if ((int) $row['domain_id'] === $domainId && $row['resolved_at'] === null) {
                $results[] = $row;
            }
        }

        return array_values($results);
    }

    public function resolveAlertsForDomain(int $domainId, string $type, string $resolvedAt): void
    {
        foreach ($this->rows as $id => $row) {
            if ((int) $row['domain_id'] === $domainId && $row['type'] === $type && $row['resolved_at'] === null) {
                $this->rows[$id]['resolved_at'] = $resolvedAt;
            }
        }
    }
}
