<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DashboardWidget;
use PHPUnit\Framework\TestCase;

final class DashboardWidgetTest extends TestCase
{
    public function test_it_renders_visible_domain_monitor_summary(): void
    {
        $widget = new DashboardWidget('example.com');

        $html = $widget->renderHtml();

        self::assertStringContainsString('Domain Monitor', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('Status: Not checked yet', $html);
        self::assertStringContainsString('Run first check', $html);
    }

    public function test_it_escapes_the_domain_in_widget_output(): void
    {
        $widget = new DashboardWidget('<script>alert(1)</script>.example.com');

        $html = $widget->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.example.com', $html);
    }
}
