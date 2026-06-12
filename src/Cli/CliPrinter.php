<?php
declare(strict_types=1);

namespace DomainMonitor\Cli;

/**
 * Abstracts WP-CLI output so the command class can be unit-tested without
 * WP_CLI present.
 */
interface CliPrinter
{
    public function line(string $message): void;

    public function success(string $message): void;

    public function error(string $message, bool $exit = true): void;

    public function warning(string $message): void;

    /**
     * Render a table of rows.
     *
     * @param list<array<string,mixed>> $items
     * @param list<string>             $fields
     * @param string                   $format  table|json|csv|ids|count
     */
    public function formatItems(array $items, array $fields, string $format): void;
}
