<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Drops custom tables and removes plugin options from every site in the network
 * (multisite-aware). On single-site installs the multisite branch is skipped.
 */

defined('WP_UNINSTALL_PLUGIN') || exit;

/**
 * Clean up one site: drop tables and delete options.
 *
 * @param string $prefix The wpdb table prefix for this site.
 */
function domain_monitor_uninstall_site(string $prefix): void {
    global $wpdb;

    // Resolve table names via DomainTable constants when available; fall back to
    // raw names so uninstall works even when the autoloader is absent.
    if (class_exists(\DomainMonitor\Storage\DomainTable::class)) {
        $domainsTable = \DomainMonitor\Storage\DomainTable::tableName($prefix);
        $alertsTable  = \DomainMonitor\Storage\DomainTable::alertsTableName($prefix);
    } else {
        $domainsTable = $prefix . 'domainmon_domains';
        $alertsTable  = $prefix . 'domainmon_alerts';
    }

    // Drop alerts first (references domain_id from domains table).
    $wpdb->query("DROP TABLE IF EXISTS `{$alertsTable}`");   // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
    $wpdb->query("DROP TABLE IF EXISTS `{$domainsTable}`");  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

    // Delete all plugin options stored for this site.
    delete_option('domain_monitor_schema_version');
}

global $wpdb;

if (is_multisite()) {
    $sites = get_sites(['number' => 0, 'fields' => 'ids']);
    foreach ($sites as $site_id) {
        switch_to_blog((int) $site_id);
        domain_monitor_uninstall_site($wpdb->prefix);
        restore_current_blog();
    }
} else {
    domain_monitor_uninstall_site($wpdb->prefix);
}
