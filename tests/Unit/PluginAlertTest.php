<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Checks\DnsChecker;
use DomainMonitor\Checks\DomainCheckRunner;
use DomainMonitor\Checks\RdapResult;
use DomainMonitor\Plugin;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use DomainMonitor\Tests\Unit\Support\FakeCheckRunner;
use DomainMonitor\Tests\Unit\Support\FakeNotifier;
use PHPUnit\Framework\TestCase;

/**
 * Tests for alert creation and NS-change notification in the check pipeline.
 *
 * Snapshots are seeded with the dns.apex.{a,mx,ns} structure that SnapshotDiffer
 * reads. Subsequent check results carry 'dns_apex' so snapshotFromResult preserves
 * the rich structure in the new snapshot too.
 */
final class PluginAlertTest extends TestCase
{
    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function makePlugin(
        DomainRepository $repository,
        CheckRunner $runner,
        FakeNotifier $notifier,
        ArrayAlertStore $alertStore
    ): Plugin {
        return new Plugin($repository, $runner, $notifier, $alertStore);
    }

    /**
     * Seed the domain store with a rich DNS snapshot so SnapshotDiffer can
     * detect changes in subsequent checks.
     *
     * @param list<string> $nsRecords
     * @param list<string> $aRecords
     * @param bool|null    $transferLocked
     */
    private function seedDomain(
        ArrayDomainStore $store,
        DomainRepository $repository,
        string $domain,
        array $nsRecords = [],
        array $aRecords = [],
        $transferLocked = null
    ): int {
        $id = $repository->upsertDomain($domain, 'manual');

        $snapshot = [
            'schema_version' => 1,
            'checked_at'     => '2026-06-01 00:00:00',
            'domain'         => $domain,
            'rdap'           => [
                'status'          => 'ok',
                'tier'            => 0,
                'registrar'       => 'Example Registrar',
                'expires_at'      => '2030-01-01T00:00:00Z',
                'message'         => '',
                'transfer_locked' => $transferLocked,
                'domain_statuses' => $transferLocked ? ['clientTransferProhibited'] : [],
            ],
            'dns' => [
                'status'  => 'ok',
                'message' => '',
                'apex'    => [
                    'ns' => $nsRecords,
                    'a'  => $aRecords,
                    'mx' => [],
                ],
            ],
            'errors' => [],
        ];

        $store->overwriteSnapshot($id, $snapshot);

        return $id;
    }

    /**
     * Build a flat check result that includes a dns_apex structure so that
     * snapshotFromResult preserves per-record arrays in the new snapshot.
     *
     * @param list<string> $nsRecords
     * @param list<string> $aRecords
     * @param bool|null    $transferLocked
     * @return array<string,string|null>
     */
    private function resultWithDns(
        array $nsRecords = [],
        array $aRecords = [],
        $transferLocked = null
    ): array {
        return [
            'dns_status'           => 'ok',
            'dns_message'          => '',
            'dns_apex'             => json_encode(['ns' => $nsRecords, 'a' => $aRecords, 'mx' => []]),
            'rdap_status'          => 'ok',
            'rdap_message'         => '',
            'rdap_registrar'       => 'Example Registrar',
            'rdap_expires_at'      => '2030-01-01T00:00:00Z',
            'rdap_transfer_locked' => $transferLocked !== null ? ($transferLocked ? '1' : '0') : null,
            'rdap_domain_statuses' => $transferLocked !== null
                ? json_encode($transferLocked ? ['clientTransferProhibited'] : ['active'])
                : null,
            'last_checked_at'      => gmdate('Y-m-d H:i:s'),
        ];
    }

    // ----------------------------------------------------------------
    // NS change tests
    // ----------------------------------------------------------------

    public function test_ns_change_creates_alert_and_notifies(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com', 'ns2.attacker.com'], ['1.2.3.4']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $alerts = $alertStore->openAlertsForDomain(1);
        $types  = array_column($alerts, 'type');
        self::assertContains('ns_changed', $types, 'Expected an ns_changed alert to be created.');
        self::assertNotEmpty($notifier->notifications, 'NS change should trigger a notification.');
    }

    public function test_a_record_change_creates_alert_without_ns_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        // A record changes but NS stays the same.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['5.6.7.8']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $alerts = $alertStore->openAlertsForDomain(1);
        $types  = array_column($alerts, 'type');
        self::assertContains('a_changed', $types, 'Expected an a_changed alert to be created.');
        self::assertNotContains('ns_changed', $types, 'Should not create ns_changed for an A change.');

