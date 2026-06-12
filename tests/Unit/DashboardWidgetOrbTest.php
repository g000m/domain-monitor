<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DashboardWidget;
use PHPUnit\Framework\TestCase;

/**
 * Tests specific to the orb ambient-status view rendered for single-domain setups.
 */
final class DashboardWidgetOrbTest extends TestCase
{
    // -----------------------------------------------------------------
    // Routing: 0 / 1 / 2+ domains
    // -----------------------------------------------------------------

    public function test_zero_domains_renders_empty_state(): void
    {
        $widget = DashboardWidget::fromDomains([]);
        $html   = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-dev-notice', $html);
        self::assertStringNotContainsString('domain-monitor-orb', $html);
        self::assertStringNotContainsString('domain-monitor-widget--multi', $html);
    }

    public function test_one_domain_renders_orb_view(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-widget--orb', $html);
        self::assertStringContainsString('domain-monitor-orb', $html);
        self::assertStringNotContainsString('domain-monitor-widget--multi', $html);
    }

    public function test_two_domains_renders_table_not_orb(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'alpha.example.com', 'status' => 'ok',   'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
            ['domain' => 'beta.example.com',  'status' => 'warn', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-widget--multi', $html);
        self::assertStringContainsString('<table', $html);
        self::assertStringNotContainsString('domain-monitor-widget--orb', $html);
    }

    // -----------------------------------------------------------------
    // Color states
    // -----------------------------------------------------------------

    public function test_ok_status_renders_green_orb(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        // The orb div must carry the --green modifier.
        self::assertStringContainsString('class="domain-monitor-orb domain-monitor-orb--green"', $html);
        // No other colour modifier on the orb element itself.
        self::assertStringNotContainsString('class="domain-monitor-orb domain-monitor-orb--red"', $html);
        self::assertStringNotContainsString('class="domain-monitor-orb domain-monitor-orb--amber"', $html);
        self::assertStringNotContainsString('class="domain-monitor-orb domain-monitor-orb--gray"', $html);
    }

    public function test_warn_status_renders_amber_orb(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'warn', 'message' => 'Expiring soon.', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb--amber', $html);
    }

    public function test_fail_status_renders_red_orb(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'fail', 'message' => 'SSL expired.', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb--red', $html);
    }

    public function test_never_checked_renders_gray_orb(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => '', 'message' => '', 'checked_at' => '', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb--gray', $html);
        self::assertStringContainsString('First check pending', $html);
    }

    // -----------------------------------------------------------------
    // Green state: subtitle + no fact line
    // -----------------------------------------------------------------

    public function test_green_orb_shows_all_checks_passing_subtitle(): void
    {
        // checked_at set so a time-ago string is generated.
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01 12:00:00', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('All checks passing', $html);
        self::assertStringContainsString('domain-monitor-orb-subtitle', $html);
    }

    public function test_green_orb_does_not_show_fact_line(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'ok', 'message' => 'All domain checks are clear.', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        // The StatusCalculator message should NOT appear as a fact line in green state.
        self::assertStringNotContainsString('domain-monitor-orb-fact--green', $html);
    }

    // -----------------------------------------------------------------
    // Amber / red: fact line shown
    // -----------------------------------------------------------------

    public function test_amber_orb_shows_fact_line(): void
    {
        $expiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+20 days'));

        $widget = DashboardWidget::fromDomains([
            [
                'domain'          => 'example.com',
                'status'          => 'warn',
                'message'         => 'Domain registration expires within 30 days.',
                'checked_at'      => '2026-06-01',
                'expires_at'      => $expiry,
                'rdap_expires_at' => $expiry,
                'ssl_expires_at'  => '',
                'open_alert_types' => [],
            ],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb-fact--amber', $html);
        self::assertStringContainsString('Domain expires in', $html);
    }

    public function test_red_orb_shows_fact_line_and_button(): void
    {
        $widget = DashboardWidget::fromDomains([
            [
                'domain'           => 'example.com',
                'status'           => 'fail',
                'message'          => 'SSL certificate has expired.',
                'checked_at'       => '2026-06-01',
                'expires_at'       => '',
                'rdap_expires_at'  => '',
                'ssl_expires_at'   => '2026-01-01T00:00:00Z',
                'open_alert_types' => [],
            ],
        ]);
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb-fact--red', $html);
        self::assertStringContainsString('SSL certificate expired', $html);
        // Red state shows primary action button.
        self::assertStringContainsString('button-primary', $html);
    }

    // -----------------------------------------------------------------
    // Red pulse animation CSS present
    // -----------------------------------------------------------------

    public function test_red_orb_css_includes_pulse_animation(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => 'example.com', 'status' => 'fail', 'message' => 'check failed.', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        // The pulse keyframe animation is embedded in the inline style block.
        self::assertStringContainsString('domain-monitor-orb-pulse', $html);
        self::assertStringContainsString('@keyframes', $html);
    }

    // -----------------------------------------------------------------
    // Details link always present
    // -----------------------------------------------------------------

    public function test_details_link_present_in_footer_for_green(): void
    {
        $widget = DashboardWidget::fromDomains(
            [['domain' => 'example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => '']],
            '',
            '',
            'https://example.com/wp-admin/options-general.php?page=domain-monitor'
        );
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb-footer', $html);
        self::assertStringContainsString('Details', $html);
        self::assertStringContainsString('options-general.php', $html);
    }

    public function test_details_link_present_in_footer_for_gray(): void
    {
        $widget = DashboardWidget::fromDomains(
            [['domain' => 'example.com', 'status' => '', 'message' => '', 'checked_at' => '', 'expires_at' => '']],
            '',
            '',
            '#'
        );
        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb-footer', $html);
        self::assertStringContainsString('Details', $html);
    }

    // -----------------------------------------------------------------
    // Escaping
    // -----------------------------------------------------------------

    public function test_domain_name_is_escaped_in_orb_view(): void
    {
        $widget = DashboardWidget::fromDomains([
            ['domain' => '<script>xss</script>.example.com', 'status' => 'ok', 'message' => '', 'checked_at' => '2026-06-01', 'expires_at' => ''],
        ]);
        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_fact_line_is_escaped_in_orb_view(): void
    {
        $widget = DashboardWidget::fromDomains([
            [
                'domain'           => 'example.com',
                'status'           => 'fail',
                'message'          => '<img src=x onerror=alert(1)>',
                'checked_at'       => '2026-06-01',
                'expires_at'       => '',
                'rdap_expires_at'  => '',
                'ssl_expires_at'   => '',
                'open_alert_types' => [],
            ],
        ]);
        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }
}
