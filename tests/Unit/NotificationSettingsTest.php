<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Notifications\AdminNotifier;
use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Plugin;
use DomainMonitor\Settings\PluginSettings;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Feature A: notification settings gate in Plugin::shouldNotify.
 *
 * All tests run with apply_filters absent (unit-test environment) so we test
 * the settings layer directly.
 */
final class NotificationSettingsTest extends TestCase
{
    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makePlugin(
        DomainRepository $repository,
        CheckRunner $runner,
        DomainNotifier $notifier,
        ArrayAlertStore $alertStore,
        PluginSettings $settings
    ): Plugin {
        return new Plugin($repository, $runner, $notifier, $alertStore, $settings);
    }

    /**
     * Seed a domain with a rich snapshot so SnapshotDiffer can detect changes.
     *
     * @param list<string> $ns
     * @param list<string> $a
     */
    private function seedDomain(
        ArrayDomainStore $store,
        DomainRepository $repository,
        string $domain,
        array $ns,
        array $a,
        bool $transferLocked = false
    ): int {
        $id = $repository->upsertDomain($domain, 'manual');
        $store->overwriteSnapshot($id, [
            'schema_version' => 1,
            'checked_at'     => '2026-06-11 00:00:00',
            'domain'         => $domain,
            'rdap'           => [
                'status'          => 'ok',
                'tier'            => 0,
                'registrar'       => 'Example Registrar',
                'expires_at'      => '2030-01-01T00:00:00Z',
                'message'         => '',
                'transfer_locked' => $transferLocked,
                'domain_statuses' => [],
            ],
            'dns' => [
                'status'  => 'ok',
                'message' => '',
                'apex'    => ['ns' => $ns, 'a' => $a, 'mx' => []],
            ],
            'errors' => [],
        ]);
        return $id;
    }

    /** @return array<string,string|null> */
    private function resultWithDns(array $ns, array $a, ?bool $transferLocked = null): array
    {
        $locked = $transferLocked === null ? null : ($transferLocked ? '1' : '0');
        return [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'dns_apex'             => json_encode(['ns' => $ns, 'a' => $a, 'mx' => []]),
            'rdap_status'          => 'ok',
            'rdap_message'         => '',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => $locked,
            'rdap_domain_statuses' => json_encode(['active']),
            'last_checked_at'      => '2026-06-11 01:00:00',
        ];
    }

    /**
     * A result where RDAP degrades (status ok->warn) without touching DNS records.
     * Passes dns_apex matching the seeded NS/A records so SnapshotDiffer sees no change
     * and no ns_changed/a_changed alerts are created. Only status_change fires.
     *
     * @return array<string,string|null>
     */
    private function degradedResult(): array
    {
        return [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'dns_apex'             => json_encode(['ns' => ['ns1.example.com'], 'a' => ['1.2.3.4'], 'mx' => []]),
            'rdap_status'          => 'degraded',
            'rdap_message'         => 'RDAP lookup failed.',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => null,
            'rdap_domain_statuses' => null,
            'last_checked_at'      => '2026-06-11 01:00:00',
        ];
    }

    // -----------------------------------------------------------------
    // notify_status_change = false suppresses status_change notifications
    // -----------------------------------------------------------------

    public function test_status_change_notification_suppressed_when_setting_false(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        $settings   = new PluginSettings(['notify_status_change' => false]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->degradedResult());

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertEmpty(
            $notifier->notifications,
            'status_change notification must not fire when notify_status_change is false.'
        );
    }

    public function test_status_change_notification_fires_when_setting_true(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        $settings   = new PluginSettings(['notify_status_change' => true]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->degradedResult());

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertNotEmpty(
            $notifier->notifications,
            'status_change notification must fire when notify_status_change is true.'
        );
    }

    // -----------------------------------------------------------------
    // notify_ns_changed = false suppresses ns_changed notifications
    // -----------------------------------------------------------------

    public function test_ns_changed_notification_suppressed_when_setting_false(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        // Disable both ns_changed and status_change so we confirm the ns_changed gate works.
        // (An NS change also triggers a status ok->warn worsening, so we disable status_change
        //  too and assert that neither fires.)
        $settings   = new PluginSettings(['notify_ns_changed' => false, 'notify_status_change' => false]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com', 'ns2.attacker.com'], ['1.2.3.4']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertEmpty(
            $notifier->notifications,
            'No notification must fire when both notify_ns_changed and notify_status_change are false.'
        );
    }

