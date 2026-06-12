<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ExpirationDate;

final class DashboardWidget
{
    private string $domain;

    /** @var array<string,string>|null */
    private ?array $lastResult;

    private string $actionUrl;
    private string $nonce;

    /** @param array<string,string>|null $lastResult */
    public function __construct(string $domain, ?array $lastResult = null, string $actionUrl = '', string $nonce = '')
    {
        $this->domain = $domain;
        $this->lastResult = $lastResult;
        $this->actionUrl = $actionUrl;
        $this->nonce = $nonce;
    }

    public function register(): void
    {
        if (! function_exists('wp_add_dashboard_widget')) {
            return;
        }

        wp_add_dashboard_widget(
            'domain_monitor_dashboard_widget',
            $this->translate('Domain Monitor'),
            [$this, 'render']
        );
    }

    public function render(): void
    {
        echo $this->renderHtml();
    }

    public function renderHtml(): string
    {
        $domain     = $this->escapeHtml($this->domain);
        $status     = $this->statusLabel();
        $message    = $this->messageHtml();
        $expiration = $this->expirationHtml();
        $checkedAt  = $this->checkedAtHtml();
        $form       = $this->manualCheckFormHtml();

        $monitoredLabel = $this->escapeHtml($this->translate('Monitored domain:'));
        $statusLabel    = $this->escapeHtml($this->translate('Status:'));

        return <<<HTML
<div class="domain-monitor-widget">
    <p><strong>{$monitoredLabel}</strong> {$domain}</p>
    <p><strong>{$statusLabel} {$status}</strong></p>
    {$expiration}
    {$message}
    {$checkedAt}
    {$form}
</div>
HTML;
    }

    private function statusLabel(): string
    {
        if ($this->lastResult === null || ($this->lastResult['status'] ?? '') === '') {
            return $this->escapeHtml($this->translate('Not checked yet'));
        }

        // Status codes (ok/warn/fail) are machine values; display them uppercased but not translated.
        return strtoupper($this->escapeHtml((string) $this->lastResult['status']));
    }

    private function messageHtml(): string
    {
        $message = $this->lastResult['message']
            ?? $this->translate('Domain Monitor is active. Run a manual check to capture the first proof-of-concept result.');

        return '<p>' . $this->escapeHtml((string) $message) . '</p>';
    }

    private function checkedAtHtml(): string
    {
        if ($this->lastResult === null || ($this->lastResult['checked_at'] ?? '') === '') {
            return '';
        }

        $label = $this->escapeHtml($this->translate('Last checked:'));
        return '<p>' . $label . ' ' . $this->escapeHtml((string) $this->lastResult['checked_at']) . '</p>';
    }

    private function expirationHtml(): string
    {
        $expiresAt = $this->lastResult['expires_at'] ?? '';
        $label     = $this->escapeHtml($this->translate('Domain expires:'));

        return '<p>' . $label . ' ' . $this->escapeHtml(ExpirationDate::label((string) $expiresAt)) . '</p>';
    }

    private function manualCheckFormHtml(): string
    {
        $actionUrl   = $this->escapeAttribute($this->actionUrl);
        $nonce       = $this->escapeAttribute($this->nonce);
        $buttonText  = $this->escapeHtml($this->translate('Run manual check'));
        $description = $this->escapeHtml($this->translate('Checks the configured apex domain now.'));

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_manual_check" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <p>
        <button type="submit" class="button button-primary">{$buttonText}</button>
        <span class="description">{$description}</span>
    </p>
</form>
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
