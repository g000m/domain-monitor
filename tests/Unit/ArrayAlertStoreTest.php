<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Storage\ArrayAlertStore;
use PHPUnit\Framework\TestCase;

final class ArrayAlertStoreTest extends TestCase
{
    public function test_create_alert_returns_incrementing_id(): void
    {
        $store = new ArrayAlertStore();

        $id1 = $store->createAlert(1, 'ns_changed', 'NS record changed.');
        $id2 = $store->createAlert(1, 'a_changed', 'A record changed.');

        self::assertSame(1, $id1);
        self::assertSame(2, $id2);
    }

    public function test_open_alerts_for_domain_returns_unresolved(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(1, 'ns_changed', 'NS changed.');
        $store->createAlert(2, 'a_changed', 'A changed for domain 2.');

        $open = $store->openAlertsForDomain(1);

        self::assertCount(1, $open);
        self::assertSame('ns_changed', $open[0]['type']);
        self::assertSame('NS changed.', $open[0]['message']);
        self::assertNull($open[0]['resolved_at']);
    }

    public function test_resolve_alerts_for_domain_marks_matching_type_resolved(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(1, 'ns_changed', 'NS changed.');
        $store->createAlert(1, 'a_changed', 'A changed.');

        $store->resolveAlertsForDomain(1, 'ns_changed', '2026-06-01 00:00:00');

        $open = $store->openAlertsForDomain(1);

        self::assertCount(1, $open);
        self::assertSame('a_changed', $open[0]['type']);
    }

    public function test_resolve_does_not_affect_other_domains(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(1, 'ns_changed', 'NS changed.');
        $store->createAlert(2, 'ns_changed', 'NS changed on domain 2.');

        $store->resolveAlertsForDomain(1, 'ns_changed', '2026-06-01 00:00:00');

        // Domain 2 should still have an open alert.
        $open2 = $store->openAlertsForDomain(2);
        self::assertCount(1, $open2);
    }

    public function test_create_alert_stores_json_details(): void
    {
        $store = new ArrayAlertStore();
        $store->createAlert(1, 'ns_changed', 'NS changed.', ['old' => ['ns1.example.com'], 'new' => ['ns2.attacker.com']]);

        $open = $store->openAlertsForDomain(1);

        self::assertCount(1, $open);
        $details = json_decode((string) $open[0]['details'], true);
        self::assertIsArray($details);
        self::assertArrayHasKey('old', $details);
    }
}
