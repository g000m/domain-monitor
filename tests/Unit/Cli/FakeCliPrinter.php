<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit\Cli;

use DomainMonitor\Cli\CliPrinter;

/**
 * In-memory CliPrinter for unit tests.
 *
 * Captures all output into typed lists so assertions can be made without
 * WP_CLI being present.
 */
final class FakeCliPrinter implements CliPrinter
{
    /** @var list<string> */
    public array $lines = [];
    /** @var list<string> */
    public array $successes = [];
    /** @var list<string> */
    public array $errors = [];
    /** @var list<string> */
    public array $warnings = [];
    /** @var list<array{items: list<array<string,mixed>>, fields: list<string>, format: string}> */
    public array $tables = [];

    public function line(string $message): void
    {
        $this->lines[] = $message;
    }

    public function success(string $message): void
    {
        $this->successes[] = $message;
    }

    public function error(string $message, bool $exit = true): void
    {
        $this->errors[] = $message;
    }

    public function warning(string $message): void
    {
        $this->warnings[] = $message;
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param list<string>             $fields
     */
    public function formatItems(array $items, array $fields, string $format): void
    {
        $this->tables[] = ['items' => $items, 'fields' => $fields, 'format' => $format];
    }
}
