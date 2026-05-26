<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\SettingsPage;
use DomainMonitor\Storage\DomainRecord;
use PHPUnit\Framework\TestCase;

final class SettingsPageTest extends TestCase
{
    public function test_it_renders_domain_management_page_with_add_domain_form(): void
    {
        $page = new SettingsPage(
            [new DomainRecord(['id' => 1, 'domain' => 'example.com', 'source' => 'auto', 'dns_status' => 'unknown', 'rdap_status' => 'unknown'])],
            'https://example.test/wp-admin/admin-post.php',
            'nonce-value'
        );

        $html = $page->renderHtml();

        self::assertStringContainsString('Domain Monitor', $html);
        self::assertStringContainsString('Monitored domains', $html);
        self::assertStringContainsString('example.com', $html);
        self::assertStringContainsString('Auto-detected', $html);
        self::assertStringContainsString('name="domain_monitor_domain"', $html);
        self::assertStringContainsString('name="action" value="domain_monitor_add_domain"', $html);
        self::assertStringContainsString('Add domain', $html);
    }

    public function test_it_renders_dns_and_rdap_results_and_manual_check_button_for_each_domain(): void
    {
        $page = new SettingsPage([
            new DomainRecord([
                'id' => 7,
                'domain' => 'example.net',
                'source' => 'manual',
                'dns_status' => 'ok',
                'dns_message' => 'DNS has A records.',
                'rdap_status' => 'degraded',
                'rdap_message' => 'RDAP lookup failed.',
                'rdap_expires_at' => '2027-01-01T00:00:00Z',
                'last_checked_at' => '2026-05-26 21:30:00',
            ]),
        ], 'https://example.test/wp-admin/admin-post.php', 'nonce-value');

        $html = $page->renderHtml();

        self::assertStringContainsString('example.net', $html);
        self::assertStringContainsString('DNS: OK', $html);
        self::assertStringContainsString('RDAP: DEGRADED', $html);
        self::assertStringContainsString('Domain expires: 2027-01-01', $html);
        self::assertStringContainsString('DNS has A records.', $html);
        self::assertStringContainsString('RDAP lookup failed.', $html);
        self::assertStringContainsString('Last checked: 2026-05-26 21:30:00', $html);
        self::assertStringContainsString('name="action" value="domain_monitor_manual_check"', $html);
        self::assertStringContainsString('name="domain_monitor_domain_id" value="7"', $html);
        self::assertStringContainsString('Run check', $html);
    }

    public function test_it_escapes_domain_rows(): void
    {
        $page = new SettingsPage([
            new DomainRecord(['id' => 1, 'domain' => '<script>alert(1)</script>.example.com', 'source' => 'manual']),
        ], 'https://example.test/wp-admin/admin-post.php', 'nonce-value');

        $html = $page->renderHtml();

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;.example.com', $html);
    }
}
