<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Settings\PluginSettings;
use PHPUnit\Framework\TestCase;

final class PluginSettingsTest extends TestCase
{
    public function test_defaults_are_all_true_and_empty_email(): void
    {
        $settings = new PluginSettings();

        self::assertTrue($settings->notifyStatusChange());
        self::assertTrue($settings->notifyNsChanged());
        self::assertTrue($settings->notifyAChanged());
        self::assertTrue($settings->notifyMxChanged());
        self::assertTrue($settings->notifyTransferLockRemoved());
        self::assertSame('', $settings->notificationEmail());
    }

    public function test_partial_raw_defaults_missing_keys_to_true(): void
    {
        // Only one key supplied; all others should default to true.
        $settings = new PluginSettings(['notify_status_change' => false]);

        self::assertFalse($settings->notifyStatusChange());
        self::assertTrue($settings->notifyNsChanged());
        self::assertTrue($settings->notifyAChanged());
        self::assertTrue($settings->notifyMxChanged());
        self::assertTrue($settings->notifyTransferLockRemoved());
    }

    public function test_all_keys_set_to_false(): void
    {
        $settings = new PluginSettings([
            'notify_status_change'        => false,
            'notify_ns_changed'           => false,
            'notify_a_changed'            => false,
            'notify_mx_changed'           => false,
            'notify_transfer_lock_removed' => false,
            'notification_email'          => '',
        ]);

        self::assertFalse($settings->notifyStatusChange());
        self::assertFalse($settings->notifyNsChanged());
        self::assertFalse($settings->notifyAChanged());
        self::assertFalse($settings->notifyMxChanged());
        self::assertFalse($settings->notifyTransferLockRemoved());
    }

    public function test_notification_email_stored_and_returned(): void
    {
        $settings = new PluginSettings(['notification_email' => 'test@example.com']);

        self::assertSame('test@example.com', $settings->notificationEmail());
    }

    public function test_non_string_notification_email_defaults_to_empty(): void
    {
        $settings = new PluginSettings(['notification_email' => 42]);

        self::assertSame('', $settings->notificationEmail());
    }

    public function test_to_array_round_trips(): void
    {
        $raw = [
            'notify_status_change'        => false,
            'notify_ns_changed'           => true,
            'notify_a_changed'            => false,
            'notify_mx_changed'           => true,
            'notify_transfer_lock_removed' => false,
            'notification_email'          => 'ops@example.com',
            'email_dns_check_enabled'     => false,
        ];

        $settings = new PluginSettings($raw);

        self::assertSame($raw, $settings->toArray());
    }

    public function test_from_wordpress_returns_defaults_when_get_option_unavailable(): void
    {
        // get_option is not defined in the test environment.
        $settings = PluginSettings::fromWordPress();

        self::assertTrue($settings->notifyStatusChange());
        self::assertSame('', $settings->notificationEmail());
    }
}
