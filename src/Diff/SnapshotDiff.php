<?php
declare(strict_types=1);

namespace DomainMonitor\Diff;

final class SnapshotDiff
{
    private string $type;
    private string $message;
    private string $recordType;

    /**
     * @param string $type       Broad event category, e.g. 'dns_change'.
     * @param string $message    Human-readable description.
     * @param string $recordType The specific DNS record type that changed (a, mx, ns, ...).
     */
    public function __construct(string $type, string $message, string $recordType = '')
    {
        $this->type       = $type;
        $this->message    = $message;
        $this->recordType = $recordType;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * The specific DNS record type that changed (a, aaaa, cname, mx, ns, ...).
     * Empty string if not applicable.
     */
    public function recordType(): string
    {
        return $this->recordType;
    }
}
