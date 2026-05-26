<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

final class DomainAdminActionsTest extends TestCase
{
    public function test_it_upserts_auto_detected_apex_domain(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions = new DomainAdminActions($repository, new FakeRunnerForActions(), 'shop.example.co.uk');

        $id = $actions->ensureAutoDetectedDomain();
        $record = $repository->find($id);

        self::assertSame('example.co.uk', $record->domain());
        self::assertSame('auto', $record->source());
    }

    public function test_it_adds_manual_domain_from_user_input_as_apex_domain(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions = new DomainAdminActions($repository, new FakeRunnerForActions(), 'example.com');

        $id = $actions->addDomain('https://www.Example.NET/path');
        $record = $repository->find($id);

        self::assertSame('example.net', $record->domain());
        self::assertSame('manual', $record->source());
    }

    public function test_it_runs_manual_check_for_selected_domain_and_saves_results(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $runner = new FakeRunnerForActions();
        $actions = new DomainAdminActions($repository, $runner, 'example.com');
        $id = $actions->addDomain('example.net');

        $actions->runManualCheck($id);
        $record = $repository->find($id);

        self::assertSame(['example.net'], $runner->checkedDomains);
        self::assertSame('ok', $record->dnsStatus());
        self::assertSame('ok', $record->rdapStatus());
        self::assertSame('2026-05-26 21:30:00', $record->lastCheckedAt());
    }
}

final class FakeRunnerForActions
{
    /** @var list<string> */
    public array $checkedDomains = [];

    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        $this->checkedDomains[] = $domain;

        return [
            'dns_status' => 'ok',
            'dns_message' => 'DNS ok.',
            'rdap_status' => 'ok',
            'rdap_message' => 'RDAP ok.',
            'rdap_registrar' => 'Example Registrar',
            'rdap_expires_at' => '2027-01-01T00:00:00Z',
            'last_checked_at' => '2026-05-26 21:30:00',
        ];
    }
}
