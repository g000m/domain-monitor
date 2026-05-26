<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

final class DomainRepositoryTest extends TestCase
{
    public function test_it_upserts_and_lists_domains_with_documented_current_state_defaults(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());

        $autoId = $repository->upsertDomain('example.com', 'auto');
        $manualId = $repository->upsertDomain('example.net', 'manual');
        $sameAutoId = $repository->upsertDomain('example.com', 'manual');

        self::assertSame($autoId, $sameAutoId);
        self::assertSame(['example.com', 'example.net'], array_map(static function (DomainRecord $record): string {
            return $record->domain();
        }, $repository->all()));
        self::assertSame('auto', $repository->find($autoId)->source());
        self::assertTrue($repository->find($autoId)->isSelf());
        self::assertTrue($repository->find($autoId)->isActive());
        self::assertSame(1, $repository->find($autoId)->status());
        self::assertSame(hash('sha256', 'example.com'), $repository->find($autoId)->domainHash());
        self::assertSame('manual', $repository->find($manualId)->source());
        self::assertFalse($repository->find($manualId)->isSelf());
    }

    public function test_it_persists_snapshot_check_results_for_a_domain(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $id = $repository->upsertDomain('example.com', 'auto');

        $repository->saveCheckResult($id, [
            'dns_status' => 'ok',
            'dns_message' => 'DNS has A records.',
            'rdap_status' => 'ok',
            'rdap_message' => 'RDAP lookup succeeded.',
            'rdap_registrar' => 'Example Registrar',
            'rdap_expires_at' => '2027-01-01T00:00:00Z',
            'last_checked_at' => '2026-05-26 21:30:00',
        ]);

        $record = $repository->find($id);
        $snapshot = $record->snapshot();

        self::assertSame('ok', $record->dnsStatus());
        self::assertSame('DNS has A records.', $record->dnsMessage());
        self::assertSame('ok', $record->rdapStatus());
        self::assertSame('Example Registrar', $record->rdapRegistrar());
        self::assertSame('2027-01-01T00:00:00Z', $record->rdapExpiresAt());
        self::assertSame('2026-05-26 21:30:00', $record->lastCheckedAt());
        self::assertSame('example.com', $snapshot['domain']);
        self::assertSame('2027-01-01T00:00:00Z', $snapshot['rdap']['expires_at']);
        self::assertSame('ok', $snapshot['dns']['status']);
    }
}
