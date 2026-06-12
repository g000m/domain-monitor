<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\SettingsPage;
use DomainMonitor\Settings\PluginSettings;
use DomainMonitor\Storage\DomainRecord;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the new Notifications and Open alerts sections on SettingsPage.
 */
final class SettingsPageNotificationsTest extends TestCase
{
    private function makePage(
        ?PluginSettings $settings = null,
        array $openAlerts = [],
        string $saveNonce = 'save-nonce',
        string $resolveNonce = 'resolve-nonce'
    ): SettingsPage {
        return new SettingsPage(
            [new DomainRecord(['id' => 1, 'domain' => 'example.com', 'source' => 'auto'])],
            'https://example.test/wp-admin/admin-post.php',
            'check-nonce',
            'add-nonce',
            $saveNonce,
            $resolveNonce,
            $settings,
            $openAlerts
        );
    }

    // -----------------------------------------------------------------
    // Notifications section
    // -----------------------------------------------------------------

    public function test_notifications_form_renders_save_action(): void
    {
        $html = $this->makePage()->renderHtml();
        self::assertStringContainsString('value="domain_monitor_save_settings"', $html);
    }

    public function test_notifications_form_contains_save_nonce(): void
    {
        $html = $this->makePage(null, [], 'my-save-nonce')->renderHtml();
        self::assertStringContainsString('my-save-nonce', $html);
    }

    public function test_all_checkboxes_are_checked_by_default(): void
    {
        $settings = new PluginSettings(); // defaults: all true
        $html     = $this->makePage($settings)->renderHtml();

        self::assertStringContainsString('name="notify_status_change"', $html);
        self::assertStringContainsString('name="notify_ns_changed"', $html);
        self::assertStringContainsString('name="notify_a_changed"', $html);
        self::assertStringContainsString('name="notify_mx_changed"', $html);
        self::assertStringContainsString('name="notify_transfer_lock_removed"', $html);

        // All should appear checked.
        $count = substr_count($html, 'checked');
        self::assertSame(5, $count, 'All 5 checkboxes must be checked when settings default to true.');
    }

    public function test_checkbox_unchecked_when_setting_false(): void
    {
        $settings = new PluginSettings(['notify_ns_changed' => false]);
        $html     = $this->makePage($settings)->renderHtml();

        // Extract the notify_ns_changed input element.
        preg_match('/(<input[^>]*name="notify_ns_changed"[^>]*>)/i', $html, $matches);
        self::assertNotEmpty($matches, 'notify_ns_changed input must be present.');
        self::assertStringNotContainsString('checked', $matches[1]);
    }

    public function test_notification_email_field_populated(): void
    {
        $settings = new PluginSettings(['notification_email' => 'ops@example.com']);
        $html     = $this->makePage($settings)->renderHtml();

        self::assertStringContainsString('ops@example.com', $html);
    }

    // -----------------------------------------------------------------
    // Open alerts section
    // -----------------------------------------------------------------

    public function test_no_open_alerts_shows_empty_state(): void
    {
        $html = $this->makePage(null, [])->renderHtml();
        self::assertStringContainsString('No open alerts', $html);
    }

    public function test_open_alerts_table_rendered_with_alert_data(): void
    {
        $alerts = [
            [
                'id'         => 7,
                'domain_id'  => 1,
                'domain'     => 'example.com',
                'type'       => 'ns_changed',
                'message'    => 'NS record changed from ns1.old.com to ns1.attacker.com.',
                'created_at' => '2026-06-10 09:00:00',
                'resolved_at' => null,
            ],
        ];

        $html = $this->makePage(null, $alerts)->renderHtml();

        self::assertStringContainsString('Nameserver changed', $html);
        self::assertStringContainsString('NS record changed from', $html);
        self::assertStringContainsString('2026-06-10 09:00:00', $html);
        self::assertStringContainsString('example.com', $html);
    }

    public function test_resolve_button_posts_correct_action_and_id(): void
    {
        $alerts = [
            [
                'id'         => 42,
                'domain_id'  => 1,
                'domain'     => 'example.com',
                'type'       => 'a_changed',
                'message'    => 'A record changed.',
                'created_at' => '2026-06-10 09:00:00',
                'resolved_at' => null,
            ],
        ];

        $html = $this->makePage(null, $alerts)->renderHtml();

        self::assertStringContainsString('value="domain_monitor_resolve_alert"', $html);
        self::assertStringContainsString('name="domain_monitor_alert_id" value="42"', $html);
    }

    public function test_resolve_nonce_is_present_in_alerts_form(): void
    {
        $alerts = [
            [
                'id'         => 1,
                'domain_id'  => 1,
                'domain'     => 'example.com',
                'type'       => 'mx_changed',
                'message'    => 'MX changed.',
                'created_at' => '2026-06-10 09:00:00',
                'resolved_at' => null,
            ],
        ];

        $html = $this->makePage(null, $alerts, 'save-nonce', 'my-resolve-nonce')->renderHtml();

        self::assertStringContainsString('my-resolve-nonce', $html);
    }

    public function test_unknown_alert_type_renders_raw_type_safely(): void
    {
        $alerts = [
            [
                'id'         => 1,
                'domain_id'  => 1,
                'domain'     => 'example.com',
                'type'       => 'future_alert_type',
                'message'    => 'Some future alert.',
                'created_at' => '2026-06-10 09:00:00',
                'resolved_at' => null,
            ],
        ];

        $html = $this->makePage(null, $alerts)->renderHtml();
        // Should render the raw type without throwing.
        self::assertStringContainsString('future_alert_type', $html);
    }
}
