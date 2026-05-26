<?php
declare(strict_types=1);

namespace DomainMonitor;

use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Admin\SettingsPage;
use DomainMonitor\Checks\DnsChecker;
use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\NativeDnsResolver;
use DomainMonitor\Checks\RdapChecker;
use DomainMonitor\Checks\WordPressHttpClient;
use DomainMonitor\Domain\ApexDomain;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use DomainMonitor\Storage\DomainTable;
use DomainMonitor\Storage\WpdbDomainStore;
use InvalidArgumentException;

final class Plugin
{
    private const NONCE_ACTION = 'domain_monitor_manual_check';
    private const ADD_DOMAIN_ACTION = 'domain_monitor_add_domain';

    public function register(): void
    {
        if (! $this->isAdminRequest()) {
            return;
        }

        add_action('admin_init', function (): void {
            $this->actions()->ensureAutoDetectedDomain();
        });

        add_action('admin_menu', function (): void {
            (new SettingsPage(
                $this->repository()->all(),
                $this->adminPostUrl(),
                $this->manualCheckNonce()
            ))->register();
        });

        add_action('wp_dashboard_setup', function (): void {
            (new DashboardWidget(
                $this->currentDomain(),
                $this->dashboardResult(),
                $this->adminPostUrl(),
                $this->manualCheckNonce()
            ))->register();
        });

        add_action('admin_post_' . self::ADD_DOMAIN_ACTION, [$this, 'handleAddDomain']);
        add_action('admin_post_domain_monitor_manual_check', [$this, 'handleManualCheck']);
    }

    public function handleAddDomain(): void
    {
        $this->verifyAdminAction();

        $domain = isset($_POST['domain_monitor_domain']) ? (string) $this->unslash($_POST['domain_monitor_domain']) : '';
        try {
            $this->actions()->addDomain($domain);
            $this->redirectToSettings('domain_monitor_added=1');
        } catch (InvalidArgumentException $exception) {
            $this->redirectToSettings('domain_monitor_error=invalid_domain');
        }
    }

    public function handleManualCheck(): void
    {
        $this->verifyAdminAction();

        $domainId = null;
        if (isset($_POST['domain_monitor_domain_id'])) {
            $domainId = max(1, (int) $this->unslash($_POST['domain_monitor_domain_id']));
        }

        $this->actions()->runManualCheck($domainId);

        $referrer = function_exists('wp_get_referer') ? (string) wp_get_referer() : '';
        if ($referrer !== '') {
            $this->redirect($this->addQueryArg('domain_monitor_checked', '1', $referrer));
        }

        $this->redirect(function_exists('admin_url') ? (string) admin_url('index.php?domain_monitor_checked=1') : '');
    }

    private function verifyAdminAction(): void
    {
        if (function_exists('check_admin_referer')) {
            check_admin_referer(self::NONCE_ACTION);
        }

        if (function_exists('current_user_can') && ! current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                wp_die('Sorry, you are not allowed to manage Domain Monitor.');
            }

            return;
        }
    }

    private function actions(): DomainAdminActions
    {
        return new DomainAdminActions($this->repository(), $this->checkRunner(), $this->currentHost());
    }

    private function repository(): DomainRepository
    {
        global $wpdb;

        return new DomainRepository(new WpdbDomainStore($wpdb, DomainTable::tableName((string) $wpdb->prefix)));
    }

    private function checkRunner(): DomainCheckRunner
    {
        return new DomainCheckRunner(
            new DnsChecker(new NativeDnsResolver()),
            new RdapChecker(new WordPressHttpClient())
        );
    }

    private function currentDomain(): string
    {
        return ApexDomain::fromHost($this->currentHost());
    }

    private function currentHost(): string
    {
        $url = '';

        if (function_exists('home_url')) {
            $url = (string) home_url('/');
        }

        $host = $url !== '' ? parse_url($url, PHP_URL_HOST) : null;
        if (is_string($host) && $host !== '') {
            return $host;
        }

        if (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            return $_SERVER['HTTP_HOST'];
        }

        return 'unknown-domain.invalid';
    }

    /** @return array<string,string>|null */
    private function dashboardResult(): ?array
    {
        $id = $this->actions()->ensureAutoDetectedDomain();
        $record = $this->repository()->find($id);
        if (! $record instanceof DomainRecord || $record->lastCheckedAt() === '') {
            return null;
        }

        $status = ($record->dnsStatus() === 'ok' && $record->rdapStatus() === 'ok') ? 'ok' : 'degraded';
        $message = trim('DNS: ' . $record->dnsMessage() . ' RDAP: ' . $record->rdapMessage());

        return [
            'status' => $status,
            'message' => $message,
            'checked_at' => $record->lastCheckedAt(),
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

    /** @param mixed $value */
    private function unslash($value)
    {
        return function_exists('wp_unslash') ? wp_unslash($value) : $value;
    }

    private function redirectToSettings(string $query): void
    {
        $url = function_exists('admin_url') ? (string) admin_url('options-general.php?page=domain-monitor&' . $query) : '';
        $this->redirect($url);
    }

    private function redirect(string $url): void
    {
        if ($url !== '' && function_exists('wp_safe_redirect')) {
            wp_safe_redirect($url);
            exit;
        }
    }

    private function addQueryArg(string $key, string $value, string $url): string
    {
        if (function_exists('add_query_arg')) {
            return (string) add_query_arg($key, $value, $url);
        }

        $separator = strpos($url, '?') === false ? '?' : '&';
        return $url . $separator . rawurlencode($key) . '=' . rawurlencode($value);
    }

    private function isAdminRequest(): bool
    {
        return function_exists('is_admin') && is_admin();
    }
}
