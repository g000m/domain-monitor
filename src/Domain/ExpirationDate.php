<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

use DateTimeImmutable;
use Exception;

final class ExpirationDate
{
    public static function label(string $rdapTimestamp): string
    {
        $rdapTimestamp = trim($rdapTimestamp);
        if ($rdapTimestamp === '') {
            return 'Unknown';
        }

        try {
            return (new DateTimeImmutable($rdapTimestamp))->format('Y-m-d');
        } catch (Exception $exception) {
            return $rdapTimestamp;
        }
    }
}
