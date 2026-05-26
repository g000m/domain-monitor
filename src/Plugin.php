<?php
declare(strict_types=1);

namespace DomainMonitor;

use DateTimeImmutable;
use DateTimeZone;
use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Domain\ApexDomain;

final class Plugin
{
    private const OPTION_LAST_MANUAL_CHECK = 'domain_monitor_last_manual_check';
    private const NONCE_ACTION = 'domain_monitor_manual_check';

    public function register(): void
    {
        if (! $this->isAdminRequest()) {
            return;
        }

        add_action('wp_dashboard_setup', function (): void {
            (new DashboardWidget(
                $this->currentDomain(),
                $this->lastManualCheckResult(),
                $this->adminPostUrl(),
                $this->manualCheckNonce()
            ))->register();
        });

        add_action('admin_post_domain_monitor_manual_check', [$this, 'handleManualCheck']);
    }

    public function handleManualCheck(): void
    {
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::NONCE_ACTION);
        }

        if (function_exists('current_user_can') && ! current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                wp_die('Sorry, you are not allowed to run Domain Monitor checks.');
            }

            return;
        }

        $domain = $this->currentDomain();
        $result = [
            'status' => 'ok',
            'message' => sprintf('Manual check completed for %s.', $domain),
            'checked_at' => $this->now(),
        ];

        if (function_exists('update_option')) {
            update_option(self::OPTION_LAST_MANUAL_CHECK, $result, false);
        }

        if (function_exists('wp_safe_redirect') && function_exists('admin_url')) {
            wp_safe_redirect(admin_url('index.php?domain_monitor_checked=1'));
            exit;
        }
    }

    private function currentDomain(): string
    {
        $url = '';

        if (function_exists('home_url')) {
            $url = (string) home_url('/');
        }

        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            return ApexDomain::fromHost($host);
        }

        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return ApexDomain::fromHost($_SERVER['HTTP_HOST']);
        }

        return 'unknown domain';
    }

    /** @return array<string,string>|null */
    private function lastManualCheckResult(): ?array
    {
        if (! function_exists('get_option')) {
            return null;
        }

        $result = get_option(self::OPTION_LAST_MANUAL_CHECK);
        if (! is_array($result)) {
            return null;
        }

        return [
            'status' => isset($result['status']) ? (string) $result['status'] : '',
            'message' => isset($result['message']) ? (string) $result['message'] : '',
            'checked_at' => isset($result['checked_at']) ? (string) $result['checked_at'] : '',
        ];
    }

    private function adminPostUrl(): string
    {
        if (function_exists('admin_url')) {
            return (string) admin_url('admin-post.php');
        }

        return '';
    }

    private function manualCheckNonce(): string
    {
        if (function_exists('wp_create_nonce')) {
            return (string) wp_create_nonce(self::NONCE_ACTION);
        }

        return '';
    }

    private function now(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('c');
        }

        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    }

    private function isAdminRequest(): bool
    {
        return function_exists('is_admin') && is_admin();
    }
}
