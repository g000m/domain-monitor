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
}
