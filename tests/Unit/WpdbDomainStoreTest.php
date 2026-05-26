<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\WpdbDomainStore;
use PHPUnit\Framework\TestCase;

final class WpdbDomainStoreTest extends TestCase
{
    public function test_it_inserts_new_domain_when_no_existing_row_matches(): void
    {
        $wpdb = new FakeWpdbForDomainStore();
        $store = new WpdbDomainStore($wpdb, 'wp_domain_monitor_domains');

        $id = $store->upsertDomain('example.com', 'auto');

        self::assertSame(1, $id);
        self::assertSame('wp_domain_monitor_domains', $wpdb->insertedTable);
        self::assertSame('example.com', $wpdb->insertedData['domain']);
        self::assertSame('auto', $wpdb->insertedData['source']);
        self::assertSame('unknown', $wpdb->insertedData['dns_status']);
    }

    public function test_it_updates_check_result_with_safe_allowed_fields_only(): void
    {
        $wpdb = new FakeWpdbForDomainStore();
        $store = new WpdbDomainStore($wpdb, 'wp_domain_monitor_domains');

        $store->saveCheckResult(7, [
            'dns_status' => 'ok',
            'dns_message' => 'DNS has records.',
            'rdap_status' => 'degraded',
            'rdap_message' => 'RDAP timeout.',
            'rdap_registrar' => null,
            'rdap_expires_at' => null,
            'last_checked_at' => '2026-05-26 21:30:00',
            'unexpected' => 'do not save',
        ]);

        self::assertSame('wp_domain_monitor_domains', $wpdb->updatedTable);
        self::assertArrayHasKey('dns_status', $wpdb->updatedData);
        self::assertArrayNotHasKey('unexpected', $wpdb->updatedData);
        self::assertSame(['id' => 7], $wpdb->updatedWhere);
    }
}

final class FakeWpdbForDomainStore
{
    public int $insert_id = 1;
    public string $insertedTable = '';
    /** @var array<string,mixed> */
    public array $insertedData = [];
    public string $updatedTable = '';
    /** @var array<string,mixed> */
    public array $updatedData = [];
    /** @var array<string,mixed> */
    public array $updatedWhere = [];

    public function get_var(string $query)
    {
        return null;
    }

    public function prepare(string $query, ...$args): string
    {
        foreach ($args as $arg) {
            $query = preg_replace('/%[ds]/', (string) $arg, $query, 1);
        }

        return $query;
    }

    /** @param array<string,mixed> $data */
    public function insert(string $table, array $data): bool
    {
        $this->insertedTable = $table;
        $this->insertedData = $data;
        return true;
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    public function update(string $table, array $data, array $where): bool
    {
        $this->updatedTable = $table;
        $this->updatedData = $data;
        $this->updatedWhere = $where;
        return true;
    }

    /** @return list<object> */
    public function get_results(string $query): array
    {
        return [];
    }

    public function get_row(string $query)
    {
        return null;
    }
}
