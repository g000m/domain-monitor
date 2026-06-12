<?php
declare(strict_types=1);

namespace DomainMonitor\Notifications;

use DomainMonitor\Storage\DomainRecord;

interface DomainNotifier
{
    /**
     * Called when a domain's status transitions to a worse state.
     *
     * @param DomainRecord $record     The freshly-checked domain record.
     * @param string       $from       Previous status code (ok / warn / fail).
     * @param string       $to         New (worse) status code.
     */
    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void;
}
