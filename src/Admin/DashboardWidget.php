<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ExpirationDate;

final class DashboardWidget
{
    /**
     * List of domain summaries for multi-domain rendering.
     * Each entry: ['domain'=>string, 'status'=>string, 'message'=>string, 'checked_at'=>string, 'expires_at'=>string]
     *
     * @var list<array<string,string>>
     */
    private array $domainRows;

    private string $actionUrl;
    private string $nonce;

    /**
     * Legacy single-domain constructor path (kept for back-compat with existing tests).
     * Production code should call DashboardWidget::fromDomains() instead.
     *
     * @param array<string,string>|null $lastResult
     */
    public function __construct(string $domain, ?array $lastResult = null, string $actionUrl = '', string $nonce = '')
    {
        $this->actionUrl = $actionUrl;
        $this->nonce     = $nonce;

        if ($domain === '') {
            $this->domainRows = [];
            return;
        }

        // Wrap single-domain data into the unified list format.
        $this->domainRows = [
            [
                'domain'     => $domain,
                'status'     => $lastResult !== null ? (string) ($lastResult['status'] ?? '') : '',
                'message'    => (string) ($lastResult['message'] ?? ''),
                'checked_at' => $lastResult !== null ? (string) ($lastResult['checked_at'] ?? '') : '',
                'expires_at' => $lastResult !== null ? (string) ($lastResult['expires_at'] ?? '') : '',
            ],
        ];
    }

    /**
     * Named constructor for multi-domain mode used by Plugin.php.
     *
     * @param list<array<string,string>> $domainRows Each row: domain, status, message, checked_at, expires_at.
     */
    public static function fromDomains(array $domainRows, string $actionUrl = '', string $nonce = ''): self
    {
        $widget             = new self('__bypass__', null, $actionUrl, $nonce);
        $widget->domainRows = $domainRows;
        return $widget;
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
        if ($this->domainRows === []) {
            return $this->devNoticeHtml();
        }

        // Single-domain legacy path: preserve the detailed original layout.
        if (count($this->domainRows) === 1) {
            return $this->renderSingleDomain($this->domainRows[0]);
        }

        return $this->renderMultiDomain();
    }

    // -----------------------------------------------------------------
    // Private rendering helpers
    // -----------------------------------------------------------------

    /**
     * Rendered when no monitorable domain has been detected (local/dev environment).
     */
    private function devNoticeHtml(): string
    {
        $message = $this->escapeHtml($this->translate(
            'No public domain detected. This site appears to be a local or development environment. '
            . 'Add a domain below, or define DOMAIN_MONITOR_PRIMARY_DOMAIN in wp-config.php.'
        ));

        return '<div class="domain-monitor-widget"><p class="domain-monitor-dev-notice">' . $message . '</p></div>';
    }

    /**
     * Original single-domain detailed layout — unchanged so existing behaviour is preserved.
     *
     * @param array<string,string> $row
     */
    private function renderSingleDomain(array $row): string
    {
        $domain     = $this->escapeHtml($row['domain']);
        $status     = $this->statusPill($row['status']);
        $message    = $this->rowMessageHtml($row['message']);
        $expiration = $this->rowExpirationHtml($row['expires_at']);
        $checkedAt  = $this->rowCheckedAtHtml($row['checked_at']);
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

    /**
     * Compact list layout for two or more domains.
     */
    private function renderMultiDomain(): string
    {
        $rows = '';
        foreach ($this->domainRows as $row) {
            $domain    = $this->escapeHtml($row['domain']);
            $pill      = $this->statusPill($row['status']);
            $expiresAt = $row['expires_at'] ?? '';
            $expiry    = $this->escapeHtml(ExpirationDate::label($expiresAt));

            $daysLeft = '';
            if ($expiresAt !== '') {
                try {
                    $diff     = (new \DateTimeImmutable($expiresAt))->diff(new \DateTimeImmutable());
                    $days     = (int) $diff->days;
                    $daysLeft = $diff->invert
                        ? $this->escapeHtml(sprintf($this->translate('%d days'), $days))
                        : $this->escapeHtml($this->translate('Expired'));
                } catch (\Exception $e) {
                    // Leave $daysLeft empty.
                }
            }

            $daysHtml = $daysLeft !== '' ? '<td class="domain-monitor-days">' . $daysLeft . '</td>' : '<td></td>';

            $rows .= <<<HTML
<tr class="domain-monitor-row">
    <td class="domain-monitor-domain-name">{$domain}</td>
    <td class="domain-monitor-status">{$pill}</td>
    <td class="domain-monitor-expiry">{$expiry}</td>
    {$daysHtml}
</tr>
HTML;
        }

        $domainHeader  = $this->escapeHtml($this->translate('Domain'));
        $statusHeader  = $this->escapeHtml($this->translate('Status'));
        $expiryHeader  = $this->escapeHtml($this->translate('Expires'));
        $daysHeader    = $this->escapeHtml($this->translate('Days'));
        $form          = $this->manualCheckFormHtml();

        return <<<HTML
<div class="domain-monitor-widget domain-monitor-widget--multi">
    <table class="domain-monitor-table widefat">
        <thead>
            <tr>
                <th>{$domainHeader}</th>
                <th>{$statusHeader}</th>
                <th>{$expiryHeader}</th>
                <th>{$daysHeader}</th>
            </tr>
        </thead>
        <tbody>
            {$rows}
        </tbody>
    </table>
    {$form}
</div>
HTML;
    }

    /**
     * Render a coloured status pill for a status code (ok/warn/fail/empty).
     * Reuses status->label/colour logic from DomainStatus without duplicating it.
     */
    private function statusPill(string $status): string
    {
        if ($status === '') {
            return '<span class="domain-monitor-pill domain-monitor-pill--unknown">'
                . $this->escapeHtml($this->translate('Not checked yet'))
                . '</span>';
        }

        $cssClass = match ($status) {
            'ok'   => 'domain-monitor-pill--ok',
            'warn' => 'domain-monitor-pill--warn',
            'fail' => 'domain-monitor-pill--fail',
            default => 'domain-monitor-pill--unknown',
        };

        return '<span class="domain-monitor-pill ' . $this->escapeAttribute($cssClass) . '">'
            . strtoupper($this->escapeHtml($status))
            . '</span>';
    }

    /** @param string $message */
    private function rowMessageHtml(string $message): string
    {
        if ($message === '') {
            $message = $this->translate('Domain Monitor is active. Run a manual check to capture the first proof-of-concept result.');
        }

        return '<p>' . $this->escapeHtml($message) . '</p>';
    }

    private function rowCheckedAtHtml(string $checkedAt): string
    {
        if ($checkedAt === '') {
            return '';
        }

        $label = $this->escapeHtml($this->translate('Last checked:'));
        return '<p>' . $label . ' ' . $this->escapeHtml($checkedAt) . '</p>';
    }

    private function rowExpirationHtml(string $expiresAt): string
    {
        $label = $this->escapeHtml($this->translate('Domain expires:'));
        return '<p>' . $label . ' ' . $this->escapeHtml(ExpirationDate::label($expiresAt)) . '</p>';
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
