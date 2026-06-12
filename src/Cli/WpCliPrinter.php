<?php
declare(strict_types=1);

namespace DomainMonitor\Cli;

/**
 * Production CliPrinter that delegates to WP_CLI and WP_CLI\Utils.
 *
 * Only instantiated when WP_CLI is available.
 */
final class WpCliPrinter implements CliPrinter
{
    public function line(string $message): void
    {
        \WP_CLI::line($message);
    }

    public function success(string $message): void
    {
        \WP_CLI::success($message);
    }

    public function error(string $message, bool $exit = true): void
    {
        \WP_CLI::error($message, $exit);
    }

    public function warning(string $message): void
    {
        \WP_CLI::warning($message);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<string>             $fields
     */
    public function formatItems(array $items, array $fields, string $format): void
    {
        \WP_CLI\Utils\format_items($format, $items, $fields);
    }
}
