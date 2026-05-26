<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

final class DashboardWidget
{
    private string $domain;

    public function __construct(string $domain)
    {
        $this->domain = $domain;
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

        return <<<HTML
<div class="domain-monitor-widget">
    <p><strong>Monitored domain:</strong> {$domain}</p>
    <p><strong>Status: Not checked yet</strong></p>
    <p>Domain Monitor is active. The next proof-of-concept step will connect this widget to RDAP, DNS, and alert state checks.</p>
    <p>
        <button type="button" class="button button-primary" disabled aria-disabled="true">Run first check</button>
        <span class="description">Manual checks are coming next.</span>
    </p>
</div>
HTML;
    }

    private function escapeHtml(string $value): string
    {
        if (function_exists('esc_html')) {
            return esc_html($value);
        }

        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
