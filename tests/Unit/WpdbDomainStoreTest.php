<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\WpdbDomainStore;
use PHPUnit\Framework\TestCase;

final class WpdbDomainStoreTest extends TestCase
{
    public function test_it_inserts_new_domain_when_no_existing_hash_matches(): void
    {
        $wpdb = new FakeWpdbForDomainStore();
        $store = new WpdbDomainStore($wpdb, 'wp_domainmon_domains');

        $id = $store->upsertDomain('example.com', 'auto');

        self::assertSame(1, $id);
        self::assertSame('wp_domainmon_domains', $wpdb->insertedTable);
        self::assertSame('example.com', $wpdb->insertedData['domain']);
        self::assertSame(hash('sha256', 'example.com'), $wpdb->insertedData['domain_hash']);
        self::assertSame(1, $wpdb->insertedData['is_self']);
        self::assertSame(1, $wpdb->insertedData['is_active']);
        self::assertSame(1, $wpdb->insertedData['status']);
        self::assertSame('', $wpdb->insertedData['status_reason']);
    }

    public function test_it_updates_check_result_with_snapshot_and_safe_allowed_fields_only(): void
    {
        $wpdb = new FakeWpdbForDomainStore();
        $wpdb->rows[7] = ['id' => 7, 'domain' => 'example.com'];
        $store = new WpdbDomainStore($wpdb, 'wp_domainmon_domains');

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

        self::assertSame('wp_domainmon_domains', $wpdb->updatedTable);
        self::assertArrayHasKey('snapshot', $wpdb->updatedData);
        self::assertArrayHasKey('status', $wpdb->updatedData);
        self::assertArrayNotHasKey('unexpected', $wpdb->updatedData);
        self::assertSame(['id' => 7], $wpdb->updatedWhere);
        self::assertStringContainsString('RDAP timeout.', $wpdb->updatedData['snapshot']);
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
    /** @var array<int,array<string,mixed>> */
    public array $rows = [];

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
        return array_map(static function (array $row): object {
            return (object) $row;
        }, array_values($this->rows));
    }

    public function get_row(string $query)
    {
        if (preg_match('/WHERE id = (\d+)/', $query, $matches) === 1) {
            $id = (int) $matches[1];
            return isset($this->rows[$id]) ? (object) $this->rows[$id] : null;
        }

        return null;
    }
}
