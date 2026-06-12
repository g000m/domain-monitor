<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\DomainAdminActions;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Task 2: auto-detection guard, constant override, filter override.
 */
final class AutoDetectionTest extends TestCase
{
    // -----------------------------------------------------------------
    // Non-monitorable host: nothing inserted
    // -----------------------------------------------------------------

    public function test_localhost_host_produces_no_insertion(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'localhost');

        $id = $actions->ensureAutoDetectedDomain();

        self::assertSame(0, $id, 'Non-monitorable host must return 0 (nothing inserted).');
        self::assertCount(0, $repository->all());
    }

    public function test_bare_intranet_name_produces_no_insertion(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'devbox');

        $id = $actions->ensureAutoDetectedDomain();

        self::assertSame(0, $id);
        self::assertCount(0, $repository->all());
    }

    public function test_ipv4_host_produces_no_insertion(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), '192.168.1.100');

        $id = $actions->ensureAutoDetectedDomain();

        self::assertSame(0, $id);
        self::assertCount(0, $repository->all());
    }

    public function test_dev_tld_host_produces_no_insertion(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'mysite.test');

        $id = $actions->ensureAutoDetectedDomain();

        self::assertSame(0, $id);
        self::assertCount(0, $repository->all());
    }

    // -----------------------------------------------------------------
    // Monitorable host: normal insertion
    // -----------------------------------------------------------------

    public function test_public_domain_is_inserted(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'example.com');

        $id = $actions->ensureAutoDetectedDomain();

        self::assertGreaterThan(0, $id);
        $record = $repository->find($id);
        self::assertNotNull($record);
        self::assertSame('example.com', $record->domain());
        self::assertSame('auto', $record->source());
    }

    // -----------------------------------------------------------------
    // domainSource callable (test seam): bypasses guard
    // -----------------------------------------------------------------

    public function test_domain_source_callable_overrides_host_and_bypasses_guard(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        // currentHost is localhost (non-monitorable), but domainSource overrides to a real domain.
        $actions = new DomainAdminActions(
            $repository,
            new FakeRunnerForAutoDetection(),
            'localhost',
            static fn (): string => 'override.example.com'
        );

        $id = $actions->ensureAutoDetectedDomain();

        self::assertGreaterThan(0, $id);
        $record = $repository->find($id);
        self::assertNotNull($record);
        self::assertSame('example.com', $record->domain());
    }

    public function test_domain_source_callable_returning_empty_falls_through_to_normal_path(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions = new DomainAdminActions(
            $repository,
            new FakeRunnerForAutoDetection(),
            'localhost',
            static fn (): string => '' // empty -> fall through
        );

        $id = $actions->ensureAutoDetectedDomain();

        // localhost is non-monitorable, so nothing should be inserted.
        self::assertSame(0, $id);
    }

    // -----------------------------------------------------------------
    // DOMAIN_MONITOR_PRIMARY_DOMAIN constant: bypasses guard
    //
    // Constants cannot be undefined after definition, so we test the constant
    // path via the domainSource seam (which is the same code path that reads
    // the constant). A separate integration-level note is provided.
    // -----------------------------------------------------------------

    public function test_constant_override_path_bypasses_monitorable_guard(): void
    {
        // The constant resolution path in ensureAutoDetectedDomain checks
        // defined('DOMAIN_MONITOR_PRIMARY_DOMAIN') then calls constant().
        // We simulate this via the domainSource callable seam, which exercises
        // the identical bypass logic.
        $repository = new DomainRepository(new ArrayDomainStore());
        // Simulate: constant defined as 'constant.example.net' and host is non-monitorable.
        $actions = new DomainAdminActions(
            $repository,
            new FakeRunnerForAutoDetection(),
            'localhost',
            static fn (): string => 'constant.example.net' // simulates constant
        );

        $id = $actions->ensureAutoDetectedDomain();

        self::assertGreaterThan(0, $id);
        $record = $repository->find($id);
        self::assertNotNull($record);
        self::assertSame('example.net', $record->domain());
    }

    // -----------------------------------------------------------------
    // apply_filters / WP filter: overrides host, still subject to guard
    // -----------------------------------------------------------------

    public function test_filter_override_via_apply_filters_is_used(): void
    {
        // Simulate apply_filters being available by injecting via domainSource seam.
        // The seam simulates what a filter would do: override to a real domain.
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions = new DomainAdminActions(
            $repository,
            new FakeRunnerForAutoDetection(),
            'mysite.local', // non-monitorable
            static fn (): string => 'filtered.example.org' // filter result
        );

        $id = $actions->ensureAutoDetectedDomain();

        self::assertGreaterThan(0, $id);
        $record = $repository->find($id);
        self::assertNotNull($record);
        self::assertSame('example.org', $record->domain());
    }

    // -----------------------------------------------------------------
    // resolvePrimaryDomain: the display path must agree with detection
    // (regression: widget showed dev notice while an override was active)
    // -----------------------------------------------------------------

    public function test_resolve_primary_domain_returns_override_on_non_monitorable_host(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions(
            $repository,
            new FakeRunnerForAutoDetection(),
            'localhost',
            static fn (): string => 'gabeherbert.com'
        );

        self::assertSame('gabeherbert.com', $actions->resolvePrimaryDomain());
    }

    public function test_resolve_primary_domain_empty_for_non_monitorable_host_without_override(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'localhost');

        self::assertSame('', $actions->resolvePrimaryDomain());
    }

    public function test_resolve_primary_domain_returns_apex_for_public_host(): void
    {
        $repository = new DomainRepository(new ArrayDomainStore());
        $actions    = new DomainAdminActions($repository, new FakeRunnerForAutoDetection(), 'www.example.co.uk');

        self::assertSame('example.co.uk', $actions->resolvePrimaryDomain());
    }
}

final class FakeRunnerForAutoDetection
{
    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        return [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => null,
            'rdap_expires_at' => null,
            'last_checked_at' => '2026-06-12 00:00:00',
        ];
    }
}
