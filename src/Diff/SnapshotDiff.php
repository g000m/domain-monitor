<?php
declare(strict_types=1);

namespace DomainMonitor\Diff;

final class SnapshotDiff
{
    private string $type;
    private string $message;

    public function __construct(string $type, string $message)
    {
        $this->type = $type;
        $this->message = $message;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }
}
