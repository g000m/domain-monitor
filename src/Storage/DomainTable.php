<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class DomainTable
{
    public const OPTION_SCHEMA_VERSION = 'domain_monitor_schema_version';
    public const SCHEMA_VERSION = '1';

    public static function tableName(string $prefix): string
    {
        return $prefix . 'domain_monitor_domains';
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
            source varchar(20) NOT NULL DEFAULT 'manual',
            dns_status varchar(20) NOT NULL DEFAULT 'unknown',
            dns_message text NULL,
            rdap_status varchar(20) NOT NULL DEFAULT 'unknown',
            rdap_message text NULL,
            rdap_registrar varchar(255) NULL,
            rdap_expires_at varchar(40) NULL,
            last_checked_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY domain (domain),
            KEY last_checked_at (last_checked_at)
        ){$collation};";
    }
}
