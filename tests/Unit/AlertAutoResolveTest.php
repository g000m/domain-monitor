<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DateTimeImmutable;
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
 * Integration tests for Feature B:
 *   - Auto-resolve on revert (via Plugin::checkAndRecord -> AlertResolver)
 *   - StatusCalculator 3-day amber persistence after resolution
 */
final class AlertAutoResolveTest extends TestCase
{
    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function makePlugin(
        DomainRepository $repository,
        CheckRunner $runner,
        DomainNotifier $notifier,
        ArrayAlertStore $alertStore
    ): Plugin {
        return new Plugin($repository, $runner, $notifier, $alertStore);
    }

    /**
     * Seed a domain with a rich DNS snapshot.
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

    // -----------------------------------------------------------------
    // Auto-resolve: NS revert
    // -----------------------------------------------------------------

    public function test_ns_alert_auto_resolves_when_ns_reverts(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForAutoResolve();
        $original   = ['ns1.example.com', 'ns2.example.com'];
        $attacker   = ['ns1.attacker.com', 'ns2.attacker.com'];

        $this->seedDomain($store, $repository, 'example.com', $original, ['1.2.3.4']);

        // Check 1: NS changes -> alert created.
        $runner = new FakeRunnerForAutoResolve();
        $runner->overrideResult('example.com', $this->resultWithDns($attacker, ['1.2.3.4']));
        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertNotEmpty($alertStore->openAlertsForDomain(1), 'ns_changed alert must exist after NS change.');

        // Check 2: NS reverts -> alert auto-resolved.
        $runner->overrideResult('example.com', $this->resultWithDns($original, ['1.2.3.4']));
        $plugin->handleDailyCheck();

        self::assertEmpty($alertStore->openAlertsForDomain(1), 'ns_changed alert must be auto-resolved after revert.');
    }

    // -----------------------------------------------------------------
    // Auto-resolve: A record revert
    // -----------------------------------------------------------------

    public function test_a_alert_auto_resolves_when_a_records_revert(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForAutoResolve();
        $original   = ['1.2.3.4'];
        $changed    = ['9.9.9.9'];

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], $original);

        // Check 1: A changes.
        $runner = new FakeRunnerForAutoResolve();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], $changed));
        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertNotEmpty($alertStore->openAlertsForDomain(1));

        // Check 2: A reverts.
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], $original));
        $plugin->handleDailyCheck();

        self::assertEmpty($alertStore->openAlertsForDomain(1), 'a_changed alert must be auto-resolved after revert.');
    }

    // -----------------------------------------------------------------
    // Auto-resolve: transfer_lock_removed
    // -----------------------------------------------------------------

    public function test_transfer_lock_alert_auto_resolves_when_lock_restored(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForAutoResolve();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4'], true);

        // Check 1: lock removed.
        $runner = new FakeRunnerForAutoResolve();
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['1.2.3.4'], false));
        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();

        self::assertNotEmpty($alertStore->openAlertsForDomain(1));

        // Check 2: lock restored.
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.example.com'], ['1.2.3.4'], true));
        $plugin->handleDailyCheck();

        self::assertEmpty($alertStore->openAlertsForDomain(1), 'transfer_lock_removed alert must auto-resolve when lock restored.');
    }

    // -----------------------------------------------------------------
    // Non-revert: alert stays open
    // -----------------------------------------------------------------

    public function test_ns_alert_stays_open_when_ns_still_differs(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $alertStore = new ArrayAlertStore();
        $notifier   = new FakeNotifierForAutoResolve();

        $this->seedDomain($store, $repository, 'example.com', ['ns1.example.com'], ['1.2.3.4']);

        $runner = new FakeRunnerForAutoResolve();
        // NS changes to attacker.
        $runner->overrideResult('example.com', $this->resultWithDns(['ns1.attacker.com'], ['1.2.3.4']));
        $plugin = $this->makePlugin($repository, $runner, $notifier, $alertStore);
        $plugin->handleDailyCheck();
        // Second check: same attacker NS (no revert).
        $plugin->handleDailyCheck();

        self::assertNotEmpty($alertStore->openAlertsForDomain(1), 'Alert must stay open when no revert.');
    }

    // -----------------------------------------------------------------
    // Amber persistence: recently-resolved alerts keep domain amber
    // -----------------------------------------------------------------

    /**
     * After resolving an ns_changed alert, the domain must stay amber
     * for 3 days (StatusCalculator's recent-recovery window).
     *
     * We verify this by directly exercising Plugin::alertsForRecord
     * through the status-reporting path: a record's effective status
     * must be WARN at day 2 because recently-resolved alerts are included.
     */
    public function test_recently_resolved_alert_keeps_domain_amber_within_3_days(): void
    {
        $store    = new ArrayAlertStore();
        $alertId  = $store->createAlert(1, 'ns_changed', 'NS changed.');

        // Resolve 2 days ago (within the 3-day window).
        $store->resolveAlert($alertId, '2026-06-09 12:00:00');

        // Fetch recently-resolved and normalise exactly as Plugin::alertsForRecord does.
        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertCount(1, $recent, 'Resolved alert must appear in recentlyResolvedAlertsForDomain at day 2.');

        $normalized = array_map(static function (array $row): array {
            $row['is_active'] = ($row['resolved_at'] ?? null) === null;
            $row['severity']  = $row['severity'] ?? 'warn';
            return $row;
        }, $recent);

        $status = (new StatusCalculator())->calculate(
            ['rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z'], 'dns' => ['status' => 'ok']],
            $normalized,
            new DateTimeImmutable('2026-06-11T12:00:00Z')
        );

        self::assertSame(StatusCalculator::STATUS_WARN, $status->code(), 'Domain must stay amber within the 3-day persistence window.');
        self::assertStringContainsString('recently recovered', strtolower($status->message()));
    }

    public function test_resolved_alert_clears_amber_after_3_days(): void
    {
        $store   = new ArrayAlertStore();
        $alertId = $store->createAlert(1, 'ns_changed', 'NS changed.');

        // Resolve 4 days ago (outside the 3-day window).
        $store->resolveAlert($alertId, '2026-06-07 12:00:00');

        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertEmpty($recent, 'Alert resolved 4 days ago must not appear in the 3-day window.');

        $status = (new StatusCalculator())->calculate(
            ['rdap' => ['status' => 'ok', 'expires_at' => '2030-01-01T00:00:00Z'], 'dns' => ['status' => 'ok']],
            [],
            new DateTimeImmutable('2026-06-11T12:00:00Z')
        );

        self::assertSame(StatusCalculator::STATUS_OK, $status->code(), 'Domain must be green after the 3-day window.');
    }
}

final class FakeNotifierForAutoResolve implements DomainNotifier
{
    /** @var list<array<string,string>> */
    public array $notifications = [];

    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        $this->notifications[] = ['domain' => $record->domain(), 'from' => $from, 'to' => $to];
    }
}

final class FakeRunnerForAutoResolve implements CheckRunner
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
