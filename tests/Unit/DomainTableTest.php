<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\DomainTable;
use PHPUnit\Framework\TestCase;

final class DomainTableTest extends TestCase
{
    public function test_it_builds_schema_for_domains_custom_table(): void
    {
        $schema = DomainTable::schemaSql('wp_domain_monitor_domains', 'utf8mb4_unicode_ci');

        self::assertStringContainsString('CREATE TABLE wp_domain_monitor_domains', $schema);
        self::assertStringContainsString('domain varchar(253) NOT NULL', $schema);
        self::assertStringContainsString('source varchar(20) NOT NULL DEFAULT \'manual\'', $schema);
        self::assertStringContainsString('dns_status varchar(20) NOT NULL DEFAULT \'unknown\'', $schema);
        self::assertStringContainsString('rdap_status varchar(20) NOT NULL DEFAULT \'unknown\'', $schema);
        self::assertStringContainsString('last_checked_at datetime NULL', $schema);
        self::assertStringContainsString('UNIQUE KEY domain (domain)', $schema);
        self::assertStringContainsString('COLLATE utf8mb4_unicode_ci', $schema);
    }

    public function test_it_resolves_table_name_from_wordpress_prefix(): void
    {
        self::assertSame('wp_domain_monitor_domains', DomainTable::tableName('wp_'));
    }
}
