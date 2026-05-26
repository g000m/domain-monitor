<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DnsResult
{
    private string $status;
    private string $message;

    public function __construct(string $status, string $message)
    {
        $this->status = $status;
        $this->message = $message;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }
}
