<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

final class DomainRepositoryTest extends TestCase
{
    public function test_it_upserts_and_lists_domains(): void
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
        self::assertSame('manual', $repository->find($manualId)->source());
    }

    public function test_it_persists_check_results_for_a_domain(): void
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

        self::assertSame('ok', $record->dnsStatus());
        self::assertSame('DNS has A records.', $record->dnsMessage());
        self::assertSame('ok', $record->rdapStatus());
        self::assertSame('Example Registrar', $record->rdapRegistrar());
        self::assertSame('2027-01-01T00:00:00Z', $record->rdapExpiresAt());
        self::assertSame('2026-05-26 21:30:00', $record->lastCheckedAt());
    }
}
