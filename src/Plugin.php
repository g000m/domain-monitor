<?php
declare(strict_types=1);

namespace DomainMonitor;

use DateTimeImmutable;
use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Admin\SettingsPage;
use DomainMonitor\Checks\DnsChecker;
use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\NativeDnsResolver;
use DomainMonitor\Checks\RdapChecker;
use DomainMonitor\Checks\SslChecker;
use DomainMonitor\Checks\StreamCertificateFetcher;
use DomainMonitor\Checks\WordPressHttpClient;
use DomainMonitor\Diff\SnapshotDiffer;
use DomainMonitor\Domain\ApexDomain;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Notifications\AdminNotifier;
use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Storage\AlertStore;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use DomainMonitor\Storage\DomainTable;
use DomainMonitor\Storage\WpdbAlertStore;
use DomainMonitor\Storage\WpdbDomainStore;
use InvalidArgumentException;

final class Plugin
{
    private const NONCE_ACTION_MANUAL_CHECK = 'domain_monitor_manual_check';
    private const NONCE_ACTION_ADD_DOMAIN   = 'domain_monitor_add_domain_form';
    private const ADD_DOMAIN_ACTION         = 'domain_monitor_add_domain';
    private const CRON_HOOK                 = 'domain_monitor_daily_check';

    /** @var DomainRepository|null */
    private $injectedRepository = null;
    /** @var CheckRunner|null */
    private $injectedCheckRunner = null;
    /** @var DomainNotifier|null */
    private $injectedNotifier = null;
    /** @var AlertStore|null */
    private $injectedAlertStore = null;

    /**
     * Optional constructor injection for testability.
     * All parameters default to null; production code uses the private factory methods.
     */
    public function __construct(
        ?DomainRepository $repository = null,
        ?CheckRunner $checkRunner = null,
        ?DomainNotifier $notifier = null,
        ?AlertStore $alertStore = null
    ) {
        $this->injectedRepository  = $repository;
        $this->injectedCheckRunner = $checkRunner;
        $this->injectedNotifier    = $notifier;
        $this->injectedAlertStore  = $alertStore;
    }

    public function register(): void
    {
        // Cron hook must be registered outside the is_admin() gate so it fires
        // on front-end requests where WP-Cron runs.
        add_action(self::CRON_HOOK, [$this, 'handleDailyCheck']);

        // Admin-only hooks.
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
                $this->manualCheckNonce(),
                $this->addDomainNonce()
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

        add_action('admin_notices', [$this, 'handleAdminNotices']);

        add_action('admin_post_' . self::ADD_DOMAIN_ACTION, [$this, 'handleAddDomain']);
        add_action('admin_post_domain_monitor_manual_check', [$this, 'handleManualCheck']);
    }

    // -----------------------------------------------------------------
    // Public action handlers
    // -----------------------------------------------------------------

    public function handleAddDomain(): void
    {
        $this->verifyAdminAction(self::NONCE_ACTION_ADD_DOMAIN);

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
        $this->verifyAdminAction(self::NONCE_ACTION_MANUAL_CHECK);

        $domainId = null;
        if (isset($_POST['domain_monitor_domain_id'])) {
            $domainId = max(1, (int) $this->unslash($_POST['domain_monitor_domain_id']));
        }

        $this->runCheckForDomain($domainId);

        $referrer = function_exists('wp_get_referer') ? (string) wp_get_referer() : '';
        if ($referrer !== '') {
            $this->redirect($this->addQueryArg('domain_monitor_checked', '1', $referrer));
        }

        $this->redirect(function_exists('admin_url') ? (string) admin_url('index.php?domain_monitor_checked=1') : '');
    }

    public function handleDailyCheck(): void
    {
        $repository = $this->repository();
        $records    = $repository->all();

        foreach ($records as $record) {
            $this->checkAndRecord($record, $repository);
        }
    }

