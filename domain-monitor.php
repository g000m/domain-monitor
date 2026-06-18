<?php
/**
 * Plugin Name: Domain Monitor
 * Description: Proof-of-concept domain health monitor for WordPress.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires at least: 6.6
 * Author: Gabe Herbert
 * License: GPL-2.0-or-later
 * Text Domain: domain-monitor
 * Domain Path: /languages
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    exit;
}

define('DOMAIN_MONITOR_VERSION', '0.1.0');
define('DOMAIN_MONITOR_PLUGIN_FILE', __FILE__);

$domain_monitor_autoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($domain_monitor_autoload)) {
    require_once $domain_monitor_autoload;
}

register_activation_hook(__FILE__, static function (): void {
    global $wpdb;

    if (class_exists(\DomainMonitor\Storage\Installer::class)) {
        (new \DomainMonitor\Storage\Installer($wpdb))->install();
    }

    // Schedule the daily domain check if not already scheduled.
    if (function_exists('wp_next_scheduled') && ! wp_next_scheduled('domain_monitor_daily_check')) {
        wp_schedule_event(time(), 'daily', 'domain_monitor_daily_check');
    }

    // Auto-detect the current site's domain so the first check can run without
    // requiring a manual visit to wp-admin.
    if (
        class_exists(\DomainMonitor\Admin\DomainAdminActions::class) &&
        class_exists(\DomainMonitor\Storage\DomainRepository::class) &&
        class_exists(\DomainMonitor\Storage\WpdbDomainStore::class) &&
        class_exists(\DomainMonitor\Storage\DomainTable::class) &&
        function_exists('home_url')
    ) {
        $domain_monitor_store = new \DomainMonitor\Storage\WpdbDomainStore(
            $wpdb,
            \DomainMonitor\Storage\DomainTable::tableName((string) $wpdb->prefix)
        );
        $domain_monitor_repo  = new \DomainMonitor\Storage\DomainRepository($domain_monitor_store);
        $domain_monitor_host  = (string) parse_url((string) home_url('/'), PHP_URL_HOST);
        if ($domain_monitor_host !== '') {
            $domain_monitor_actions = new \DomainMonitor\Admin\DomainAdminActions(
                $domain_monitor_repo,
                null,
                $domain_monitor_host
            );
            $domain_monitor_actions->ensureAutoDetectedDomain();
        }
    }
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('domain_monitor_daily_check');
});

add_action('plugins_loaded', static function (): void {
    if (class_exists(\DomainMonitor\Plugin::class)) {
        (new \DomainMonitor\Plugin())->register();
    }

    // Upgrade schema if needed (e.g. plugin updated without reactivation).
    if (class_exists(\DomainMonitor\Storage\Installer::class)) {
        global $wpdb;
        (new \DomainMonitor\Storage\Installer($wpdb))->maybeUpgrade();
    }

    // Self-update from GitHub releases. This only activates when the
    // plugin-update-checker library is bundled, i.e. the GitHub "dogfood" build
    // produced by `bin/build.sh --with-updater`. It is intentionally absent from
    // the default/WordPress.org build, where updates are served by the repository.
    $domain_monitor_puc = '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
    if (class_exists($domain_monitor_puc)) {
        $domain_monitor_update_checker = $domain_monitor_puc::buildUpdateChecker(
            'https://github.com/g000m/domain-monitor/',
            DOMAIN_MONITOR_PLUGIN_FILE,
            'domain-monitor'
        );
        // Use the packaged release asset (built with a production autoloader),
        // not GitHub's source zipball, which would lack vendor/.
        $domain_monitor_update_checker->getVcsApi()->enableReleaseAssets(
            '/domain-monitor-[0-9.]+\\.zip($|[?&#])/i'
        );
    }
});

if (defined('WP_CLI') && WP_CLI && class_exists(\DomainMonitor\Cli\DomainMonitorCommand::class)) {
    \WP_CLI::add_command('domain-monitor', \DomainMonitor\Cli\DomainMonitorCommand::create());
}