        // An A-record change creates an open alert which transitions the effective status
        // from ok to warn, so a status-change notification must fire.
        self::assertNotEmpty($notifier->notifications, 'A-record change creates an open alert which causes ok->warn notification.');
        $notification = $notifier->notifications[0];
        self::assertSame('ok', $notification['from'], 'Previous status must be ok.');
        self::assertSame('warn', $notification['to'], 'New status must be warn due to open alert.');
    }

    public function test_no_change_creates_no_alerts(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $ns = ['ns1.example.com', 'ns2.example.com'];
        $a  = ['1.2.3.4'];
        $this->seedDomain($store, $repository, 'example.com', $ns, $a);

        // Same NS and A records returned.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns($ns, $a));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertEmpty($alertStore->openAlertsForDomain(1), 'No alerts expected when nothing changed.');
    }

    // ----------------------------------------------------------------
    // Transfer lock removal tests
    // ----------------------------------------------------------------

    public function test_transfer_lock_removal_creates_alert_and_notifies(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        // Domain previously had transfer lock ON.
        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], [], true);

        // New check returns transfer lock OFF.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], [], false));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $alerts = $alertStore->openAlertsForDomain(1);
        $types  = array_column($alerts, 'type');
        self::assertContains('transfer_lock_removed', $types, 'Expected transfer_lock_removed alert.');
        self::assertNotEmpty($notifier->notifications, 'Transfer lock removal should notify.');
    }

    public function test_transfer_lock_remaining_creates_no_alert(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], [], true);

        // Transfer lock still on.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], [], true));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $alerts = $alertStore->openAlertsForDomain(1);
        $types  = array_column($alerts, 'type');
        self::assertNotContains('transfer_lock_removed', $types, 'No transfer_lock_removed alert when lock is still on.');
    }

    // ----------------------------------------------------------------
    // Alert status propagation tests
    // ----------------------------------------------------------------

    /**
     * After a check creates an open ns_changed alert, the record's effective
     * status reported by statusCode() (via the same path checkAndRecord uses)
     * must be warn even though the underlying snapshot is fully healthy.
     */
    public function test_open_alert_makes_healthy_snapshot_report_warn(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        // NS changes: alert will be created.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com', 'ns2.attacker.com'], ['1.2.3.4']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        // Confirm the alert exists.
        $alerts = $alertStore->openAlertsForDomain(1);
        self::assertNotEmpty($alerts, 'ns_changed alert must exist after NS change.');

        // Simulate how handleAdminNotices and checkAndRecord resolve the status:
        // fetch the fresh record and pass open alerts into statusCode().
        $fresh = $repository->find(1);
        self::assertNotNull($fresh);
        $rawAlerts = $alertStore->openAlertsForDomain($fresh->id());
        $normalized = array_map(static function (array $row): array {
            $row['is_active'] = ($row['resolved_at'] ?? null) === null;
            $row['severity']  = $row['severity'] ?? 'warn';
            return $row;
        }, $rawAlerts);

        self::assertSame(
            'warn',
            $fresh->statusCode($normalized),
            'Effective status must be warn when an open alert exists, even with a healthy snapshot.'
        );
    }

    /**
     * A domain with no open alerts and a healthy snapshot must stay ok.
     */
    public function test_no_alerts_healthy_snapshot_stays_ok(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $ns = ['ns1.example.com', 'ns2.example.com'];
        $a  = ['1.2.3.4'];
        $this->seedDomain($store, $repository, 'example.com', $ns, $a);

        // No change -- no alert created.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns($ns, $a));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertEmpty($alertStore->openAlertsForDomain(1));

        $fresh = $repository->find(1);
        self::assertNotNull($fresh);
        self::assertSame('ok', $fresh->statusCode([]), 'No alerts + healthy snapshot must be ok.');
    }

    /**
     * An NS change on a healthy domain transitions from ok to warn (notifies).
     * A subsequent check with no further NS change sees warn -> warn, which is
     * NOT a worsening, so the notifier must NOT fire a second time.
     */
    public function test_ns_change_does_not_re_notify_on_second_check(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        // First check: NS changes -> alert created, status ok -> warn -> notifier fires.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com', 'ns2.attacker.com'], ['1.2.3.4']));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $firstNotifyCount = count($notifier->notifications);
        self::assertGreaterThan(0, $firstNotifyCount, 'First check must trigger a notification.');

        // Second check: same NS (attacker), no further change -> warn -> warn, no new notify.
        $plugin->handleDailyCheck();

        self::assertCount(
            $firstNotifyCount,
            $notifier->notifications,
            'Second check with same status must not re-notify (warn->warn is not a worsening).'
        );
    }

    // ----------------------------------------------------------------
    // End-to-end: real DnsChecker + DomainCheckRunner -> Plugin alert
    // ----------------------------------------------------------------

    /**
     * Proves the full production chain:
     * real DnsChecker (fake resolver) -> DomainCheckRunner -> dns_apex in flat result
     * -> snapshotFromResult builds snapshot with dns.apex.ns -> SnapshotDiffer detects
     * change -> Plugin creates ns_changed alert -> notifier fires.
     */
    public function test_full_chain_ns_change_creates_alert_via_real_check_runner(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        // Seed old snapshot with NS set A.
        $id = $repository->upsertDomain('example.com', 'manual');
        $store->overwriteSnapshot($id, [
            'schema_version' => 1,
            'checked_at'     => '2026-06-01 00:00:00',
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

        // Second check: NS has changed to attacker nameservers.
        $fakeResolver = new FakeDnsResolverForPlugin([
            'A'    => [['ip' => '1.2.3.4']],
            'AAAA' => [],
            'NS'   => [['target' => 'ns1.attacker.com'], ['target' => 'ns2.attacker.com']],
            'MX'   => [],
        ]);
        $fakeRdap = new FakeRdapCheckerForPlugin(
            new RdapResult('ok', '2030-01-01T00:00:00Z', 'Example Registrar', 'RDAP ok.')
        );
        $runner = new DomainCheckRunner(
            new DnsChecker($fakeResolver),
            $fakeRdap,
            static fn (): string => '2026-06-02 00:00:00'
        );

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $alerts = $alertStore->openAlertsForDomain($id);
        $types  = array_column($alerts, 'type');
        self::assertContains('ns_changed', $types, 'ns_changed alert must be created by the real check pipeline.');
        self::assertNotEmpty($notifier->notifications, 'NS change must trigger a notification via the real pipeline.');
    }

    // ----------------------------------------------------------------
    // Bug 3 regression: baseline guard for old snapshots lacking dns.apex
    // ----------------------------------------------------------------

    /**
     * An old snapshot that has a dns section but no apex sub-array (written by
     * an older plugin version before DNS record capture existed) must not trigger
     * bogus "added" alerts when the first rich snapshot arrives. The new rich
     * snapshot simply becomes the reference baseline.
     */
    public function test_old_snapshot_without_apex_produces_no_alerts(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        // Seed a legacy-style snapshot: dns section present but no apex key.
        $id = $repository->upsertDomain('example.com', 'manual');
        $store->overwriteSnapshot($id, [
            'schema_version' => 1,
            'checked_at'     => '2026-01-01 00:00:00',
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
                // No 'apex' key -- simulates a snapshot from older plugin code.
            ],
            'errors' => [],
        ]);

        // New rich check result arrives with full apex records.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(
            ['ns1.example.com', 'ns2.example.com'],
            ['1.2.3.4']
        ));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertEmpty(
            $alertStore->openAlertsForDomain($id),
            'No alerts expected: old snapshot lacked apex, so the first rich snapshot is the baseline.'
        );
        self::assertEmpty(
            $notifier->notifications,
            'No notifications expected when old snapshot has no apex reference.'
        );
    }

    /**
     * Two fully-rich snapshots where NS actually changed must still produce an
     * alert (the baseline guard must not suppress genuine changes).
     */
    public function test_two_rich_snapshots_with_ns_change_still_alerts(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifier();

        // Seed a rich old snapshot with known NS records.
        $id = $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com', 'ns2.example.com'], ['1.2.3.4']);

        // New check result shows different NS records.
        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->resultWithDns(
            ['ns1.attacker.com', 'ns2.attacker.com'],
            ['1.2.3.4']
        ));

        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        $types = array_column($alertStore->openAlertsForDomain($id), 'type');
        self::assertContains('ns_changed', $types, 'A genuine NS change between two rich snapshots must create an alert.');
        self::assertNotEmpty($notifier->notifications, 'A genuine NS change must trigger a notification.');
    }
}

// ---------------------------------------------------------------------------
// Local fakes for the end-to-end test above
// ---------------------------------------------------------------------------

final class FakeDnsResolverForPlugin
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $records;

    /** @param array<string,list<array<string,mixed>>> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    /** @return list<array<string,mixed>> */
    public function records(string $domain, string $type): array
    {
        return $this->records[$type] ?? [];
    }
}

final class FakeRdapCheckerForPlugin
{
    private RdapResult $result;

    public function __construct(RdapResult $result)
    {
        $this->result = $result;
    }

    public function check(string $domain): RdapResult
    {
        return $this->result;
    }
}
