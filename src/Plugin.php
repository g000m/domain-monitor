<?php
declare(strict_types=1);

namespace DomainMonitor;

use DateTimeImmutable;
use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Admin\SettingsPage;
use DomainMonitor\Alerts\AlertResolver;
use DomainMonitor\Checks\DnsChecker;
use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\EmailDnsChecker;
use DomainMonitor\Checks\NativeDnsResolver;
use DomainMonitor\Checks\RdapChecker;
use DomainMonitor\Checks\SslChecker;
use DomainMonitor\Checks\StreamCertificateFetcher;
use DomainMonitor\Checks\WordPressHttpClient;
use DomainMonitor\Diff\SnapshotDiffer;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Notifications\AdminNotifier;
use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Rest\StatusController;
use DomainMonitor\Settings\PluginSettings;
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
    private const NONCE_ACTION_MANUAL_CHECK    = 'domain_monitor_manual_check';
    private const NONCE_ACTION_ADD_DOMAIN      = 'domain_monitor_add_domain_form';
    private const NONCE_ACTION_SAVE_SETTINGS   = 'domain_monitor_save_settings_form';
    private const NONCE_ACTION_RESOLVE_ALERT   = 'domain_monitor_resolve_alert_form';
    private const ADD_DOMAIN_ACTION            = 'domain_monitor_add_domain';
    private const CRON_HOOK                    = 'domain_monitor_daily_check';

    /** @var DomainRepository|null */
    private $injectedRepository = null;
    /** @var CheckRunner|null */
    private $injectedCheckRunner = null;
    /** @var DomainNotifier|null */
    private $injectedNotifier = null;
    /** @var AlertStore|null */
    private $injectedAlertStore = null;
    /** @var PluginSettings|null */
    private $injectedSettings = null;

    /**
     * Optional constructor injection for testability.
     * All parameters default to null; production code uses the private factory methods.
     */
    public function __construct(
        ?DomainRepository $repository = null,
        ?CheckRunner $checkRunner = null,
        ?DomainNotifier $notifier = null,
        ?AlertStore $alertStore = null,
        ?PluginSettings $settings = null
    ) {
        $this->injectedRepository  = $repository;
        $this->injectedCheckRunner = $checkRunner;
        $this->injectedNotifier    = $notifier;
        $this->injectedAlertStore  = $alertStore;
        $this->injectedSettings    = $settings;
    }

    public function register(): void
    {
        // Cron hook must be registered outside the is_admin() gate so it fires
        // on front-end requests where WP-Cron runs.
        add_action(self::CRON_HOOK, [$this, 'handleDailyCheck']);

        // REST API — registered outside is_admin() so it works on non-admin REST requests.
        add_action('rest_api_init', function (): void {
            (new StatusController(
                $this->repository(),
                $this->alertStore(),
                new \DomainMonitor\Domain\StatusCalculator()
            ))->register();
        });

        // Admin-only hooks.
        if (! $this->isAdminRequest()) {
            return;
        }

        add_action('admin_init', function (): void {
            $this->actions()->ensureAutoDetectedDomain();
        });

        add_action('admin_menu', function (): void {
            $allOpenAlerts = $this->allOpenAlertsWithDomain();
            (new SettingsPage(
                $this->repository()->all(),
                $this->adminPostUrl(),
                $this->manualCheckNonce(),
                $this->addDomainNonce(),
                $this->saveSettingsNonce(),
                $this->resolveAlertNonce(),
                $this->pluginSettings(),
                $allOpenAlerts
            ))->register();
        });

        add_action('wp_dashboard_setup', function (): void {
            DashboardWidget::fromDomains(
                $this->allDomainSummaries(),
                $this->adminPostUrl(),
                $this->manualCheckNonce(),
                $this->domainSettingsUrl()
            )->register();
        });

        add_action('admin_notices', [$this, 'handleAdminNotices']);

        add_action('admin_post_' . self::ADD_DOMAIN_ACTION, [$this, 'handleAddDomain']);
        add_action('admin_post_domain_monitor_manual_check', [$this, 'handleManualCheck']);
        add_action('admin_post_domain_monitor_save_settings', [$this, 'handleSaveSettings']);
        add_action('admin_post_domain_monitor_resolve_alert', [$this, 'handleResolveAlert']);
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

    public function handleSaveSettings(): void
    {
        $this->verifyAdminAction(self::NONCE_ACTION_SAVE_SETTINGS);

        $raw = [
            'notify_status_change'        => isset($_POST['notify_status_change']),
            'notify_ns_changed'           => isset($_POST['notify_ns_changed']),
            'notify_a_changed'            => isset($_POST['notify_a_changed']),
            'notify_mx_changed'           => isset($_POST['notify_mx_changed']),
            'notify_transfer_lock_removed' => isset($_POST['notify_transfer_lock_removed']),
            'notification_email'          => '',
            'email_dns_check_enabled'     => isset($_POST['email_dns_check_enabled']),
        ];

        if (isset($_POST['notification_email'])) {
            $emailInput = (string) $this->unslash($_POST['notification_email']);
            if (function_exists('is_email') && function_exists('sanitize_email')) {
                $raw['notification_email'] = is_email($emailInput) ? (string) sanitize_email($emailInput) : '';
            } else {
                $raw['notification_email'] = filter_var($emailInput, FILTER_VALIDATE_EMAIL) !== false ? $emailInput : '';
            }
        }

        if (function_exists('update_option')) {
            update_option(PluginSettings::OPTION_NAME, $raw);
        }

        $this->redirectToSettings('domain_monitor_settings_saved=1');
    }

    public function handleResolveAlert(): void
    {
        $this->verifyAdminAction(self::NONCE_ACTION_RESOLVE_ALERT);

        if (! isset($_POST['domain_monitor_alert_id'])) {
            $this->redirectToSettings('');
            return;
        }

        $alertId = max(1, (int) $this->unslash($_POST['domain_monitor_alert_id']));
        $this->alertStore()->resolveAlert($alertId, $this->nowMysql());

        $this->redirectToSettings('domain_monitor_alert_resolved=1');
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
    // CLI-accessible helpers (used by DomainMonitorCommand)
    // -----------------------------------------------------------------

    /**
     * Run the full check pipeline for a specific domain ID.
     * Returns true on success (record found and check ran), false otherwise.
     */
    public function runCheckForDomainId(int $domainId): bool
    {
        $repository = $this->repository();
        $record     = $repository->find($domainId);

        if (! $record instanceof DomainRecord) {
            return false;
        }

        $this->checkAndRecord($record, $repository);
        return true;
    }

    /**
     * Return the status code for a domain record using the same alert feed as
     * the daily cron and admin notices.
     */
    public function statusCodeForRecord(?DomainRecord $record): string
    {
        if ($record === null) {
            return 'unknown';
        }
        return $record->statusCode($this->alertsForRecord($record));
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

        // Diff snapshots and create new alerts.
        $this->processAlerts($record->id(), $previousSnapshot, $fresh->snapshot(), $previousTransferLocked, $fresh);

        // Auto-resolve alerts whose triggering condition has reverted.
        // Run after processAlerts so newly-created alerts are not immediately resolved.
        (new AlertResolver($this->alertStore()))->resolveReverted(
            $record->id(),
            $fresh->snapshot(),
            $fresh->rdapTransferLocked(),
            $this->nowMysql()
        );

        $newStatus = $fresh->statusCode($this->alertsForRecord($fresh));
        if ($this->isWorseStatus($previousStatus, $newStatus)) {
            // Build open_alert_types context for attribution in shouldNotify.
            $openAlerts      = $this->alertStore()->openAlertsForDomain($record->id());
            $openAlertTypes  = array_values(array_unique(array_column($openAlerts, 'type')));
            $notifyContext   = ['from' => $previousStatus, 'to' => $newStatus, 'open_alert_types' => $openAlertTypes];
            if ($this->shouldNotify('status_change', $fresh, $notifyContext)) {
                $this->notifier()->notifyStatusChange($fresh, $previousStatus, $newStatus);
            }
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
        // Index existing open alert types to avoid creating duplicates.
        // Fetched once here and reused by both the DNS diff block and the
        // email DNS regression block below.
        $existingOpenTypes = array_flip(array_column(
            $this->alertStore()->openAlertsForDomain($domainId),
            'type'
        ));

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
                    if (! isset($existingOpenTypes['ns_changed'])) {
                        $previous = $oldSnapshot['dns']['apex']['ns'] ?? [];
                        $this->alertStore()->createAlert($domainId, 'ns_changed', $diff->message(), ['previous' => $previous]);
                    }
                    // NS change is the hijack signal: always notify.
                    if ($this->shouldNotify('ns_changed', $fresh, ['message' => $diff->message()])) {
                        $this->notifier()->notifyStatusChange($fresh, 'ok', StatusCalculator::STATUS_WARN);
                    }
                } elseif ($recordType === 'a') {
                    if (! isset($existingOpenTypes['a_changed'])) {
                        $previous = $oldSnapshot['dns']['apex']['a'] ?? [];
                        $this->alertStore()->createAlert($domainId, 'a_changed', $diff->message(), ['previous' => $previous]);
                    }
                } elseif ($recordType === 'mx') {
                    if (! isset($existingOpenTypes['mx_changed'])) {
                        $previous = $oldSnapshot['dns']['apex']['mx'] ?? [];
                        $this->alertStore()->createAlert($domainId, 'mx_changed', $diff->message(), ['previous' => $previous]);
                    }
                }
                // Other record types (aaaa, cname) get no dedicated alert in v1.
            }
        }

        // Email DNS regression alerts: SPF disappeared, DMARC disappeared.
        // We only alert on clear regressions (had it, now missing). We do NOT
        // alert on "never had DMARC" — absence on a fresh baseline is not a regression.
        $oldEmailDns = $oldSnapshot['email_dns'] ?? null;
        $newEmailDns = $newSnapshot['email_dns'] ?? null;
        if (is_array($oldEmailDns) && is_array($newEmailDns)) {
            $oldSpf   = $oldEmailDns['spf_state'] ?? '';
            $newSpf   = $newEmailDns['spf_state'] ?? '';
            $oldDmarc = $oldEmailDns['dmarc_state'] ?? '';
            $newDmarc = $newEmailDns['dmarc_state'] ?? '';

            if ($oldSpf === 'present' && $newSpf === 'missing') {
                if (! isset($existingOpenTypes['spf_missing'])) {
                    $this->alertStore()->createAlert($domainId, 'spf_missing', 'SPF record disappeared.');
                }
            }

            if ($oldDmarc === 'present' && $newDmarc === 'missing') {
                if (! isset($existingOpenTypes['dmarc_missing'])) {
                    $this->alertStore()->createAlert($domainId, 'dmarc_missing', 'DMARC record disappeared.');
                }
            }
        }

        // Transfer lock removal.
        $newTransferLocked = $fresh->rdapTransferLocked();
        if ($previousTransferLocked === true && $newTransferLocked === false) {
            $this->alertStore()->createAlert($domainId, 'transfer_lock_removed', 'Domain transfer lock was removed.');
            if ($this->shouldNotify('transfer_lock_removed', $fresh, ['message' => 'Domain transfer lock was removed.'])) {
                $this->notifier()->notifyStatusChange($fresh, 'ok', StatusCalculator::STATUS_WARN);
            }
        }
    }

    /**
     * Returns true when a notification should be dispatched.
     *
     * Consults PluginSettings first (settings override defaults to true for all
     * types). The domain_monitor_should_notify filter is applied last so external
     * code can always override the result.
     *
     * $type values:
     *   'status_change'        -- domain status worsened
     *   'ns_changed'           -- nameserver change detected
     *   'transfer_lock_removed' -- domain transfer lock was removed
     *
     * For 'status_change', the context may carry an 'open_alert_types' key
     * (list<string>) that allows attributing the worsening to specific alert types
     * (a_changed / mx_changed). When all open alerts are exclusively a_changed or
     * mx_changed, the per-type setting is checked instead of notify_status_change.
     * If multiple types are present, the notification fires if ANY of the applicable
     * settings is enabled.
     *
     * @param array<string,mixed> $context Additional context (e.g. from/to status or diff message).
     */
    protected function shouldNotify(string $type, DomainRecord $record, array $context): bool
    {
        $settings = $this->pluginSettings();
        $enabled  = $this->isEnabledBySettings($type, $context, $settings);

        if (! $enabled) {
            return false;
        }

        if (function_exists('apply_filters')) {
            /** @var mixed $result */
            $result = apply_filters('domain_monitor_should_notify', true, $type, $record, $context);
            return (bool) $result;
        }

        return true;
    }

    /**
     * Check whether the notification is allowed by the current PluginSettings.
     *
     * @param array<string,mixed> $context
     */
    private function isEnabledBySettings(string $type, array $context, PluginSettings $settings): bool
    {
        switch ($type) {
            case 'ns_changed':
                return $settings->notifyNsChanged();

            case 'transfer_lock_removed':
                return $settings->notifyTransferLockRemoved();

            case 'status_change':
                // If the context carries open_alert_types, use them for attribution.
                // When ALL open alerts are a_changed/mx_changed types (no ns_changed or
                // other types), check per-record-type settings instead of notify_status_change.
                $openTypes = $context['open_alert_types'] ?? null;
                if (is_array($openTypes) && count($openTypes) > 0) {
                    $attributable = ['a_changed', 'mx_changed'];
                    $unique = array_unique($openTypes);
                    $allAttributable = count(array_diff($unique, $attributable)) === 0;

                    if ($allAttributable) {
                        // Gate on per-type settings; pass if ANY enabled type is present.
                        $hasA  = in_array('a_changed', $unique, true);
                        $hasMx = in_array('mx_changed', $unique, true);
                        if ($hasA && $settings->notifyAChanged()) {
                            return true;
                        }
                        if ($hasMx && $settings->notifyMxChanged()) {
                            return true;
                        }
                        return false;
                    }
                }
                return $settings->notifyStatusChange();

            default:
                return true;
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

        $emailDnsChecker = $this->pluginSettings()->emailDnsCheckEnabled()
            ? new EmailDnsChecker(new NativeDnsResolver())
            : null;

        return new DomainCheckRunner(
            new DnsChecker(new NativeDnsResolver()),
            new RdapChecker(new WordPressHttpClient()),
            new SslChecker(new StreamCertificateFetcher()),
            null,
            $emailDnsChecker
        );
    }

    private function notifier(): DomainNotifier
    {
        if ($this->injectedNotifier !== null) {
            return $this->injectedNotifier;
        }

        return new AdminNotifier($this->pluginSettings());
    }

    private function pluginSettings(): PluginSettings
    {
        if ($this->injectedSettings !== null) {
            return $this->injectedSettings;
        }

        return PluginSettings::fromWordPress();
    }

    /**
     * Fetch open and recently-resolved alerts for a domain record, normalized to
     * the shape StatusCalculator expects.
     *
     * Open alerts get is_active=true. Recently-resolved alerts (within 3 days) get
     * is_active=false with their resolved_at preserved so StatusCalculator's 3-day
     * amber persistence path fires correctly.
     *
     * @return list<array<string,mixed>>
     */
    private function alertsForRecord(DomainRecord $record): array
    {
        $open     = $this->alertStore()->openAlertsForDomain($record->id());
        $recent   = $this->alertStore()->recentlyResolvedAlertsForDomain($record->id(), 3, $this->nowMysql());
        $combined = array_merge($open, $recent);

        $normalized = [];
        foreach ($combined as $row) {
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
        // Same resolution chain as auto-detection (constant and filter overrides
        // included), so the widget never disagrees with what is actually monitored.
        // Empty string makes DashboardWidget show the dev-environment notice.
        return $this->actions()->resolvePrimaryDomain();
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

    /**
     * Build the list of domain summaries for the multi-domain dashboard widget.
     * Each entry maps the minimal data the widget needs: domain, status, message,
     * checked_at, expires_at.
     *
     * @return list<array<string,string>>
     */
    private function allDomainSummaries(): array
    {
        // Ensure the auto-detected domain is seeded before we enumerate.
        $this->actions()->ensureAutoDetectedDomain();

        $records    = $this->repository()->all();
        $summaries  = [];

        foreach ($records as $record) {
            if (! $record->isActive()) {
                continue;
            }

            if ($record->lastCheckedAt() === '') {
                $summaries[] = [
                    'domain'           => $record->domain(),
                    'status'           => '',
                    'message'          => '',
                    'checked_at'       => '',
                    'expires_at'       => '',
                    'rdap_expires_at'  => '',
                    'ssl_expires_at'   => '',
                    'open_alert_types' => [],
                ];
                continue;
            }

            $calculator  = new \DomainMonitor\Domain\StatusCalculator();
            $domainStatus = $calculator->calculate(
                $record->snapshot(),
                $this->alertsForRecord($record),
                new \DateTimeImmutable()
            );

            $openAlerts      = $this->alertStore()->openAlertsForDomain($record->id());
            $openAlertTypes  = array_values(array_unique(array_column($openAlerts, 'type')));

            $summaries[] = [
                'domain'           => $record->domain(),
                'status'           => $domainStatus->code(),
                'message'          => $domainStatus->message(),
                'checked_at'       => $record->lastCheckedAt(),
                'expires_at'       => $record->rdapExpiresAt(),
                'rdap_expires_at'  => $record->rdapExpiresAt(),
                'ssl_expires_at'   => $record->sslExpiresAt(),
                'open_alert_types' => $openAlertTypes,
            ];
        }

        return $summaries;
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

    private function domainSettingsUrl(): string
    {
        if (function_exists('admin_url')) {
            return (string) admin_url('options-general.php?page=domain-monitor');
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

    private function saveSettingsNonce(): string
    {
        if (function_exists('wp_create_nonce')) {
            return (string) wp_create_nonce(self::NONCE_ACTION_SAVE_SETTINGS);
        }

        return '';
    }

    private function resolveAlertNonce(): string
    {
        if (function_exists('wp_create_nonce')) {
            return (string) wp_create_nonce(self::NONCE_ACTION_RESOLVE_ALERT);
        }

        return '';
    }

    /**
     * Collect all open alerts across all domains, enriched with domain name.
     *
     * @return list<array<string,mixed>>
     */
    private function allOpenAlertsWithDomain(): array
    {
        $records = $this->repository()->all();
        $all     = [];
        foreach ($records as $record) {
            $alerts = $this->alertStore()->openAlertsForDomain($record->id());
            foreach ($alerts as $alert) {
                $alert['domain'] = $record->domain();
                $all[]           = $alert;
            }
        }
        return $all;
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
