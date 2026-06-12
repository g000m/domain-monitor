<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

use DateTimeImmutable;
use DomainMonitor\Domain\StatusCalculator;

final class DomainRecord
{
    /** @var array<string,mixed> */
    private array $data;

    /** @param array<string,mixed> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function id(): int
    {
        return (int) ($this->data['id'] ?? 0);
    }

    public function domain(): string
    {
        return (string) ($this->data['domain'] ?? '');
    }

    public function domainHash(): string
    {
        return (string) ($this->data['domain_hash'] ?? hash('sha256', $this->domain()));
    }

    public function source(): string
    {
        return (string) ($this->data['source'] ?? 'manual');
    }

    public function isSelf(): bool
    {
        return (int) ($this->data['is_self'] ?? ($this->source() === 'auto' ? 1 : 0)) === 1;
    }

    public function isActive(): bool
    {
        return (int) ($this->data['is_active'] ?? 1) === 1;
    }

    public function status(): int
    {
        return (int) ($this->data['status'] ?? 1);
    }

    public function statusReason(): string
    {
        return (string) ($this->data['status_reason'] ?? '');
    }

    /**
     * Returns the string status code (ok / warn / fail) computed via
     * StatusCalculator so all callers use a consistent definition.
     *
     * A record that has never been checked (no snapshot, no last_checked_at)
     * reports warn so the admin knows it still needs a first check.
     *
     * @param list<array<string,mixed>> $alerts Open alerts for this domain (from AlertStore).
     */
    public function statusCode(array $alerts = []): string
    {
        if ($this->lastCheckedAt() === '' && $this->snapshot() === []) {
            return StatusCalculator::STATUS_WARN;
        }

        $calculator = new StatusCalculator();
        $result = $calculator->calculate($this->snapshot(), $alerts, new DateTimeImmutable());
        return $result->code();
    }

    public function dnsStatus(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['dns']['status'] ?? $this->data['dns_status'] ?? 'unknown');
    }

    public function dnsMessage(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['dns']['message'] ?? $this->data['dns_message'] ?? '');
    }

    public function rdapStatus(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['rdap']['status'] ?? $this->data['rdap_status'] ?? 'unknown');
    }

    public function rdapMessage(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['rdap']['message'] ?? $this->data['rdap_message'] ?? '');
    }

    public function rdapRegistrar(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['rdap']['registrar'] ?? $this->data['rdap_registrar'] ?? '');
    }

    public function rdapExpiresAt(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['rdap']['expires_at'] ?? $this->data['rdap_expires_at'] ?? '');
    }

    /** @return bool|null */
    public function rdapTransferLocked()
    {
        $snapshot = $this->snapshot();
        $val = $snapshot['rdap']['transfer_locked'] ?? null;
        if ($val === null) {
            return null;
        }

        return (bool) $val;
    }

    public function sslStatus(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['ssl']['status'] ?? '');
    }

    public function sslExpiresAt(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['ssl']['expires_at'] ?? '');
    }

    public function sslIssuer(): string
    {
        $snapshot = $this->snapshot();
        return (string) ($snapshot['ssl']['issuer'] ?? '');
    }

    public function lastCheckedAt(): string
    {
        return (string) ($this->data['last_checked_at'] ?? '');
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $snapshot = $this->data['snapshot'] ?? null;
        if (is_array($snapshot)) {
            return $snapshot;
        }

        if (is_string($snapshot) && $snapshot !== '') {
            $decoded = json_decode($snapshot, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return $this->data;
    }
}
