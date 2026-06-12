<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\DomainTable;
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

        self::assertCount(2, $dbDeltaCalls);
        self::assertStringContainsString('CREATE TABLE wp_domainmon_domains', $dbDeltaCalls[0]);
        self::assertStringContainsString('CREATE TABLE wp_domainmon_alerts', $dbDeltaCalls[1]);
        self::assertSame(DomainTable::SCHEMA_VERSION, $options['domain_monitor_schema_version']);
    }

    public function test_maybe_upgrade_skips_when_version_is_current(): void
    {
        $db = new FakeWpdbForInstaller();
        $dbDeltaCalls = [];
        $installer = new Installer(
            $db,
            static function (string $sql) use (&$dbDeltaCalls): void {
                $dbDeltaCalls[] = $sql;
            },
            static function (string $name, string $value): void {},
            static function (string $name) { return DomainTable::SCHEMA_VERSION; }
        );

        $installer->maybeUpgrade();

        self::assertCount(0, $dbDeltaCalls);
    }

    public function test_maybe_upgrade_runs_install_when_version_is_old(): void
    {
        $db = new FakeWpdbForInstaller();
        $dbDeltaCalls = [];
        $installer = new Installer(
            $db,
            static function (string $sql) use (&$dbDeltaCalls): void {
                $dbDeltaCalls[] = $sql;
            },
            static function (string $name, string $value): void {},
            static function (string $name) { return '1'; }
        );

        $installer->maybeUpgrade();

        self::assertCount(2, $dbDeltaCalls);
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
