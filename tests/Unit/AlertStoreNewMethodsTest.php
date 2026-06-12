<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\ArrayAlertStore;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the two methods added to AlertStore in Feature B:
 *   - resolveAlert(int $alertId, string $resolvedAt)
 *   - recentlyResolvedAlertsForDomain(int $domainId, int $withinDays, string $now)
 */
final class AlertStoreNewMethodsTest extends TestCase
{
    // -----------------------------------------------------------------
    // resolveAlert
    // -----------------------------------------------------------------

    public function test_resolve_alert_marks_single_row_resolved(): void
    {
        $store = new ArrayAlertStore();
        $id1   = $store->createAlert(1, 'ns_changed', 'NS changed.');
        $id2   = $store->createAlert(1, 'a_changed', 'A changed.');

        $store->resolveAlert($id1, '2026-06-11 12:00:00');

        $open = $store->openAlertsForDomain(1);
        self::assertCount(1, $open);
        self::assertSame('a_changed', $open[0]['type']);
    }

    public function test_resolve_alert_is_idempotent(): void
    {
        $store = new ArrayAlertStore();
        $id    = $store->createAlert(1, 'ns_changed', 'NS changed.');

        $store->resolveAlert($id, '2026-06-11 12:00:00');
        // Second call must not throw or change resolved_at.
        $store->resolveAlert($id, '2026-06-12 00:00:00');

        self::assertEmpty($store->openAlertsForDomain(1));
    }

    public function test_resolve_alert_for_nonexistent_id_is_safe(): void
    {
        $store = new ArrayAlertStore();
        // Must not throw for an id that does not exist.
        $store->resolveAlert(999, '2026-06-11 12:00:00');
        $this->addToAssertionCount(1);
    }

    // -----------------------------------------------------------------
    // recentlyResolvedAlertsForDomain
    // -----------------------------------------------------------------

    public function test_recently_resolved_returns_alerts_resolved_within_window(): void
    {
        $store = new ArrayAlertStore();
        $id    = $store->createAlert(1, 'ns_changed', 'NS changed.');

        // Resolve 1 day ago; now is 2026-06-11.
        $store->resolveAlert($id, '2026-06-10 12:00:00');

        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertCount(1, $recent);
    }

    public function test_recently_resolved_excludes_alerts_outside_window(): void
    {
        $store = new ArrayAlertStore();
        $id    = $store->createAlert(1, 'ns_changed', 'NS changed.');

        // Resolved 5 days ago; window is 3 days.
        $store->resolveAlert($id, '2026-06-06 12:00:00');

        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertEmpty($recent);
    }

    public function test_recently_resolved_excludes_open_alerts(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(1, 'ns_changed', 'NS changed.');

        // No resolve call; alert is still open.
        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertEmpty($recent);
    }

    public function test_recently_resolved_excludes_other_domains(): void
    {
        $store = new ArrayAlertStore();
        $id1   = $store->createAlert(1, 'ns_changed', 'NS changed on 1.');
        $id2   = $store->createAlert(2, 'ns_changed', 'NS changed on 2.');

        $store->resolveAlert($id1, '2026-06-10 12:00:00');
        $store->resolveAlert($id2, '2026-06-10 12:00:00');

        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertCount(1, $recent);
        self::assertSame(1, (int) $recent[0]['domain_id']);
    }

    public function test_recently_resolved_at_exact_boundary_is_included(): void
    {
        $store = new ArrayAlertStore();
        $id    = $store->createAlert(1, 'ns_changed', 'NS changed.');

        // Resolved exactly 3 days ago: 2026-06-08 12:00:00, now = 2026-06-11 12:00:00.
        $store->resolveAlert($id, '2026-06-08 12:00:00');

        $recent = $store->recentlyResolvedAlertsForDomain(1, 3, '2026-06-11 12:00:00');
        self::assertCount(1, $recent, 'An alert resolved exactly at the boundary must be included.');
    }
}
