<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

interface AlertStore
{
    /**
     * Insert a new alert row. Returns the new alert id.
     *
     * @param array<string,mixed>|null $details
     */
    public function createAlert(int $domainId, string $type, string $message, ?array $details = null): int;

    /**
     * Return all open (unresolved) alerts for a domain.
     *
     * @return list<array<string,mixed>>
     */
    public function openAlertsForDomain(int $domainId): array;

    /**
     * Mark all open alerts of a given type for a domain as resolved.
     */
    public function resolveAlertsForDomain(int $domainId, string $type, string $resolvedAt): void;

    /**
     * Mark a single alert row as resolved by its primary-key id.
     */
    public function resolveAlert(int $alertId, string $resolvedAt): void;

    /**
     * Return alerts for a domain that were resolved within the last $withinDays days.
     * These are passed to StatusCalculator so the 3-day amber persistence path works.
     *
     * @return list<array<string,mixed>>
     */
    public function recentlyResolvedAlertsForDomain(int $domainId, int $withinDays, string $now): array;
}
