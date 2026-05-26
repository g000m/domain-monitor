<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\Installer;
use PHPUnit\Framework\TestCase;

final class InstallerTest extends TestCase
{
    public function test_it_runs_dbdelta_with_domain_table_schema_and_stores_schema_version(): void
    {
        $db = new FakeWpdbForInstaller();
        $dbDeltaCalls = [];
        $options = [];
        $installer = new Installer(
            $db,
            static function (string $sql) use (&$dbDeltaCalls): void {
                $dbDeltaCalls[] = $sql;
            },
            static function (string $name, string $value) use (&$options): void {
                $options[$name] = $value;
            }
        );

        $installer->install();

        self::assertCount(1, $dbDeltaCalls);
        self::assertStringContainsString('CREATE TABLE wp_domain_monitor_domains', $dbDeltaCalls[0]);
        self::assertSame('1', $options['domain_monitor_schema_version']);
    }
}

final class FakeWpdbForInstaller
{
    public string $prefix = 'wp_';

    public function get_charset_collate(): string
    {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }
}
