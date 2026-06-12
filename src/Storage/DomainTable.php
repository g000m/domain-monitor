<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class DomainTable
{
    public const OPTION_SCHEMA_VERSION = 'domain_monitor_schema_version';
    public const SCHEMA_VERSION = '3';

    public static function tableName(string $prefix): string
    {
        return $prefix . 'domainmon_domains';
    }

    public static function alertsTableName(string $prefix): string
    {
        return $prefix . 'domainmon_alerts';
    }

    public static function schemaSql(string $tableName, string $collate): string
    {
        $collate = trim($collate);
        if ($collate === '') {
            $collation = '';
        } elseif (stripos($collate, 'COLLATE') === false && stripos($collate, 'CHARACTER SET') === false) {
            $collation = ' COLLATE ' . $collate;
        } else {
            $collation = ' ' . $collate;
        }

        return "CREATE TABLE {$tableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain varchar(253) NOT NULL,
            domain_hash char(64) NOT NULL,
            source varchar(20) NOT NULL DEFAULT 'manual',
            is_self tinyint(1) NOT NULL DEFAULT 0,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            owner_site_id bigint(20) unsigned NOT NULL DEFAULT 0,
            rdap_tier tinyint(3) unsigned NOT NULL DEFAULT 0,
            status tinyint(3) unsigned NOT NULL DEFAULT 1,
            status_reason varchar(191) NOT NULL DEFAULT '',
            snapshot mediumtext NULL,
            last_known_good_snapshot mediumtext NULL,
            last_checked_at datetime NULL,
            next_due_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain_hash (domain_hash),
            KEY next_due_at (next_due_at),
            KEY status (status),
            KEY is_active (is_active),
            KEY owner_site_id (owner_site_id)
        ){$collation};";
    }

    public static function alertsSchemaSql(string $alertsTableName, string $collate): string
    {
        $collate = trim($collate);
        if ($collate === '') {
            $collation = '';
        } elseif (stripos($collate, 'COLLATE') === false && stripos($collate, 'CHARACTER SET') === false) {
            $collation = ' COLLATE ' . $collate;
        } else {
            $collation = ' ' . $collate;
        }

        return "CREATE TABLE {$alertsTableName} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            domain_id bigint(20) unsigned NOT NULL,
            type varchar(64) NOT NULL,
            message varchar(500) NOT NULL DEFAULT '',
            details mediumtext NULL,
            created_at datetime NOT NULL,
            resolved_at datetime NULL,
            PRIMARY KEY  (id),
            KEY domain_id (domain_id),
            KEY resolved_at (resolved_at)
        ){$collation};";
    }
}