    public function test_ns_changed_notification_fires_independently_when_enabled(): void
    {
        // Confirm ns_changed notification fires when only ns_changed is enabled.
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        $settings   = new PluginSettings(['notify_ns_changed' => true, 'notify_status_change' => false]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com', 'ns2.attacker.com'], ['1.2.3.4']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertNotEmpty(
            $notifier->notifications,
            'ns_changed notification must fire when notify_ns_changed is true.'
        );
    }

    // -----------------------------------------------------------------
    // notify_transfer_lock_removed = false suppresses that notification
    // -----------------------------------------------------------------

    public function test_transfer_lock_removed_suppressed_when_setting_false(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        // Disable both transfer_lock_removed and status_change: a lock removal also
        // triggers a status_change worsening, so both must be off to assert no fires.
        $settings   = new PluginSettings(['notify_transfer_lock_removed' => false, 'notify_status_change' => false]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4'], true);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['1.2.3.4'], false));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertEmpty(
            $notifier->notifications,
            'No notification must fire when both notify_transfer_lock_removed and notify_status_change are false.'
        );
    }

    // -----------------------------------------------------------------
    // a_changed / mx_changed attribution via status_change path
    // -----------------------------------------------------------------

    public function test_a_changed_status_change_suppressed_when_notify_a_changed_false(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        // a_changed off; status_change on (should not matter for this path).
        $settings   = new PluginSettings(['notify_a_changed' => false, 'notify_status_change' => true]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        // A record changes, NS stays same -> only a_changed alert created -> status ok->warn.
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['9.9.9.9']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertEmpty(
            $notifier->notifications,
            'status_change notification must not fire when worsening is a_changed-only and notify_a_changed is false.'
        );
    }

    public function test_a_changed_status_change_fires_when_notify_a_changed_true(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        $settings   = new PluginSettings(['notify_a_changed' => true]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['9.9.9.9']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        self::assertNotEmpty(
            $notifier->notifications,
            'status_change notification must fire when a_changed alert present and notify_a_changed is true.'
        );
    }

    public function test_mixed_a_and_ns_alerts_fires_when_ns_enabled(): void
    {
        // Both a_changed and ns_changed alerts open; ns_changed enabled, a_changed disabled.
        // The presence of a non-a/mx type means notify_status_change governs for the
        // status_change path, not the a/mx attribution path.
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForSettings();
        $settings   = new PluginSettings([
            'notify_ns_changed'    => true,
            'notify_a_changed'     => false,
            'notify_status_change' => true,
        ]);

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForSettings();
        // Both NS and A change in the same check.
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com'], ['9.9.9.9']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore, $settings);
        $plugin->handleDailyCheck();

        // ns_changed direct notification must fire; status_change path:
        // ns_changed is in open alerts so notify_status_change governs -> true -> fires.
        self::assertNotEmpty($notifier->notifications, 'At least the ns_changed notification must fire.');
    }

    // -----------------------------------------------------------------
    // AdminNotifier uses notification_email when set
    // -----------------------------------------------------------------

    public function test_admin_notifier_compose_is_unaffected_by_settings(): void
    {
        // AdminNotifier::composeMessage is a pure function; settings do not change the message content.
        $settings = new PluginSettings(['notification_email' => 'ops@example.com']);
        $notifier = new AdminNotifier($settings);
        $record   = new DomainRecord(['id' => 1, 'domain' => 'example.com', 'source' => 'auto']);

        ['subject' => $subject] = $notifier->composeMessage($record, 'ok', 'warn');

        self::assertStringContainsString('example.com', $subject);
    }
}

final class FakeNotifierForSettings implements DomainNotifier
{
    /** @var list<array<string,string>> */
    public array $notifications = [];

    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        $this->notifications[] = ['domain' => $record->domain(), 'from' => $from, 'to' => $to];
    }
}

final class FakeRunnerForSettings implements CheckRunner
{
    /** @var array<string, array<string,string|null>> */
    private array $overrides = [];

    /** @param array<string,string|null> $result */
    public function overrideResult(string $domain, array $result): void
    {
        $this->overrides[$domain] = $result;
    }

    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        if (isset($this->overrides[$domain])) {
            return $this->overrides[$domain];
        }

        return [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'rdap_status'          => 'ok',
            'rdap_message'         => '',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => null,
            'rdap_domain_statuses' => null,
            'last_checked_at'      => '2026-06-11 00:00:00',
        ];
    }
}
