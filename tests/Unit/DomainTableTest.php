<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\DomainTable;
use PHPUnit\Framework\TestCase;

final class DomainTableTest extends TestCase
{
    public function test_it_builds_schema_for_documented_domains_current_state_table(): void
    {
        $schema = DomainTable::schemaSql('wp_domainmon_domains', 'utf8mb4_unicode_ci');

        self::assertStringContainsString('CREATE TABLE wp_domainmon_domains', $schema);
        self::assertStringContainsString('domain varchar(253) NOT NULL', $schema);
        self::assertStringContainsString('domain_hash char(64) NOT NULL', $schema);
        self::assertStringContainsString('is_self tinyint(1) NOT NULL DEFAULT 0', $schema);
        self::assertStringContainsString('is_active tinyint(1) NOT NULL DEFAULT 1', $schema);
        self::assertStringContainsString('owner_site_id bigint(20) unsigned NOT NULL DEFAULT 0', $schema);
        self::assertStringContainsString('rdap_tier tinyint(3) unsigned NOT NULL DEFAULT 0', $schema);
        self::assertStringContainsString('status tinyint(3) unsigned NOT NULL DEFAULT 1', $schema);
        self::assertStringContainsString('status_reason varchar(191) NOT NULL DEFAULT \'\'', $schema);
        self::assertStringContainsString('snapshot mediumtext NULL', $schema);
        self::assertStringContainsString('last_known_good_snapshot mediumtext NULL', $schema);
        self::assertStringContainsString('last_checked_at datetime NULL', $schema);
        self::assertStringContainsString('next_due_at datetime NULL', $schema);
        self::assertStringContainsString('UNIQUE KEY domain_hash (domain_hash)', $schema);
        self::assertStringContainsString('KEY next_due_at (next_due_at)', $schema);
        self::assertStringContainsString('COLLATE utf8mb4_unicode_ci', $schema);
    }

    public function test_it_resolves_documented_table_name_from_wordpress_prefix(): void
    {
        self::assertSame('wp_domainmon_domains', DomainTable::tableName('wp_'));
    }
}
