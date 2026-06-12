<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ExpirationDate;
use DomainMonitor\Settings\PluginSettings;
use DomainMonitor\Storage\DomainRecord;

final class SettingsPage
{
    /** @var list<DomainRecord> */
    private array $domains;
    private string $actionUrl;
    private string $manualCheckNonce;
    private string $addDomainNonce;
    private string $saveSettingsNonce;
    private string $resolveAlertNonce;
    private PluginSettings $settings;
    /** @var list<array<string,mixed>> */
    private array $openAlerts;

    /**
     * @param list<DomainRecord> $domains
     * @param string $manualCheckNonce   Nonce for the manual-check form.
     * @param string $addDomainNonce     Nonce for the add-domain form.
     * @param string $saveSettingsNonce  Nonce for the save-settings form.
     * @param string $resolveAlertNonce  Nonce for the resolve-alert form.
     * @param list<array<string,mixed>> $openAlerts All open alerts across all domains.
     */
    public function __construct(
        array $domains,
        string $actionUrl,
        string $manualCheckNonce,
        string $addDomainNonce = '',
        string $saveSettingsNonce = '',
        string $resolveAlertNonce = '',
        ?PluginSettings $settings = null,
        array $openAlerts = []
    ) {
        $this->domains           = $domains;
        $this->actionUrl         = $actionUrl;
        $this->manualCheckNonce  = $manualCheckNonce;
        // Back-compat: if no add-domain nonce supplied fall back to the same value
        // (preserves existing tests that only pass three args).
        $this->addDomainNonce    = $addDomainNonce !== '' ? $addDomainNonce : $manualCheckNonce;
        $this->saveSettingsNonce = $saveSettingsNonce;
        $this->resolveAlertNonce = $resolveAlertNonce;
        $this->settings          = $settings ?? new PluginSettings();
        $this->openAlerts        = $openAlerts;
    }

