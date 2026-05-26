<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ExpirationDate;
use DomainMonitor\Storage\DomainRecord;

final class SettingsPage
{
    /** @var list<DomainRecord> */
    private array $domains;
    private string $actionUrl;
    private string $nonce;

    /** @param list<DomainRecord> $domains */
    public function __construct(array $domains, string $actionUrl, string $nonce)
    {
        $this->domains = $domains;
        $this->actionUrl = $actionUrl;
        $this->nonce = $nonce;
    }

    public function register(): void
    {
        if (! function_exists('add_options_page')) {
            return;
        }

        add_options_page(
            'Domain Monitor',
            'Domain Monitor',
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
        $rows = $this->domainRowsHtml();
        $addForm = $this->addDomainFormHtml();

        return <<<HTML
<div class="wrap domain-monitor-settings">
    <h1>Domain Monitor</h1>
    <h2>Monitored domains</h2>
    {$rows}
    <h2>Add another domain</h2>
    {$addForm}
</div>
HTML;
    }

    private function domainRowsHtml(): string
    {
        if ($this->domains === []) {
            return '<p>No domains are monitored yet.</p>';
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
        $source = $record->source() === 'auto' ? 'Auto-detected' : 'Manual';
        $source = $this->escapeHtml($source);
        $dns = $this->escapeHtml(strtoupper($record->dnsStatus()));
        $rdap = $this->escapeHtml(strtoupper($record->rdapStatus()));
        $dnsMessage = $record->dnsMessage() !== '' ? '<p>' . $this->escapeHtml($record->dnsMessage()) . '</p>' : '';
        $expiration = '<p>Domain expires: ' . $this->escapeHtml(ExpirationDate::label($record->rdapExpiresAt())) . '</p>';
        $rdapMessage = $record->rdapMessage() !== '' ? '<p>' . $this->escapeHtml($record->rdapMessage()) . '</p>' : '';
        $checkedAt = $record->lastCheckedAt() !== '' ? '<p>Last checked: ' . $this->escapeHtml($record->lastCheckedAt()) . '</p>' : '';
        $form = $this->manualCheckFormHtml($record->id());

        return <<<HTML
<section class="domain-monitor-domain">
    <h3>{$domain}</h3>
    <p>Source: {$source}</p>
    <p><strong>DNS: {$dns}</strong></p>
    {$dnsMessage}
    <p><strong>RDAP: {$rdap}</strong></p>
    {$expiration}
    {$rdapMessage}
    {$checkedAt}
    {$form}
</section>
HTML;
    }

    private function addDomainFormHtml(): string
    {
        $actionUrl = $this->escapeAttribute($this->actionUrl);
        $nonce = $this->escapeAttribute($this->nonce);

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_add_domain" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <p>
        <label for="domain-monitor-domain">Domain</label>
        <input id="domain-monitor-domain" type="text" name="domain_monitor_domain" class="regular-text" placeholder="example.com" />
        <button type="submit" class="button button-primary">Add domain</button>
    </p>
</form>
HTML;
    }

    private function manualCheckFormHtml(int $domainId): string
    {
        $actionUrl = $this->escapeAttribute($this->actionUrl);
        $nonce = $this->escapeAttribute($this->nonce);
        $id = $this->escapeAttribute((string) $domainId);

        return <<<HTML
<form method="post" action="{$actionUrl}">
    <input type="hidden" name="action" value="domain_monitor_manual_check" />
    <input type="hidden" name="_wpnonce" value="{$nonce}" />
    <input type="hidden" name="domain_monitor_domain_id" value="{$id}" />
    <button type="submit" class="button">Run check</button>
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
