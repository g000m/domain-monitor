<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Alerts\AlertResolver;
use DomainMonitor\Storage\ArrayAlertStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AlertResolver auto-resolution logic.
 *
 * Snapshot structure matches what SnapshotDiffer reads: dns.apex.{a,mx,ns}.
 */
final class AlertResolverTest extends TestCase
{
    private const RESOLVED_AT = '2026-06-11 12:00:00';
    private const DOMAIN_ID   = 1;

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * Build a minimal snapshot with dns.apex containing the given records.
     *
     * @param list<string> $ns
     * @param list<string> $a
     * @param list<string> $mx
     * @return array<string,mixed>
     */
    private function snapshot(array $ns = [], array $a = [], array $mx = []): array
    {
        return [
            'dns' => [
                'apex' => [
                    'ns' => $ns,
                    'a'  => $a,
                    'mx' => $mx,
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // transfer_lock_removed
    // -----------------------------------------------------------------

    public function test_transfer_lock_removed_resolves_when_lock_restored(): void
    {
        $store = new ArrayAlertStore();
        $id    = $store->createAlert(self::DOMAIN_ID, 'transfer_lock_removed', 'Lock was removed.');

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(self::DOMAIN_ID, [], true, self::RESOLVED_AT);

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertEmpty($open, 'Alert must be resolved when lock is restored.');
    }

    public function test_transfer_lock_removed_stays_open_when_lock_still_missing(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'transfer_lock_removed', 'Lock was removed.');

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(self::DOMAIN_ID, [], false, self::RESOLVED_AT);

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'Alert must remain open when lock is still missing.');
    }

    public function test_transfer_lock_null_does_not_resolve(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'transfer_lock_removed', 'Lock was removed.');

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(self::DOMAIN_ID, [], null, self::RESOLVED_AT);

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'null transfer_locked must not resolve the alert.');
    }

    // -----------------------------------------------------------------
    // ns_changed
    // -----------------------------------------------------------------

    public function test_ns_changed_resolves_when_records_revert(): void
    {
        $previous = ['ns1.example.com', 'ns2.example.com'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot($previous),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertEmpty($open, 'ns_changed must resolve when current NS matches previous.');
    }

    public function test_ns_changed_stays_open_when_records_differ(): void
    {
        $previous = ['ns1.example.com', 'ns2.example.com'];
        $current  = ['ns1.attacker.com', 'ns2.attacker.com'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot($current),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'ns_changed must stay open when records still differ.');
    }

    public function test_ns_changed_without_details_stays_open(): void
    {
        $store = new ArrayAlertStore();
        // No details -> cannot determine revert.
        $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', null);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot(['ns1.example.com']),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'Alert with no details must not be auto-resolved.');
    }

    // -----------------------------------------------------------------
    // a_changed
    // -----------------------------------------------------------------

    public function test_a_changed_resolves_when_records_revert(): void
    {
        $previous = ['1.2.3.4'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'a_changed', 'A record changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot([], $previous),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertEmpty($open, 'a_changed must resolve when A records revert.');
    }

    public function test_a_changed_stays_open_when_records_differ(): void
    {
        $previous = ['1.2.3.4'];
        $current  = ['5.6.7.8'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'a_changed', 'A record changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot([], $current),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'a_changed must stay open when records still differ.');
    }

    // -----------------------------------------------------------------
    // mx_changed
    // -----------------------------------------------------------------

    public function test_mx_changed_resolves_when_records_revert(): void
    {
        $previous = ['mail.example.com'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'mx_changed', 'MX changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot([], [], $previous),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertEmpty($open, 'mx_changed must resolve when MX records revert.');
    }

    // -----------------------------------------------------------------
    // Normalisation: order independence
    // -----------------------------------------------------------------

    public function test_ns_changed_resolves_regardless_of_record_order(): void
    {
        // previous stored in one order; current arrives in reverse order.
        $previous = ['ns1.example.com', 'ns2.example.com'];
        $current  = ['ns2.example.com', 'ns1.example.com'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot($current),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertEmpty($open, 'Revert detection must be order-independent.');
    }

    // -----------------------------------------------------------------
    // Multiple alerts
    // -----------------------------------------------------------------

    public function test_only_reverted_alert_is_resolved(): void
    {
        $previousNs = ['ns1.example.com', 'ns2.example.com'];
        $previousA  = ['1.2.3.4'];
        $store      = new ArrayAlertStore();
        $nsId = $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', ['previous' => $previousNs]);
        $store->createAlert(self::DOMAIN_ID, 'a_changed', 'A changed.', ['previous' => $previousA]);

        // NS reverts; A still differs.
        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(
            self::DOMAIN_ID,
            $this->snapshot($previousNs, ['9.9.9.9']),
            null,
            self::RESOLVED_AT
        );

        $open = $store->openAlertsForDomain(self::DOMAIN_ID);
        self::assertCount(1, $open, 'Only the reverted alert must be resolved.');
        self::assertSame('a_changed', $open[0]['type']);
    }

    public function test_does_not_affect_other_domains(): void
    {
        $previous = ['ns1.example.com'];
        $store    = new ArrayAlertStore();
        $store->createAlert(self::DOMAIN_ID, 'ns_changed', 'NS changed.', ['previous' => $previous]);
        $store->createAlert(2, 'ns_changed', 'NS changed on domain 2.', ['previous' => $previous]);

        $resolver = new AlertResolver($store);
        $resolver->resolveReverted(self::DOMAIN_ID, $this->snapshot($previous), null, self::RESOLVED_AT);

        // Domain 1 resolved, domain 2 untouched.
        self::assertEmpty($store->openAlertsForDomain(self::DOMAIN_ID));
        self::assertCount(1, $store->openAlertsForDomain(2));
    }
}
