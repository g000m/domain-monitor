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

    public function test_add_domain_form_uses_separate_nonce_from_manual_check_form(): void
    {
        $page = new SettingsPage(
            [new DomainRecord(['id' => 3, 'domain' => 'example.com', 'source' => 'auto'])],
            'https://example.test/wp-admin/admin-post.php',
            'manual-check-nonce',
            'add-domain-nonce'
        );

        $html = $page->renderHtml();

        // The add-domain form must carry the add-domain nonce.
        self::assertStringContainsString('name="action" value="domain_monitor_add_domain"', $html);
        // The manual-check form must carry the manual-check nonce.
        self::assertStringContainsString('name="action" value="domain_monitor_manual_check"', $html);

        // Both nonce values must appear somewhere in the output.
        self::assertStringContainsString('add-domain-nonce', $html);
        self::assertStringContainsString('manual-check-nonce', $html);
    }

    public function test_add_domain_form_nonce_is_placed_in_add_domain_form_not_manual_check_form(): void
    {
        $page = new SettingsPage(
            [new DomainRecord(['id' => 5, 'domain' => 'example.org', 'source' => 'manual'])],
            'https://example.test/wp-admin/admin-post.php',
            'nonce-for-check',
            'nonce-for-add'
        );

        $html = $page->renderHtml();

        // Split on the two form actions to isolate each form's HTML.
        $addFormStart = strpos($html, 'value="domain_monitor_add_domain"');
        $checkFormStart = strpos($html, 'value="domain_monitor_manual_check"');

        self::assertNotFalse($addFormStart);
        self::assertNotFalse($checkFormStart);

        // Grab the section around the add-domain action tag (200 chars before and after).
        $addFormContext = substr($html, max(0, $addFormStart - 200), 400);
        self::assertStringContainsString('nonce-for-add', $addFormContext);
        self::assertStringNotContainsString('nonce-for-check', $addFormContext);
    }
}
