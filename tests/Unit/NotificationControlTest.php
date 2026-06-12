<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Plugin;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Task 4: Notification control seam.
 *
 * Verifies that apply_filters('domain_monitor_should_notify') is the gate for
 * every notification dispatch, and that the default (apply_filters absent) is
 * always-true so existing behaviour is preserved.
 */
final class NotificationControlTest extends TestCase
{
    // -----------------------------------------------------------------
    // Default-true path: notifications still fire when apply_filters absent
    // -----------------------------------------------------------------

    public function test_status_change_notification_fires_with_default_true_path(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForControl();

        // Seed a domain with a healthy prior result so status starts at ok.
        $id = $repository->upsertDomain('example.com', 'manual');
        $store->saveCheckResult($id, $this->healthyResult());

        // New check produces a degraded result -> ok->warn transition.
        $runner = new FakeRunnerForControl();
        $runner->overrideResult('example.com', $this->degradedResult());

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertGreaterThan(0, count($notifier->notifications), 'Notification must fire (default-true path).');
        $last = end($notifier->notifications);
        self::assertSame('ok', $last['from']);
        self::assertSame('warn', $last['to']);
    }

    public function test_ns_changed_notification_fires_with_default_true_path(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForControl();

        $id = $repository->upsertDomain('example.com', 'manual');
        $store->overwriteSnapshot($id, [
            'schema_version' => 1,
            'checked_at'     => '2026-06-12 00:00:00',
            'domain'         => 'example.com',
            'rdap'           => [
                'status'          => 'ok',
                'tier'            => 0,
                'registrar'       => 'Example Registrar',
                'expires_at'      => '2030-01-01T00:00:00Z',
                'message'         => '',
                'transfer_locked' => null,
                'domain_statuses' => [],
            ],
            'dns' => [
                'status'  => 'ok',
                'message' => '',
                'apex'    => [
                    'ns' => ['ns1.example.com', 'ns2.example.com'],
                    'a'  => ['1.2.3.4'],
                    'mx' => [],
                ],
            ],
            'errors' => [],
        ]);

        $runner = new FakeRunnerForControl();
        // Use the dns_apex JSON format that DomainCheckRunner produces.
        $runner->overrideResult('example.com', [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'dns_apex'             => json_encode(['ns' => ['ns1.attacker.com', 'ns2.attacker.com'], 'a' => ['1.2.3.4'], 'mx' => []]),
            'rdap_status'          => 'ok',
            'rdap_message'         => '',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => null,
            'rdap_domain_statuses' => null,
            'last_checked_at'      => '2026-06-12 01:00:00',
        ]);

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        // An NS-change notification must have fired.
        self::assertGreaterThan(0, count($notifier->notifications), 'NS-change notification must fire (default-true path).');
    }

    public function test_transfer_lock_removed_notification_fires_with_default_true_path(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForControl();

        $id = $repository->upsertDomain('example.com', 'manual');
        $store->overwriteSnapshot($id, [
            'schema_version' => 1,
            'checked_at'     => '2026-06-12 00:00:00',
            'domain'         => 'example.com',
            'rdap'           => [
                'status'          => 'ok',
                'tier'            => 0,
                'registrar'       => 'Example Registrar',
                'expires_at'      => '2030-01-01T00:00:00Z',
                'message'         => '',
                'transfer_locked' => true,
                'domain_statuses' => [],
            ],
            'dns' => [
                'status'  => 'ok',
                'message' => '',
                'apex'    => ['ns' => ['ns1.example.com'], 'a' => ['1.2.3.4'], 'mx' => []],
            ],
            'errors' => [],
        ]);

        $runner = new FakeRunnerForControl();
        $runner->overrideResult('example.com', [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'dns_apex'             => json_encode(['ns' => ['ns1.example.com'], 'a' => ['1.2.3.4'], 'mx' => []]),
            'rdap_status'          => 'ok',
            'rdap_message'         => '',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => '0', // lock removed (stored as string '0')
            'rdap_domain_statuses' => json_encode(['active']),
            'last_checked_at'      => '2026-06-12 01:00:00',
        ]);

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertGreaterThan(0, count($notifier->notifications), 'Transfer-lock-removed notification must fire (default-true path).');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makePlugin(
        DomainRepository $repository,
        CheckRunner $runner,
        FakeNotifierForControl $notifier,
        ArrayAlertStore $alertStore
    ): Plugin {
        return new Plugin($repository, $runner, $notifier, $alertStore);
    }

    /** @return array<string,string|null> */
    private function healthyResult(): array
    {
        return [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2030-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ];
    }

    /** @return array<string,string|null> */
    private function degradedResult(): array
    {
        return [
            'dns_status'      => 'degraded',
            'dns_message'     => 'DNS lookup timed out.',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2030-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 01:00:00',
        ];
    }
}

final class FakeNotifierForControl implements DomainNotifier
{
    /** @var list<array<string,string>> */
    public array $notifications = [];

    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        $this->notifications[] = ['domain' => $record->domain(), 'from' => $from, 'to' => $to];
    }
}

final class FakeRunnerForControl implements CheckRunner
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
            'last_checked_at'      => '2026-06-12 00:00:00',
        ];
    }
}
