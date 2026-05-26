<?php
declare(strict_types=1);

namespace DomainMonitor;

use DomainMonitor\Admin\DashboardWidget;

final class Plugin
{
    public function register(): void
    {
        if (! $this->isAdminRequest()) {
            return;
        }

        add_action('wp_dashboard_setup', function (): void {
            (new DashboardWidget($this->currentDomain()))->register();
        });
    }

    private function currentDomain(): string
    {
        $url = '';

        if (function_exists('home_url')) {
            $url = (string) home_url('/');
        }

        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            return strtolower($host);
        }

        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return strtolower(preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST']) ?? $_SERVER['HTTP_HOST']);
        }

        return 'unknown domain';
    }

    private function isAdminRequest(): bool
    {
        return function_exists('is_admin') && is_admin();
    }
}
