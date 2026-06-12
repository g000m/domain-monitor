<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DateTimeImmutable;
use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Multi-domain dashboard widget rendering tests.
 *
 * Uses ArrayDomainStore so no WordPress is required.
 */
final class DashboardWidgetMultiDomainTest extends TestCase
{
    // -----------------------------------------------------------------
    // fromDomains() factory
    // -----------------------------------------------------------------

    public function test_fromDomains_renders_table_for_multiple_domains(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com',  'status' => 'ok',   'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => '2027-01-01T00:00:00Z'],
            ['domain' => 'example.org',  'status' => 'warn', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => '2026-08-01T00:00:00Z'],
        ], 'https://example.com/wp-admin/admin-post.php', 'test-nonce');

        $html = $widget->renderHtml();

        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('example.org', $html);
        self::assertStringContainsString('domain-monitor-widget--multi', $html);
        self::assertStringContainsString('<table', $html);
    }

    public function test_fromDomains_renders_status_pills(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'ok.example.com',   'status' => 'ok',   'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
            ['domain' => 'warn.example.com', 'status' => 'warn', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
            ['domain' => 'fail.example.com', 'status' => 'fail', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);

        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-pill--ok', $html);
        self::assertStringContainsString('domain-monitor-pill--warn', $html);
        self::assertStringContainsString('domain-monitor-pill--fail', $html);
        // Uppercase labels.
        self::assertStringContainsString('OK', $html);
        self::assertStringContainsString('WARN', $html);
        self::assertStringContainsString('FAIL', $html);
    }

    public function test_fromDomains_single_domain_uses_orb_layout(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'ok', 'message' => 'All clear.', 'checked_at' => '2026-06-01', 'expires_at' => '2027-01-01T00:00:00Z'],
        ], 'https://example.com/admin-post.php', 'nonce-x');

        $html = $widget->renderHtml();

        // Single-domain path: orb view, not the multi-domain table.
        self::assertStringNotContainsString('domain-monitor-widget--multi', $html);
        self::assertStringContainsString('domain-monitor-widget--orb', $html);
        self::assertStringContainsString('domain-monitor-orb', $html);
        self::assertStringContainsString('example.com', $html);
    }

    public function test_fromDomains_empty_list_shows_dev_notice(): void
    {
        $widget = DashboardWidget::fromDomains([]);

        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-dev-notice', $html);
        self::assertStringContainsString('No public domain detected', $html);
    }

    public function test_fromDomains_escapes_domain_name(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => '<script>xss</script>.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);

        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_fromDomains_unchecked_domain_shows_not_checked_pill(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'new.example.com', 'status' => '', 'message' => '', 'checked_at' => '', 'expires_at' => ''],
            ['domain' => 'old.example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);

        $html = $widget->renderHtml();

        self::assertStringContainsString('Not checked yet', $html);
        self::assertStringContainsString('domain-monitor-pill--unknown', $html);
    }

    // -----------------------------------------------------------------
    // Integration with ArrayDomainStore and StatusCalculator
    // -----------------------------------------------------------------

    public function test_widget_reflects_multiple_domains_from_array_store(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $calculator = new StatusCalculator();

        // Seed two domains.
        $id1 = $repository->upsertDomain('alpha.example.com', 'manual');
        $id2 = $repository->upsertDomain('beta.example.com', 'auto');

        // Give alpha a full check result.
        $repository->saveCheckResult($id1, [
            'dns_status'      => 'ok',
            'dns_message'     => 'DNS ok.',
            'rdap_status'     => 'ok',
            'rdap_message'    => 'RDAP ok.',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2027-06-01T00:00:00Z',
            'last_checked_at' => '2026-06-01 12:00:00',
        ]);

        // beta remains unchecked.

        $now      = new DateTimeImmutable('2026-06-01 12:00:00');
        $summaries = [];

        foreach ($repository->all() as $record) {
            if ($record->lastCheckedAt() === '') {
                $summaries[] = [
                    'domain'     => $record->domain(),
                    'status'     => '',
                    'message'    => '',
                    'checked_at' => '',
                    'expires_at' => '',
                ];
                continue;
            }

            $alerts = $alertStore->openAlertsForDomain($record->id());
            $status = $calculator->calculate($record->snapshot(), $alerts, $now);

            $summaries[] = [
                'domain'     => $record->domain(),
                'status'     => $status->code(),
                'message'    => $status->message(),
                'checked_at' => $record->lastCheckedAt(),
                'expires_at' => $record->rdapExpiresAt(),
            ];
        }

        $widget = DashboardWidget::fromDomains($summaries, 'https://example.com/admin-post.php', 'nonce');
        $html   = $widget->renderHtml();

        self::assertStringContainsString('alpha.example.com', $html);
        self::assertStringContainsString('beta.example.com', $html);
        // alpha should be OK, beta should be "Not checked yet".
        self::assertStringContainsString('domain-monitor-pill--ok', $html);
        self::assertStringContainsString('Not checked yet', $html);
        // Expiry date formatted.
        self::assertStringContainsString('2027-06-01', $html);
    }

    public function test_widget_respects_is_active_flag(): void
    {
        // Inactive domains should not appear in summaries — callers filter them.
        // This test verifies the widget renders only what it is given.
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'active.example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);

        $html = $widget->renderHtml();

        self::assertStringContainsString('active.example.com', $html);
        // Inactive domain was never passed in.
        self::assertStringNotContainsString('inactive.example.com', $html);
    }

    // -----------------------------------------------------------------
    // Legacy single-domain constructor tests (backward compat)
    // -----------------------------------------------------------------

    public function test_legacy_constructor_single_domain_with_result(): void
    {
        $widget = new DashboardWidget('legacy.example.com', [
            'status'     => 'ok',
            'message'    => 'All clear.',
            'checked_at' => '2026-06-01T10:00:00Z',
            'expires_at' => '2027-01-01T00:00:00Z',
        ], 'https://example.com/wp-admin/admin-post.php', 'legacy-nonce');

        $html = $widget->renderHtml();

        // Orb view for single domain: green orb, domain name, All checks passing subtitle.
        self::assertStringContainsString('legacy.example.com', $html);
        self::assertStringContainsString('domain-monitor-orb--green', $html);
        self::assertStringContainsString('All checks passing', $html);
        self::assertStringContainsString('domain-monitor-widget--orb', $html);
    }

    public function test_legacy_constructor_empty_domain_shows_dev_notice(): void
    {
        $widget = new DashboardWidget('');

        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-dev-notice', $html);
    }
}
