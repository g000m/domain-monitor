<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class RdapResult
{
    private string $status;
    private ?string $expiresAt;
    private ?string $registrar;
    private string $message;

    public function __construct(string $status, ?string $expiresAt = null, ?string $registrar = null, string $message = '')
    {
        $this->status = $status;
        $this->expiresAt = $expiresAt;
        $this->registrar = $registrar;
        $this->message = $message;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function expiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function registrar(): ?string
    {
        return $this->registrar;
    }

    public function message(): string
    {
        return $this->message;
    }
}
