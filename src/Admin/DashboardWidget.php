<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ExpirationDate;

final class DashboardWidget
{
    /**
     * List of domain summaries for multi-domain rendering.
     *
     * Core keys (always present):
     *   domain, status, message, checked_at, expires_at
     *
     * Extended keys used by the orb view (optional, default to '' / []):
     *   rdap_expires_at   string   RDAP registration expiry (ISO-8601)
     *   ssl_expires_at    string   SSL certificate expiry (ISO-8601)
     *   open_alert_types  string[] Open alert type slugs for this domain
     *
     * @var list<array<string,mixed>>
     */
    private array $domainRows;

    private string $actionUrl;
    private string $nonce;
    private string $detailsUrl;

    /**
     * Legacy single-domain constructor path (kept for back-compat with existing tests).
     * Production code should call DashboardWidget::fromDomains() instead.
     *
     * @param array<string,string>|null $lastResult
     */
    public function __construct(string $domain, ?array $lastResult = null, string $actionUrl = '', string $nonce = '', string $detailsUrl = '')
    {
        $this->actionUrl  = $actionUrl;
        $this->nonce      = $nonce;
        $this->detailsUrl = $detailsUrl;

        if ($domain === '') {
            $this->domainRows = [];
            return;
        }

        // Wrap single-domain data into the unified list format.
        $this->domainRows = [
            [
                'domain'           => $domain,
                'status'           => $lastResult !== null ? (string) ($lastResult['status'] ?? '') : '',
                'message'          => (string) ($lastResult['message'] ?? ''),
                'checked_at'       => $lastResult !== null ? (string) ($lastResult['checked_at'] ?? '') : '',
                'expires_at'       => $lastResult !== null ? (string) ($lastResult['expires_at'] ?? '') : '',
                'rdap_expires_at'  => $lastResult !== null ? (string) ($lastResult['rdap_expires_at'] ?? $lastResult['expires_at'] ?? '') : '',
                'ssl_expires_at'   => $lastResult !== null ? (string) ($lastResult['ssl_expires_at'] ?? '') : '',
                'open_alert_types' => [],
            ],
        ];
    }

