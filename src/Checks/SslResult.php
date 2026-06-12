<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class SslResult
{
    private string $status;
    private ?string $expiresAt;
    private ?string $issuer;
    private string $message;

    public function __construct(string $status, ?string $expiresAt, ?string $issuer, string $message = '')
    {
        $this->status    = $status;
        $this->expiresAt = $expiresAt;
        $this->issuer    = $issuer;
        $this->message   = $message;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function expiresAt(): ?string
    {
        return $this->expiresAt;
    }

    public function issuer(): ?string
    {
        return $this->issuer;
    }

    public function message(): string
    {
        return $this->message;
    }
}
