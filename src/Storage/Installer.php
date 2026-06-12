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
    /** @var callable */
    private $getOption;

    /**
     * @param object $wpdb WordPress wpdb-like object.
     * @param callable|null $dbDelta Receives schema SQL.
     * @param callable|null $updateOption Receives option name and value.
     * @param callable|null $getOption Receives option name, returns stored value or false.
     */
    public function __construct($wpdb, ?callable $dbDelta = null, ?callable $updateOption = null, ?callable $getOption = null)
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
        $this->getOption = $getOption ?? static function (string $name) {
            if (function_exists('get_option')) {
                return get_option($name, false);
            }
            return false;
        };
    }

    public function install(): void
    {
        $prefix   = (string) ($this->wpdb->prefix ?? 'wp_');
        $collate  = method_exists($this->wpdb, 'get_charset_collate') ? (string) $this->wpdb->get_charset_collate() : '';
        $dbDelta  = $this->dbDelta;
        $updateOption = $this->updateOption;

        $dbDelta(DomainTable::schemaSql(DomainTable::tableName($prefix), $collate));
        $dbDelta(DomainTable::alertsSchemaSql(DomainTable::alertsTableName($prefix), $collate));
        $updateOption(DomainTable::OPTION_SCHEMA_VERSION, DomainTable::SCHEMA_VERSION);
    }

    /**
     * Run an upgrade if the stored schema version is below the current one.
     * Safe to call on every plugins_loaded.
     */
    public function maybeUpgrade(): void
    {
        $getOption = $this->getOption;
        $stored = (string) $getOption(DomainTable::OPTION_SCHEMA_VERSION);

        if ($stored === DomainTable::SCHEMA_VERSION) {
            return;
        }

        $this->install();
    }
}
