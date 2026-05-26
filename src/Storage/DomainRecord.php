<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class DomainRecord
{
    /** @var array<string,string|int|null> */
    private array $data;

    /** @param array<string,string|int|null> $data */
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

    public function source(): string
    {
        return (string) ($this->data['source'] ?? 'manual');
    }

    public function dnsStatus(): string
    {
        return (string) ($this->data['dns_status'] ?? 'unknown');
    }

    public function dnsMessage(): string
    {
        return (string) ($this->data['dns_message'] ?? '');
    }

    public function rdapStatus(): string
    {
        return (string) ($this->data['rdap_status'] ?? 'unknown');
    }

    public function rdapMessage(): string
    {
        return (string) ($this->data['rdap_message'] ?? '');
    }

    public function rdapRegistrar(): string
    {
        return (string) ($this->data['rdap_registrar'] ?? '');
    }

    public function rdapExpiresAt(): string
    {
        return (string) ($this->data['rdap_expires_at'] ?? '');
    }

    public function lastCheckedAt(): string
    {
        return (string) ($this->data['last_checked_at'] ?? '');
    }

    /** @return array<string,string|int|null> */
    public function toArray(): array
    {
        return $this->data;
    }
}