    public function handleAdminNotices(): void
    {
        $records = $this->repository()->all();
        foreach ($records as $record) {
            $code = $record->statusCode($this->alertsForRecord($record));
            if ($code === StatusCalculator::STATUS_WARN || $code === StatusCalculator::STATUS_FAIL) {
                $settingsUrl = function_exists('admin_url')
                    ? esc_url(admin_url('options-general.php?page=domain-monitor'))
                    : '#';
                echo '<div class="notice notice-warning"><p>';
                echo 'Domain Monitor: one or more domains need attention. ';
                echo '<a href="' . $settingsUrl . '">View domains</a>.';
                echo '</p></div>';
                return;
            }
        }
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    private function runCheckForDomain(?int $domainId): void
    {
        $id         = $domainId ?? $this->actions()->ensureAutoDetectedDomain();
        $repository = $this->repository();
        $record     = $repository->find($id);

        if (! $record instanceof DomainRecord) {
            return;
        }

        $this->checkAndRecord($record, $repository);
    }

    /**
     * Run a check for a single domain record, persist the result, and notify
     * if the status worsened. Both the daily batch and manual-check paths
     * delegate here to avoid duplicating the try/catch and notification logic.
     */
    private function checkAndRecord(DomainRecord $record, DomainRepository $repository): void
    {
        $previousStatus   = $record->statusCode($this->alertsForRecord($record));
        $previousSnapshot = $record->snapshot();

        // Capture transfer lock state before the check for change detection.
        $previousTransferLocked = $record->rdapTransferLocked();

        try {
            $result = $this->checkRunner()->check($record->domain());
        } catch (\Throwable $e) {
            $result = [
                'dns_status'      => 'degraded',
                'dns_message'     => $e->getMessage(),
                'rdap_status'     => 'degraded',
                'rdap_message'    => $e->getMessage(),
                'rdap_registrar'  => null,
                'rdap_expires_at' => null,
                'last_checked_at' => $this->nowMysql(),
            ];
        }

        $repository->saveCheckResult($record->id(), $result);

        $fresh = $repository->find($record->id());
        if (! $fresh instanceof DomainRecord) {
            return;
        }

        // Diff snapshots and create alerts.
        $this->processAlerts($record->id(), $previousSnapshot, $fresh->snapshot(), $previousTransferLocked, $fresh);

        $newStatus = $fresh->statusCode($this->alertsForRecord($fresh));
        if ($this->isWorseStatus($previousStatus, $newStatus)) {
            $this->notifier()->notifyStatusChange($fresh, $previousStatus, $newStatus);
        }

        if (function_exists('do_action')) {
            do_action('domain_monitor_check_complete', $fresh, $result);
        }
    }

    /**
     * Diff the previous and new snapshots, create alert rows, and send
     * notifications for NS changes and transfer lock removal.
     *
     * @param array<string,mixed> $oldSnapshot
     * @param array<string,mixed> $newSnapshot
     * @param bool|null $previousTransferLocked
     */
    private function processAlerts(
        int $domainId,
        array $oldSnapshot,
        array $newSnapshot,
        $previousTransferLocked,
        DomainRecord $fresh
    ): void {
        // DNS snapshot diff.
        // Skip if there is no previous snapshot at all, or if the previous snapshot
        // pre-dates the dns.apex structure (e.g. recorded by older plugin code). In
        // that case the first rich snapshot becomes the new reference baseline and no
        // spurious "added" alerts are emitted.
        $oldApex = $oldSnapshot['dns']['apex'] ?? null;
        $hasRichOldSnapshot = $oldSnapshot !== [] && is_array($oldApex) && $oldApex !== [];
        if ($hasRichOldSnapshot) {
            $diffs = (new SnapshotDiffer())->diff($oldSnapshot, $newSnapshot);
            foreach ($diffs as $diff) {
                $recordType = $diff->recordType();

                if ($recordType === 'ns') {
                    $this->alertStore()->createAlert($domainId, 'ns_changed', $diff->message());
                    // NS change is the hijack signal: always notify.
                    $this->notifier()->notifyStatusChange($fresh, 'ok', StatusCalculator::STATUS_WARN);
                } elseif ($recordType === 'a') {
                    $this->alertStore()->createAlert($domainId, 'a_changed', $diff->message());
                } elseif ($recordType === 'mx') {
                    $this->alertStore()->createAlert($domainId, 'mx_changed', $diff->message());
                }
                // Other record types (aaaa, cname) get no dedicated alert in v1.
            }
        }

        // Transfer lock removal.
        $newTransferLocked = $fresh->rdapTransferLocked();
        if ($previousTransferLocked === true && $newTransferLocked === false) {
            $this->alertStore()->createAlert($domainId, 'transfer_lock_removed', 'Domain transfer lock was removed.');
            $this->notifier()->notifyStatusChange($fresh, 'ok', StatusCalculator::STATUS_WARN);
        }
    }

    private function verifyAdminAction(string $nonceAction): void
    {
        if (function_exists('check_admin_referer')) {
            check_admin_referer($nonceAction);
        }

        if (function_exists('current_user_can') && ! current_user_can('manage_options')) {
            if (function_exists('wp_die')) {
                $message = function_exists('__')
                    ? __('Sorry, you are not allowed to manage Domain Monitor.', 'domain-monitor')
                    : 'Sorry, you are not allowed to manage Domain Monitor.';
                wp_die($message);
            }

            // In test context wp_die may not be defined; throw so callers cannot proceed.
            throw new \RuntimeException('Insufficient permissions for Domain Monitor action.');
        }
    }

    private function actions(): DomainAdminActions
    {
        return new DomainAdminActions($this->repository(), $this->checkRunner(), $this->currentHost());
    }

    private function repository(): DomainRepository
    {
        if ($this->injectedRepository !== null) {
            return $this->injectedRepository;
        }

        global $wpdb;

        return new DomainRepository(new WpdbDomainStore($wpdb, DomainTable::tableName((string) $wpdb->prefix)));
    }

    private function checkRunner(): CheckRunner
    {
        if ($this->injectedCheckRunner !== null) {
            return $this->injectedCheckRunner;
        }

        return new DomainCheckRunner(
            new DnsChecker(new NativeDnsResolver()),
            new RdapChecker(new WordPressHttpClient()),
            new SslChecker(new StreamCertificateFetcher())
        );
    }

    private function notifier(): DomainNotifier
    {
        if ($this->injectedNotifier !== null) {
            return $this->injectedNotifier;
        }

        return new AdminNotifier();
    }

    /**
     * Fetch open alerts for a domain record and normalize them to the shape
     * StatusCalculator expects: each row gains `is_active => true` (all rows
     * returned by openAlertsForDomain are unresolved) and `severity => 'warn'`
     * when no severity column is stored.
     *
     * @return list<array<string,mixed>>
     */
    private function alertsForRecord(DomainRecord $record): array
    {
        $raw = $this->alertStore()->openAlertsForDomain($record->id());
        $normalized = [];
        foreach ($raw as $row) {
            $row['is_active'] = ($row['resolved_at'] ?? null) === null;
            $row['severity']  = $row['severity'] ?? StatusCalculator::STATUS_WARN;
            $normalized[]     = $row;
        }
        return $normalized;
    }

    private function alertStore(): AlertStore
    {
        if ($this->injectedAlertStore !== null) {
            return $this->injectedAlertStore;
        }

        global $wpdb;

        return new WpdbAlertStore($wpdb);
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

        $calculator = new StatusCalculator();
        $domainStatus = $calculator->calculate(
            $record->snapshot(),
            $this->alertsForRecord($record),
            new DateTimeImmutable()
        );

        return [
            'status'     => $domainStatus->code(),
            'message'    => $domainStatus->message(),
            'checked_at' => $record->lastCheckedAt(),
            'expires_at' => $record->rdapExpiresAt(),
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
            return (string) wp_create_nonce(self::NONCE_ACTION_MANUAL_CHECK);
        }

        return '';
    }

    private function addDomainNonce(): string
    {
        if (function_exists('wp_create_nonce')) {
            return (string) wp_create_nonce(self::NONCE_ACTION_ADD_DOMAIN);
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

    /**
     * Returns true when $newStatus is strictly worse than $previousStatus.
     * Severity order: ok < warn < fail.
     */
    private function isWorseStatus(string $previous, string $new): bool
    {
        $order = [
            StatusCalculator::STATUS_OK   => 0,
            StatusCalculator::STATUS_WARN => 1,
            StatusCalculator::STATUS_FAIL => 2,
        ];

        $prev = $order[$previous] ?? 0;
        $next = $order[$new] ?? 0;

        return $next > $prev;
    }

    private function nowMysql(): string
    {
        if (function_exists('current_time')) {
            return (string) current_time('mysql');
        }

        return gmdate('Y-m-d H:i:s');
    }
}
