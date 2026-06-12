<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit\Cli;

use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Cli\DomainMonitorCommand;
use DomainMonitor\Plugin;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use DomainMonitor\Tests\Unit\Support\FakeCheckRunner;
use DomainMonitor\Tests\Unit\Support\FakeNotifier;
use PHPUnit\Framework\TestCase;

final class DomainMonitorCommandTest extends TestCase
{
    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makeSetup(?FakeCheckRunner $runner = null): array
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();
        $runner     = $runner ?? new FakeCheckRunner();
        $plugin     = new Plugin($repository, $runner, $notifier, $alertStore);
        $actions    = new DomainAdminActions($repository, $runner, 'test.example.com');
        $printer    = new FakeCliPrinter();
        $command    = new DomainMonitorCommand($repository, $plugin, $actions, $printer);

        return compact('store', 'repository', 'alertStore', 'runner', 'plugin', 'actions', 'printer', 'command');
    }

    // -----------------------------------------------------------------
    // list subcommand
    // -----------------------------------------------------------------

    public function testListWarnsWhenNoDomains(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->list([], []);

        $this->assertCount(1, $printer->warnings);
        $this->assertStringContainsString('No domains', $printer->warnings[0]);
        $this->assertCount(0, $printer->tables);
    }

    public function testListRendersTableWithDomains(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('example.com', 'manual');
        $repository->upsertDomain('other.com', 'auto');

        $command->list([], []);

        $this->assertCount(1, $printer->tables);
        $table = $printer->tables[0];
        $this->assertSame('table', $table['format']);
        $this->assertSame(['id', 'domain', 'source', 'status', 'last_checked_at'], $table['fields']);
        $this->assertCount(2, $table['items']);
        $this->assertSame('example.com', $table['items'][0]['domain']);
        $this->assertSame('manual', $table['items'][0]['source']);
        $this->assertSame('never', $table['items'][0]['last_checked_at']);
    }

    public function testListRespectsFormatAssocArg(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('example.com', 'manual');

        $command->list([], ['format' => 'json']);

        $this->assertCount(1, $printer->tables);
        $this->assertSame('json', $printer->tables[0]['format']);
    }

    public function testListShowsStatusCode(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('example.com', 'manual');

        $command->list([], []);

        // A domain that has never been checked reports 'warn' (per DomainRecord::statusCode).
        $this->assertSame('warn', $printer->tables[0]['items'][0]['status']);
    }

    // -----------------------------------------------------------------
    // check subcommand (all)
    // -----------------------------------------------------------------

    public function testCheckAllWarnsWhenNoDomains(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->check([], []);

        $this->assertCount(1, $printer->warnings);
        $this->assertStringContainsString('No domains', $printer->warnings[0]);
    }

    public function testCheckAllRunsAndSucceeds(): void
    {
        // FakeCheckRunner returns a healthy result by default.
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('example.com', 'manual');

        $command->check([], []);

        $this->assertCount(1, $printer->lines);
        $this->assertStringContainsString('example.com', $printer->lines[0]);
        $this->assertCount(1, $printer->successes);
        $this->assertStringContainsString('1 domain', $printer->successes[0]);
    }

    public function testCheckAllOutputsLinePerDomain(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('a.com', 'manual');
        $repository->upsertDomain('b.com', 'manual');

        $command->check([], []);

        $this->assertCount(2, $printer->lines);
        $this->assertStringContainsString('a.com', $printer->lines[0]);
        $this->assertStringContainsString('b.com', $printer->lines[1]);
    }

    // -----------------------------------------------------------------
    // check subcommand (single by name)
    // -----------------------------------------------------------------

    public function testCheckSingleByDomainName(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $repository->upsertDomain('example.com', 'manual');

        $command->check(['example.com'], []);

        $this->assertCount(1, $printer->successes);
        $this->assertStringContainsString('example.com', $printer->successes[0]);
    }

    public function testCheckSingleByNumericId(): void
    {
        ['repository' => $repository, 'printer' => $printer, 'command' => $command] = $this->makeSetup();

        $id = $repository->upsertDomain('example.com', 'manual');

        $command->check([(string) $id], []);

        $this->assertCount(1, $printer->successes);
        $this->assertStringContainsString('example.com', $printer->successes[0]);
    }

    public function testCheckSingleErrorsForUnknownDomain(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->check(['notexist.com'], []);

        $this->assertCount(1, $printer->errors);
        $this->assertStringContainsString('notexist.com', $printer->errors[0]);
    }

    // -----------------------------------------------------------------
    // add subcommand
    // -----------------------------------------------------------------

    public function testAddDomainSucceeds(): void
    {
        ['printer' => $printer, 'command' => $command, 'repository' => $repository] = $this->makeSetup();

        $command->add(['newdomain.com'], []);

        $this->assertCount(1, $printer->successes);
        $this->assertStringContainsString('ID', $printer->successes[0]);

        // Verify domain is actually stored.
        $all = $repository->all();
        $this->assertCount(1, $all);
        $this->assertSame('newdomain.com', $all[0]->domain());
    }

    public function testAddErrorsOnEmptyInput(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->add([''], []);

        $this->assertCount(1, $printer->errors);
        $this->assertCount(0, $printer->successes);
    }

    public function testAddErrorsOnInvalidDomain(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->add(['not a valid domain!!'], []);

        $this->assertCount(1, $printer->errors);
        $this->assertCount(0, $printer->successes);
    }

    public function testAddMissingArgErrors(): void
    {
        ['printer' => $printer, 'command' => $command] = $this->makeSetup();

        $command->add([], []);

        $this->assertCount(1, $printer->errors);
    }
}