    public function register(): void
    {
        if (! function_exists('add_options_page')) {
            return;
        }

        add_options_page(
            $this->translate('Domain Monitor'),
            $this->translate('Domain Monitor'),
            'manage_options',
            'domain-monitor',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        echo $this->renderHtml();
    }

    public function renderHtml(): string
    {
        $rows          = $this->domainRowsHtml();
        $addForm       = $this->addDomainFormHtml();
        $notifSection  = $this->notificationsFormHtml();
        $alertsSection = $this->openAlertsHtml();
        $heading       = $this->escapeHtml($this->translate('Domain Monitor'));
        $subMonitored  = $this->escapeHtml($this->translate('Monitored domains'));
        $subAdd        = $this->escapeHtml($this->translate('Add another domain'));
        $subNotif      = $this->escapeHtml($this->translate('Notifications'));
        $subAlerts     = $this->escapeHtml($this->translate('Open alerts'));

        return <<<HTML
<div class="wrap domain-monitor-settings">
    <h1>{$heading}</h1>
    <h2>{$subMonitored}</h2>
    {$rows}
    <h2>{$subAdd}</h2>
    {$addForm}
    <h2>{$subNotif}</h2>
    {$notifSection}
    <h2>{$subAlerts}</h2>
    {$alertsSection}
</div>
HTML;
    }

    private function domainRowsHtml(): string
    {
        if ($this->domains === []) {
            $message = $this->translate(
                'No public domain detected. This site appears to be a local or development environment. '
                . 'Add a domain below, or define DOMAIN_MONITOR_PRIMARY_DOMAIN in wp-config.php.'
            );
            return '<p class="domain-monitor-dev-notice">' . $this->escapeHtml($message) . '</p>';
        }

        $html = '<div class="domain-monitor-domains">';
        foreach ($this->domains as $domain) {
            $html .= $this->domainRowHtml($domain);
        }
        $html .= '</div>';

        return $html;
    }

    private function domainRowHtml(DomainRecord $record): string
    {
        $domain = $this->escapeHtml($record->domain());
        $source = $record->source() === 'auto'
            ? $this->translate('Auto-detected')
            : $this->translate('Manual');
        $source = $this->escapeHtml($source);
        // Status codes (ok/warn/fail) are machine values; only the label context is translated.
        $dns  = $this->escapeHtml(strtoupper($record->dnsStatus()));
        $rdap = $this->escapeHtml(strtoupper($record->rdapStatus()));
        $dnsMessage  = $record->dnsMessage() !== '' ? '<p>' . $this->escapeHtml($record->dnsMessage()) . '</p>' : '';
        $expiresLabel = $this->escapeHtml(ExpirationDate::label($record->rdapExpiresAt()));
        $domainExpires = $this->escapeHtml($this->translate('Domain expires:'));
        $expiration  = '<p>' . $domainExpires . ' ' . $expiresLabel . '</p>';
        $rdapMessage = $record->rdapMessage() !== '' ? '<p>' . $this->escapeHtml($record->rdapMessage()) . '</p>' : '';

        $transferLocked = $record->rdapTransferLocked();
        if ($transferLocked === null) {
            $lockLabel = $this->translate('unknown');
        } elseif ($transferLocked) {
            $lockLabel = $this->translate('Locked');
        } else {
            $lockLabel = $this->translate('UNLOCKED');
        }
        $lockLabel        = $this->escapeHtml($lockLabel);
        $transferLockText = $this->escapeHtml($this->translate('Transfer lock:'));
        $transferLockHtml = '<p>' . $transferLockText . ' <strong>' . $lockLabel . '</strong></p>';

        $sslHtml = '';
        if ($record->sslStatus() !== '') {
            $sslStatus = $this->escapeHtml(strtoupper($record->sslStatus()));
            $sslLabel  = $this->escapeHtml($this->translate('SSL:'));
            $sslHtml   = '<p><strong>' . $sslLabel . ' ' . $sslStatus . '</strong></p>';
            if ($record->sslExpiresAt() !== '') {
                $sslExpiresLabel = $this->escapeHtml($this->translate('SSL expires:'));
                $sslExpiresAt    = $this->escapeHtml(ExpirationDate::label($record->sslExpiresAt()));
                $sslHtml .= '<p>' . $sslExpiresLabel . ' ' . $sslExpiresAt . '</p>';
            }
        }

        $lastCheckedText = $this->escapeHtml($this->translate('Last checked:'));
        $checkedAt = $record->lastCheckedAt() !== ''
            ? '<p>' . $lastCheckedText . ' ' . $this->escapeHtml($record->lastCheckedAt()) . '</p>'
            : '';
        $form = $this->manualCheckFormHtml($record->id());

        $sourceLabel = $this->escapeHtml($this->translate('Source:'));
        $dnsLabel    = $this->escapeHtml($this->translate('DNS:'));
        $rdapLabel   = $this->escapeHtml($this->translate('RDAP:'));

        return <<<HTML
<section class="domain-monitor-domain">
    <h3>{$domain}</h3>
    <p>{$sourceLabel} {$source}</p>
    <p><strong>{$dnsLabel} {$dns}</strong></p>
    {$dnsMessage}
    <p><strong>{$rdapLabel} {$rdap}</strong></p>
    {$expiration}
    {$rdapMessage}
    {$transferLockHtml}
    {$sslHtml}
    {$checkedAt}
    {$form}
</section>
HTML;
    }

    private function addDomainFormHtml(): string
    {
        $actionUrl   = $this->escapeAttribute($this->actionUrl);
        $nonce       = $this->escapeAttribute($this->addDomainNonce);
        $labelText   = $this->escapeHtml($this->translate('Domain'));
        $placeholder = $this->escapeAttribute($this->translate('example.com'));
        $buttonText  = $this->escapeHtml($this->translate('Add domain'));

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_add_domain" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <p>
        <label for="domain-monitor-domain">{$labelText}</label>
        <input id="domain-monitor-domain" type="text" name="domain_monitor_domain" class="regular-text" placeholder="{$placeholder}" />
        <button type="submit" class="button button-primary">{$buttonText}</button>
    </p>
</form>
HTML;
    }

    private function manualCheckFormHtml(int $domainId): string
    {
        $actionUrl  = $this->escapeAttribute($this->actionUrl);
        $nonce      = $this->escapeAttribute($this->manualCheckNonce);
        $id         = $this->escapeAttribute((string) $domainId);
        $buttonText = $this->escapeHtml($this->translate('Run check'));

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_manual_check" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <input type="hidden" name="domain_monitor_domain_id" value="{$id}" />
    <button type="submit" class="button">{$buttonText}</button>
</form>
HTML;
    }

    private function notificationsFormHtml(): string
    {
        $actionUrl   = $this->escapeAttribute($this->actionUrl);
        $nonce       = $this->escapeAttribute($this->saveSettingsNonce);

        $notifyStatusChange        = $this->settings->notifyStatusChange()        ? 'checked' : '';
        $notifyNsChanged           = $this->settings->notifyNsChanged()           ? 'checked' : '';
        $notifyAChanged            = $this->settings->notifyAChanged()            ? 'checked' : '';
        $notifyMxChanged           = $this->settings->notifyMxChanged()           ? 'checked' : '';
        $notifyTransferLockRemoved = $this->settings->notifyTransferLockRemoved() ? 'checked' : '';
        $notificationEmail         = $this->escapeAttribute($this->settings->notificationEmail());

        $labelStatusChange        = $this->escapeHtml($this->translate('Notify on status change'));
        $labelNsChanged           = $this->escapeHtml($this->translate('Notify on nameserver change'));
        $labelAChanged            = $this->escapeHtml($this->translate('Notify on A record change'));
        $labelMxChanged           = $this->escapeHtml($this->translate('Notify on MX record change'));
        $labelTransferLockRemoved = $this->escapeHtml($this->translate('Notify on transfer lock removal'));
        $labelEmail               = $this->escapeHtml($this->translate('Notification email'));
        $emailPlaceholder         = $this->escapeAttribute($this->translate('Leave blank to use admin email'));
        $buttonText               = $this->escapeHtml($this->translate('Save notification settings'));

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_save_settings" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <table class="form-table">
        <tr>
            <th scope="row">{$labelStatusChange}</th>
            <td><input type="checkbox" name="notify_status_change" value="1" {$notifyStatusChange} /></td>
        </tr>
        <tr>
            <th scope="row">{$labelNsChanged}</th>
            <td><input type="checkbox" name="notify_ns_changed" value="1" {$notifyNsChanged} /></td>
        </tr>
        <tr>
            <th scope="row">{$labelAChanged}</th>
            <td><input type="checkbox" name="notify_a_changed" value="1" {$notifyAChanged} /></td>
        </tr>
        <tr>
            <th scope="row">{$labelMxChanged}</th>
            <td><input type="checkbox" name="notify_mx_changed" value="1" {$notifyMxChanged} /></td>
        </tr>
        <tr>
            <th scope="row">{$labelTransferLockRemoved}</th>
            <td><input type="checkbox" name="notify_transfer_lock_removed" value="1" {$notifyTransferLockRemoved} /></td>
        </tr>
        <tr>
            <th scope="row"><label for="domain-monitor-notif-email">{$labelEmail}</label></th>
            <td><input id="domain-monitor-notif-email" type="email" name="notification_email" class="regular-text" value="{$notificationEmail}" placeholder="{$emailPlaceholder}" /></td>
        </tr>
    </table>
    <p><button type="submit" class="button button-primary">{$buttonText}</button></p>
</form>
HTML;
    }

    private function openAlertsHtml(): string
    {
        if ($this->openAlerts === []) {
            $message = $this->escapeHtml($this->translate('No open alerts.'));
            return '<p>' . $message . '</p>';
        }

        $actionUrl = $this->escapeAttribute($this->actionUrl);
        $nonce     = $this->escapeAttribute($this->resolveAlertNonce);

        $typeLabels = [
            'ns_changed'            => $this->translate('Nameserver changed'),
            'a_changed'             => $this->translate('A record changed'),
            'mx_changed'            => $this->translate('MX record changed'),
            'transfer_lock_removed' => $this->translate('Transfer lock removed'),
        ];

        $resolveText = $this->escapeHtml($this->translate('Resolve'));
        $typeHeader  = $this->escapeHtml($this->translate('Type'));
        $msgHeader   = $this->escapeHtml($this->translate('Message'));
        $dateHeader  = $this->escapeHtml($this->translate('Date'));
        $domainHeader = $this->escapeHtml($this->translate('Domain'));
        $actionHeader = $this->escapeHtml($this->translate('Action'));

        $rows = '';
        foreach ($this->openAlerts as $alert) {
            $alertId   = (int) ($alert['id'] ?? 0);
            $alertIdEsc = $this->escapeAttribute((string) $alertId);
            $rawType   = (string) ($alert['type'] ?? '');
            $typeLabel = isset($typeLabels[$rawType]) ? $this->escapeHtml($typeLabels[$rawType]) : $this->escapeHtml($rawType);
            $message   = $this->escapeHtml((string) ($alert['message'] ?? ''));
            $createdAt = $this->escapeHtml((string) ($alert['created_at'] ?? ''));
            $domain    = $this->escapeHtml((string) ($alert['domain'] ?? ''));

            $rows .= <<<HTML
<tr>
    <td>{$domain}</td>
    <td>{$typeLabel}</td>
    <td>{$message}</td>
    <td>{$createdAt}</td>
    <td>
        <form method="post" action="{$actionUrl}" style="display:inline">
            <input type="hidden" name="action" value="domain_monitor_resolve_alert" />
            <input type="hidden" name="_wpnonce" value="{$nonce}" />
            <input type="hidden" name="domain_monitor_alert_id" value="{$alertIdEsc}" />
            <button type="submit" class="button">{$resolveText}</button>
        </form>
    </td>
</tr>
HTML;
        }

        return <<<HTML
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th>{$domainHeader}</th>
            <th>{$typeHeader}</th>
            <th>{$msgHeader}</th>
            <th>{$dateHeader}</th>
            <th>{$actionHeader}</th>
        </tr>
    </thead>
    <tbody>
        {$rows}
    </tbody>
</table>
HTML;
    }

    private function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function escapeAttribute(string $value): string
    {
        if (function_exists('esc_attr')) {
            return esc_attr($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Translate a string using the plugin text domain.
     * Falls back to the raw string when the WP i18n functions are not available
     * (e.g. in unit tests that run outside WordPress).
     */
    private function translate(string $text): string
    {
        if (function_exists('__')) {
            // @phan-suppress-next-line PhanUndeclaredFunction
            return __($text, 'domain-monitor');
        }

        return $text;
    }
}