    /**
     * Named constructor for multi-domain mode used by Plugin.php.
     *
     * Extended keys accepted in each row (all optional):
     *   rdap_expires_at   string
     *   ssl_expires_at    string
     *   open_alert_types  string[]
     *
     * @param list<array<string,mixed>> $domainRows
     */
    public static function fromDomains(array $domainRows, string $actionUrl = '', string $nonce = '', string $detailsUrl = ''): self
    {
        $widget             = new self('__bypass__', null, $actionUrl, $nonce, $detailsUrl);
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

        // Single-domain: use the ambient orb view (the 99 % case).
        if (count($this->domainRows) === 1) {
            return $this->renderOrbView($this->domainRows[0]);
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
     * Ambient orb view — rendered when exactly one domain is monitored.
     *
     * @param array<string,mixed> $row
     */
    private function renderOrbView(array $row): string
    {
        $presenter = new OrbStatusPresenter();
        $color     = $presenter->color($row);
        $domain    = $this->escapeHtml((string) ($row['domain'] ?? ''));
        $detailUrl = $this->detailsUrl !== ''
            ? $this->escapeAttribute($this->detailsUrl)
            : $this->escapeAttribute($this->settingsUrl());
        $detailText = $this->escapeHtml($this->translate('Details'));

        $orbClass = 'domain-monitor-orb domain-monitor-orb--' . $this->escapeAttribute($color);

        $style = $this->orbStylesHtml();

        if ($color === OrbStatusPresenter::COLOR_GRAY) {
            $pendingText = $this->escapeHtml($this->translate('First check pending'));
            return <<<HTML
{$style}
<div class="domain-monitor-widget domain-monitor-widget--orb">
    <div class="domain-monitor-orb-wrap">
        <div class="{$orbClass}"></div>
        <div class="domain-monitor-orb-body">
            <span class="domain-monitor-orb-fact--muted">{$pendingText}</span>
            <span class="domain-monitor-orb-fact--muted domain-monitor-orb-domain-sub">{$domain}</span>
        </div>
    </div>
    <div class="domain-monitor-orb-footer">
        <a href="{$detailUrl}">{$detailText}</a>
    </div>
</div>
HTML;
        }

        if ($color === OrbStatusPresenter::COLOR_GREEN) {
            $checkedAgo = $presenter->checkedAgoText($row);
            $subtitle   = '';
            if ($checkedAgo !== '') {
                $allClearText = $this->escapeHtml(
                    $this->translate('All checks passing') . ' · ' . $checkedAgo
                );
                $subtitle = '<p class="domain-monitor-orb-subtitle">' . $allClearText . '</p>';
            }

            return <<<HTML
{$style}
<div class="domain-monitor-widget domain-monitor-widget--orb">
    <div class="domain-monitor-orb-wrap">
        <div class="{$orbClass}"></div>
        <div>
            <p class="domain-monitor-orb-domain">{$domain}</p>
            {$subtitle}
        </div>
    </div>
    <div class="domain-monitor-orb-footer">
        <a href="{$detailUrl}">{$detailText}</a>
    </div>
</div>
HTML;
        }

        // AMBER or RED.
        $factLine    = $this->escapeHtml($presenter->factLine($row));
        $factClass   = 'domain-monitor-orb-fact domain-monitor-orb-fact--' . $this->escapeAttribute($color);
        $actionHtml  = $this->orbActionHtml($color, $detailUrl, $detailText);

        return <<<HTML
{$style}
<div class="domain-monitor-widget domain-monitor-widget--orb">
    <div class="domain-monitor-orb-wrap">
        <div class="{$orbClass}"></div>
        <div class="domain-monitor-orb-body">
            <span class="{$factClass}">{$factLine}</span>
            <span class="domain-monitor-orb-fact--muted domain-monitor-orb-domain-sub">{$domain}</span>
        </div>
        {$actionHtml}
    </div>
    <div class="domain-monitor-orb-footer">
        <a href="{$detailUrl}">{$detailText}</a>
    </div>
</div>
HTML;
    }

    /**
     * Inline <style> block for the orb widget. Scoped with domain-monitor- prefixes.
     * Only emitted once per widget render (the widget only appears once per page).
     */
    private function orbStylesHtml(): string
    {
        return <<<'CSS'
<style>
.domain-monitor-orb-wrap {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 28px 12px 20px;
    gap: 14px;
}
.domain-monitor-orb {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    flex-shrink: 0;
}
.domain-monitor-orb--green {
    background: #00a32a;
    box-shadow: 0 0 0 8px rgba(0,163,42,.12), 0 0 24px rgba(0,163,42,.30);
}
.domain-monitor-orb--amber {
    background: #dba617;
    box-shadow: 0 0 0 8px rgba(219,166,23,.12), 0 0 20px rgba(219,166,23,.28);
}
.domain-monitor-orb--red {
    background: #d63638;
    box-shadow: 0 0 0 8px rgba(214,54,56,.14), 0 0 24px rgba(214,54,56,.35);
    animation: domain-monitor-orb-pulse 2.6s ease-in-out infinite;
}
.domain-monitor-orb--gray {
    background: #8c8f94;
    box-shadow: 0 0 0 8px rgba(140,143,148,.10), 0 0 16px rgba(140,143,148,.18);
}
@keyframes domain-monitor-orb-pulse {
    0%   { transform: scale(1);    box-shadow: 0 0 0 8px rgba(214,54,56,.14), 0 0 24px rgba(214,54,56,.35); }
    50%  { transform: scale(1.07); box-shadow: 0 0 0 14px rgba(214,54,56,.10), 0 0 36px rgba(214,54,56,.55); }
    100% { transform: scale(1);    box-shadow: 0 0 0 8px rgba(214,54,56,.14), 0 0 24px rgba(214,54,56,.35); }
}
.domain-monitor-orb-body {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 5px;
    text-align: center;
    flex: 1;
}
.domain-monitor-orb-domain {
    font-size: 13px;
    font-weight: 600;
    color: #1d2327;
    text-align: center;
    line-height: 1.4;
    margin: 0;
}
.domain-monitor-orb-subtitle {
    font-size: 12px;
    color: #646970;
    text-align: center;
    margin: -8px 0 0;
}
.domain-monitor-orb-fact {
    font-size: 12px;
    font-weight: 600;
    line-height: 1.4;
}
.domain-monitor-orb-fact--amber { color: #8a6116; }
.domain-monitor-orb-fact--red   { color: #d63638; }
.domain-monitor-orb-fact--muted { color: #646970; font-weight: 400; font-size: 11px; }
.domain-monitor-orb-domain-sub  { font-size: 11px; }
.domain-monitor-orb-action { margin-top: 2px; }
.domain-monitor-orb-action a { font-size: 12px; color: #2271b1; text-decoration: none; }
.domain-monitor-orb-action a:hover { text-decoration: underline; }
.domain-monitor-orb-action .button-primary { height: 26px; line-height: 24px; padding: 0 10px; font-size: 12px; }
.domain-monitor-orb-footer {
    text-align: center;
    padding: 8px 12px 12px;
    border-top: 1px solid #c3c4c7;
}
.domain-monitor-orb-footer a { font-size: 11px; color: #646970; text-decoration: none; }
.domain-monitor-orb-footer a:hover { color: #2271b1; text-decoration: underline; }
</style>
CSS;
    }

    /**
     * Renders the action link/button for amber and red orb states.
     * Red state uses a primary button per the design card; amber uses a plain link.
     */
    private function orbActionHtml(string $color, string $detailUrl, string $detailText): string
    {
        if ($color === OrbStatusPresenter::COLOR_RED) {
            return '<div class="domain-monitor-orb-action">'
                . '<a href="' . $detailUrl . '" class="button button-primary">' . $detailText . '</a>'
                . '</div>';
        }

        // Amber: plain link.
        return '<div class="domain-monitor-orb-action">'
            . '<a href="' . $detailUrl . '">' . $detailText . '</a>'
            . '</div>';
    }

    /**
     * Returns the admin settings page URL for the "Details" link.
     * Falls back to '#' when WordPress functions are unavailable (tests).
     */
    private function settingsUrl(): string
    {
        if (function_exists('admin_url')) {
            return (string) admin_url('options-general.php?page=domain-monitor');
        }

        return '#';
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

        $pillClasses = [
            'ok'   => 'domain-monitor-pill--ok',
            'warn' => 'domain-monitor-pill--warn',
            'fail' => 'domain-monitor-pill--fail',
        ];
        $cssClass = isset($pillClasses[$status]) ? $pillClasses[$status] : 'domain-monitor-pill--unknown';

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
