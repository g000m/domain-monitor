<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DnsResult
{
    private string $status;
    private string $message;
    /**
     * Per-record-type arrays captured during the check.
     * Keys: 'a', 'aaaa', 'ns', 'mx'.
     * A/AAAA/NS values are plain strings; MX values are ['priority'=>int,'host'=>string].
     * Empty array means records were not captured (e.g. degraded path).
     *
     * @var array<string,list<mixed>>
     */
    private array $records;

    /**
     * @param array<string,list<mixed>> $records
     */
    public function __construct(string $status, string $message, array $records = [])
    {
        $this->status  = $status;
        $this->message = $message;
        $this->records = $records;
    }

    public function status(): string
    {
        return $this->status;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * Per-record-type arrays for snapshot diffing.
     *
     * @return array<string,list<mixed>>
     */
    public function records(): array
    {
        return $this->records;
    }
}
