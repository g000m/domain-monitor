<?php
/**
 * Plugin Name: Domain Monitor
 * Description: Proof-of-concept domain health monitor for WordPress.
 * Version: 0.1.0
 * Requires PHP: 7.4
 * Requires at least: 6.6
 * Author: Domain Monitor Contributors
 * License: GPL-2.0-or-later
 * Text Domain: domain-monitor
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
