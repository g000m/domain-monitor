<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DashboardWidget;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetTest extends TestCase
{
    public function test_it_renders_visible_domain_monitor_summary(): void
    {
        // Single domain with no prior check renders the orb view in gray (pending) state.
        $widget = new DashboardWidget('example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('domain-monitor-orb', $html);
        self::assertStringContainsString('First check pending', $html);
    }

    public function test_it_renders_orb_with_details_link(): void
    {
        // Details link is always present in the orb footer.
        $widget = new DashboardWidget('example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value', '#');

        $html = $widget->renderHtml();

        self::assertStringContainsString('Details', $html);
        self::assertStringContainsString('domain-monitor-orb-footer', $html);
    }

    public function test_it_renders_orb_green_for_ok_status(): void
    {
        $widget = new DashboardWidget('example.com', [
            'status'     => 'ok',
            'message'    => 'All checks passing.',
            'checked_at' => '2026-05-26T21:00:00+00:00',
            'expires_at' => '2027-01-01T00:00:00Z',
        ], 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb--green', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('All checks passing', $html);
    }

    public function test_it_renders_orb_red_for_fail_status(): void
    {
        $widget = new DashboardWidget('example.com', [
            'status'     => 'fail',
            'message'    => 'SSL certificate has expired.',
            'checked_at' => '2026-05-26T21:00:00+00:00',
            'expires_at' => '',
            'ssl_expires_at' => '2026-01-01T00:00:00Z',
        ], 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('domain-monitor-orb--red', $html);
        self::assertStringContainsString('example.com', $html);
        // Red state shows a primary button for the Details action.
        self::assertStringContainsString('button-primary', $html);
    }

    public function test_it_escapes_the_domain_in_widget_output(): void
    {
        $widget = new DashboardWidget('<script>alert(1)</script>.example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.example.com', $html);
    }
}
