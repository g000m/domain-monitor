<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DashboardWidget;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetTest extends TestCase
{
    public function test_it_renders_visible_domain_monitor_summary(): void
    {
        $widget = new DashboardWidget('example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('Domain Monitor', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('Status: Not checked yet', $html);
        self::assertStringContainsString('Run manual check', $html);
    }

    public function test_it_renders_manual_check_form_with_nonce(): void
    {
        $widget = new DashboardWidget('example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('method="post"', $html);
        self::assertStringContainsString('action="https://example.com/wp-admin/admin-post.php"', $html);
        self::assertStringContainsString('name="action" value="domain_monitor_manual_check"', $html);
        self::assertStringContainsString('name="_wpnonce" value="nonce-value"', $html);
    }

    public function test_it_renders_last_manual_check_result(): void
    {
        $widget = new DashboardWidget('example.com', [
            'status' => 'ok',
            'message' => 'Manual check completed for example.com.',
            'checked_at' => '2026-05-26T21:00:00+00:00',
        ], 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringContainsString('Status: OK', $html);
        self::assertStringContainsString('Manual check completed for example.com.', $html);
        self::assertStringContainsString('Last checked: 2026-05-26T21:00:00+00:00', $html);
    }

    public function test_it_escapes_the_domain_in_widget_output(): void
    {
        $widget = new DashboardWidget('<script>alert(1)</script>.example.com', null, 'https://example.com/wp-admin/admin-post.php', 'nonce-value');

        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.example.com', $html);
    }
}
