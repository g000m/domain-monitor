<?php
declare(strict_types=1);

namespace DomainMonitor\Alerts;

use DomainMonitor\Storage\AlertStore;

/**
 * Auto-resolves open alerts when the triggering condition reverts.
 *
 * Supported auto-resolve conditions:
 *   - a_changed / mx_changed / ns_changed: resolved when current snapshot records
 *     for that type match the alert's stored `previous` value (same normalization
 *     used by SnapshotDiffer).
 *   - transfer_lock_removed: resolved when the domain is transfer-locked again.
 *
 * Callers pass the current snapshot and the fresh transfer-lock state; this class
 * iterates open alerts and resolves any whose revert condition is satisfied.
 *
 * @phpstan-type SnapshotArray array<string,mixed>
 */
final class AlertResolver
{
    private AlertStore $alertStore;

    public function __construct(AlertStore $alertStore)
    {
        $this->alertStore = $alertStore;
    }

    /**
     * Check all open alerts for $domainId and resolve any whose condition has reverted.
     *
     * @param array<string,mixed> $currentSnapshot
     * @param bool|null $currentTransferLocked
     */
    public function resolveReverted(int $domainId, array $currentSnapshot, $currentTransferLocked, string $resolvedAt): void
    {
        $openAlerts = $this->alertStore->openAlertsForDomain($domainId);

        foreach ($openAlerts as $alert) {
            $type = (string) ($alert['type'] ?? '');

            if ($type === 'transfer_lock_removed') {
                if ($currentTransferLocked === true) {
                    $this->alertStore->resolveAlert((int) $alert['id'], $resolvedAt);
                }
                continue;
            }

            if (in_array($type, ['a_changed', 'mx_changed', 'ns_changed'], true)) {
                $this->maybeResolveRecordAlert($alert, $type, $currentSnapshot, $resolvedAt);
            }
        }
    }

    /**
     * Resolve a record-change alert if the current records match the alert's stored previous set.
     *
     * @param array<string,mixed> $alert
     * @param array<string,mixed> $currentSnapshot
     */
    private function maybeResolveRecordAlert(array $alert, string $type, array $currentSnapshot, string $resolvedAt): void
    {
        $details = $this->decodeDetails($alert['details'] ?? null);
        if ($details === null) {
            // No stored previous; cannot determine revert — leave open.
            return;
        }

        $previous = $details['previous'] ?? null;
        if (! is_array($previous)) {
            return;
        }

        $recordType = $this->recordTypeFromAlertType($type);
        $currentRecords = $this->extractCurrentRecords($currentSnapshot, $recordType);

        $normPrevious = $this->normaliseRecords($previous, $recordType);
        $normCurrent  = $this->normaliseRecords($currentRecords, $recordType);

        if ($normPrevious === $normCurrent) {
            $this->alertStore->resolveAlert((int) $alert['id'], $resolvedAt);
        }
    }

    /**
     * @param mixed $raw
     * @return array<string,mixed>|null
     */
    private function decodeDetails($raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function recordTypeFromAlertType(string $alertType): string
    {
        // 'a_changed' -> 'a', 'mx_changed' -> 'mx', 'ns_changed' -> 'ns'
        return str_replace('_changed', '', $alertType);
    }

    /**
     * Extract the current records for a given record type from the apex scope.
     *
     * @param array<string,mixed> $snapshot
     * @return mixed
     */
    private function extractCurrentRecords(array $snapshot, string $recordType)
    {
        return $snapshot['dns']['apex'][$recordType] ?? [];
    }

    /**
     * Normalise a record set using the same algorithm as SnapshotDiffer::normaliseRecords.
     *
     * @param mixed $records
     * @return list<string>
     */
    private function normaliseRecords($records, string $recordType): array
    {
        if (! is_array($records)) {
            return [];
        }

        $normalised = [];
        foreach ($records as $record) {
            if ($recordType === 'mx' && is_array($record)) {
                $normalised[] = trim((string) ($record['priority'] ?? '') . ' ' . (string) ($record['host'] ?? ''));
                continue;
            }

            $normalised[] = (string) $record;
        }

        $normalised = array_values(array_filter($normalised, static fn (string $r): bool => $r !== ''));
        sort($normalised, SORT_STRING);

        return $normalised;
    }
}
