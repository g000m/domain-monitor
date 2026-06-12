<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit\Support;

use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Storage\DomainRecord;

/**
 * Captures notifications without sending real email.
 * Inject into Plugin or other collaborators that accept a DomainNotifier.
 */
final class FakeNotifier implements DomainNotifier
{
    /** @var list<array{record: DomainRecord, from: string, to: string}> */
    public array $notifications = [];

    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        $this->notifications[] = [
            'record' => $record,
            'from'   => $from,
            'to'     => $to,
        ];
    }
}
