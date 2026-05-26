<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

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
            'Domain Monitor',
            [$this, 'render']
        );
    }

    public function render(): void
    {
        echo $this->renderHtml();
    }

    public function renderHtml(): string
    {
        $domain = $this->escapeHtml($this->domain);
        $status = $this->statusLabel();
        $message = $this->messageHtml();
        $checkedAt = $this->checkedAtHtml();
        $form = $this->manualCheckFormHtml();

        return <<<HTML
<div class="domain-monitor-widget">
    <p><strong>Monitored domain:</strong> {$domain}</p>
    <p><strong>Status: {$status}</strong></p>
    {$message}
    {$checkedAt}
    {$form}
</div>
HTML;
    }

    private function statusLabel(): string
    {
        if ($this->lastResult === null || ($this->lastResult['status'] ?? '') === '') {
            return 'Not checked yet';
        }

        return strtoupper($this->escapeHtml((string) $this->lastResult['status']));
    }

    private function messageHtml(): string
    {
        $message = $this->lastResult['message'] ?? 'Domain Monitor is active. Run a manual check to capture the first proof-of-concept result.';

        return '<p>' . $this->escapeHtml((string) $message) . '</p>';
    }

    private function checkedAtHtml(): string
    {
        if ($this->lastResult === null || ($this->lastResult['checked_at'] ?? '') === '') {
            return '';
        }

        return '<p>Last checked: ' . $this->escapeHtml((string) $this->lastResult['checked_at']) . '</p>';
    }

    private function manualCheckFormHtml(): string
    {
        $actionUrl = $this->escapeAttribute($this->actionUrl);
        $nonce = $this->escapeAttribute($this->nonce);

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_manual_check" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <p>
        <button type="submit" class="button button-primary">Run manual check</button>
        <span class="description">Checks the configured apex domain now.</span>
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
}
