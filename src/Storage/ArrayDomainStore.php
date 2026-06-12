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

    /**
     * Overwrite the stored snapshot for a row directly.
     * Useful in tests to seed rich snapshot structures without going through
     * snapshotFromResult (which only captures summary fields). Also sets
     * last_checked_at to the snapshot's checked_at so statusCode() does not
     * short-circuit to warn.
     *
     * @param array<string,mixed> $snapshot
     */
    public function overwriteSnapshot(int $id, array $snapshot): void
    {
        if (isset($this->rows[$id])) {
            $this->rows[$id]['snapshot']        = $snapshot;
            $this->rows[$id]['last_checked_at'] = (string) ($snapshot['checked_at'] ?? 'now');
        }
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
        $rdapTransferLocked = isset($result['rdap_transfer_locked'])
            ? ($result['rdap_transfer_locked'] === '1' || $result['rdap_transfer_locked'] === true)
            : null;

        $rdapDomainStatuses = [];
        if (isset($result['rdap_domain_statuses']) && is_string($result['rdap_domain_statuses'])) {
            $decoded = json_decode($result['rdap_domain_statuses'], true);
            if (is_array($decoded)) {
                $rdapDomainStatuses = $decoded;
            }
        }

        $snapshot = [
            'schema_version' => 1,
            'checked_at' => (string) ($result['last_checked_at'] ?? ''),
            'domain' => $domain,
            'rdap' => [
                'status'          => (string) ($result['rdap_status'] ?? 'unknown'),
                'tier'            => 0,
                'registrar'       => (string) ($result['rdap_registrar'] ?? ''),
                'expires_at'      => (string) ($result['rdap_expires_at'] ?? ''),
                'message'         => (string) ($result['rdap_message'] ?? ''),
                'transfer_locked' => $rdapTransferLocked,
                'domain_statuses' => $rdapDomainStatuses,
            ],
            'dns' => self::buildDnsSection($result),
            'errors' => [],
        ];

        if (isset($result['ssl_status'])) {
            $snapshot['ssl'] = [
                'status'     => (string) $result['ssl_status'],
                'expires_at' => isset($result['ssl_expires_at']) ? (string) $result['ssl_expires_at'] : null,
                'issuer'     => isset($result['ssl_issuer']) ? (string) $result['ssl_issuer'] : null,
                'message'    => (string) ($result['ssl_message'] ?? ''),
            ];
        }

        if (isset($result['email_dns']) && is_string($result['email_dns'])) {
            $decoded = json_decode($result['email_dns'], true);
            if (is_array($decoded)) {
                $snapshot['email_dns'] = $decoded;
            }
        }

        return $snapshot;
    }

    /**
     * Build the dns section of a snapshot from a flat check result.
     *
     * If the result includes a 'dns_apex' key (JSON-encoded array of per-record-type
     * arrays), it is merged into the dns section so that SnapshotDiffer can detect
     * per-record-type changes. This is populated by checkers that collect per-type
     * record arrays (e.g. a richer DnsChecker), and also by test helpers.
     *
     * @param array<string,string|null> $result
     * @return array<string,mixed>
     */
    private static function buildDnsSection(array $result): array
    {
        $section = [
            'status'  => (string) ($result['dns_status'] ?? 'unknown'),
            'message' => (string) ($result['dns_message'] ?? ''),
        ];

        if (isset($result['dns_apex']) && is_string($result['dns_apex'])) {
            $apex = json_decode($result['dns_apex'], true);
            if (is_array($apex)) {
                $section['apex'] = $apex;
            }
        }

        return $section;
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
