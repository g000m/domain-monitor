<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class Installer
{
    /** @var object */
    private $wpdb;
    /** @var callable */
    private $dbDelta;
    /** @var callable */
    private $updateOption;

    /**
     * @param object $wpdb WordPress wpdb-like object.
     * @param callable|null $dbDelta Receives schema SQL.
     * @param callable|null $updateOption Receives option name and value.
     */
    public function __construct($wpdb, ?callable $dbDelta = null, ?callable $updateOption = null)
    {
        $this->wpdb = $wpdb;
        $this->dbDelta = $dbDelta ?? static function (string $sql): void {
            if (! function_exists('dbDelta')) {
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            }
            dbDelta($sql);
        };
        $this->updateOption = $updateOption ?? static function (string $name, string $value): void {
            if (function_exists('update_option')) {
                update_option($name, $value, false);
            }
        };
    }

    public function install(): void
    {
        $prefix = (string) ($this->wpdb->prefix ?? 'wp_');
        $collate = method_exists($this->wpdb, 'get_charset_collate') ? (string) $this->wpdb->get_charset_collate() : '';
        $sql = DomainTable::schemaSql(DomainTable::tableName($prefix), $collate);
        $dbDelta = $this->dbDelta;
        $updateOption = $this->updateOption;

        $dbDelta($sql);
        $updateOption(DomainTable::OPTION_SCHEMA_VERSION, DomainTable::SCHEMA_VERSION);
    }
}
