<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DashboardWidget;
use DomainMonitor\Admin\SettingsPage;
use PHPUnit\Framework\TestCase;

/**
 * Task 3: Empty-state UX.
 * Verifies that SettingsPage and DashboardWidget render the dev-environment
 * notice when no domain has been configured, while still keeping the form visible.
 */
final class EmptyStateUxTest extends TestCase
{
    // -----------------------------------------------------------------
    // SettingsPage empty state
    // -----------------------------------------------------------------

    public function test_settings_page_shows_dev_notice_when_no_domains(): void
    {
        $page = new SettingsPage([], 'https://example.test/wp-admin/admin-post.php', 'nonce-value');

        $html = $page->renderHtml();

        self::assertStringContainsString('No public domain detected', $html);
        self::assertStringContainsString('local or development environment', $html);
        self::assertStringContainsString('DOMAIN_MONITOR_PRIMARY_DOMAIN', $html);
    }

    public function test_settings_page_keeps_add_domain_form_visible_when_no_domains(): void
    {
        $page = new SettingsPage([], 'https://example.test/wp-admin/admin-post.php', 'nonce-value');

        $html = $page->renderHtml();

        self::assertStringContainsString('name="domain_monitor_domain"', $html);
        self::assertStringContainsString('name="action" value="domain_monitor_add_domain"', $html);
    }

    public function test_settings_page_does_not_show_old_empty_message_when_no_domains(): void
    {
        $page = new SettingsPage([], 'https://example.test/wp-admin/admin-post.php', 'nonce-value');

        $html = $page->renderHtml();

        self::assertStringNotContainsString('No domains are monitored yet', $html);
    }

    // -----------------------------------------------------------------
    // DashboardWidget empty state (empty domain string)
    // -----------------------------------------------------------------

    public function test_dashboard_widget_shows_dev_notice_when_domain_is_empty(): void
    {
        $widget = new DashboardWidget('', null, 'https://example.test/wp-admin/admin-post.php', 'nonce');

        $html = $widget->renderHtml();

        self::assertStringContainsString('No public domain detected', $html);
        self::assertStringContainsString('local or development environment', $html);
        self::assertStringContainsString('DOMAIN_MONITOR_PRIMARY_DOMAIN', $html);
    }

    public function test_dashboard_widget_does_not_render_normal_layout_when_domain_is_empty(): void
    {
        $widget = new DashboardWidget('', null, 'https://example.test/wp-admin/admin-post.php', 'nonce');

        $html = $widget->renderHtml();

        self::assertStringNotContainsString('Monitored domain:', $html);
        self::assertStringNotContainsString('Status:', $html);
    }

    public function test_dashboard_widget_renders_normally_when_domain_is_set(): void
    {
        $widget = new DashboardWidget('example.com', null, 'https://example.test/wp-admin/admin-post.php', 'nonce');

        $html = $widget->renderHtml();

        self::assertStringContainsString('Monitored domain:', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringNotContainsString('No public domain detected', $html);
    }
}
