<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\CheckRunner;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Plugin;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use DomainMonitor\Tests\Unit\Support\FakeCheckRunner;
use DomainMonitor\Tests\Unit\Support\FakeNotifier;
use PHPUnit\Framework\TestCase;

final class PluginDailyCheckTest extends TestCase
{
    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function makePlugin(
        DomainRepository $repository,
        FakeCheckRunner $runner,
        FakeNotifier $notifier
    ): Plugin {
        return new Plugin($repository, $runner, $notifier);
    }

    /** Returns a result array that the StatusCalculator will score as ok. */
    private function healthyResult(): array
    {
        return [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2030-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-11 00:00:00',
        ];
    }

    /** Returns a result array that the StatusCalculator will score as warn (degraded dns). */
    private function degradedResult(): array
    {
        return [
            'dns_status'      => 'degraded',
            'dns_message'     => 'DNS lookup timed out.',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2030-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-11 00:00:00',
        ];
    }

    /** Returns a result array that the StatusCalculator will score as fail (expired). */
    private function failResult(): array
    {
        return [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2020-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-11 00:00:00',
        ];
    }

    // ----------------------------------------------------------------
    // Batch / isolation tests
    // ----------------------------------------------------------------

    public function test_batch_runs_all_domains_and_saves_a_result_for_each(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);

        $id1 = $repository->upsertDomain('alpha.example.com', 'manual');
        $id2 = $repository->upsertDomain('beta.example.com', 'manual');

        $runner   = new FakeCheckRunner();
        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertContains('alpha.example.com', $runner->checked);
        self::assertContains('beta.example.com', $runner->checked);
        self::assertCount(2, $runner->checked);

        $r1 = $repository->find($id1);
        $r2 = $repository->find($id2);

        self::assertNotNull($r1);
        self::assertNotNull($r2);
        self::assertNotSame('', $r1->lastCheckedAt(), 'alpha should have a last_checked_at after the batch');
        self::assertNotSame('', $r2->lastCheckedAt(), 'beta should have a last_checked_at after the batch');
    }

    public function test_runner_throws_for_one_domain_other_domain_still_checked_and_degraded_result_saved(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);

        $id1 = $repository->upsertDomain('failing.example.com', 'manual');
        $id2 = $repository->upsertDomain('passing.example.com', 'manual');

        $runner = new FakeCheckRunner();
        $runner->throwFor('failing.example.com', new \RuntimeException('Network unreachable'));

        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        // Both domains were attempted.
        self::assertContains('failing.example.com', $runner->checked);
        self::assertContains('passing.example.com', $runner->checked);

        // The passing domain has a healthy result saved.
        $passing = $repository->find($id2);
        self::assertNotNull($passing);
        self::assertSame(StatusCalculator::STATUS_OK, $passing->statusCode());

        // The failing domain has a degraded result saved (not left unchecked).
        $failing = $repository->find($id1);
        self::assertNotNull($failing);
        self::assertNotSame('', $failing->lastCheckedAt(), 'degraded result should set last_checked_at');
        self::assertNotSame(StatusCalculator::STATUS_OK, $failing->statusCode(), 'degraded result should not be ok');
    }

    // ----------------------------------------------------------------
    // Notification transition tests
    // ----------------------------------------------------------------

    /**
     * Seed the store so a domain has a known existing status before the batch.
     */
    private function seedWithStatus(ArrayDomainStore $store, DomainRepository $repository, string $domain, array $result): int
    {
        $id = $repository->upsertDomain($domain, 'manual');
        $store->saveCheckResult($id, $result);
        return $id;
    }

    public function test_ok_to_warn_triggers_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->healthyResult());

        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->degradedResult());

        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertCount(1, $notifier->notifications);
        self::assertSame('ok', $notifier->notifications[0]['from']);
        self::assertSame('warn', $notifier->notifications[0]['to']);
    }

    public function test_ok_to_fail_triggers_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->healthyResult());

        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->failResult());

        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertCount(1, $notifier->notifications);
        self::assertSame('ok', $notifier->notifications[0]['from']);
        self::assertSame('fail', $notifier->notifications[0]['to']);
    }

    public function test_warn_to_fail_triggers_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->degradedResult());

        $runner = new FakeCheckRunner();
        $runner->overrideResult('example.com', $this->failResult());

        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertCount(1, $notifier->notifications);
        self::assertSame('warn', $notifier->notifications[0]['from']);
        self::assertSame('fail', $notifier->notifications[0]['to']);
    }

    public function test_warn_to_ok_does_not_trigger_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->degradedResult());

        // Default runner returns healthy result.
        $runner   = new FakeCheckRunner();
        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertEmpty($notifier->notifications, 'warn -> ok should not notify');
    }

    public function test_fail_to_ok_does_not_trigger_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->failResult());

        // Default runner returns healthy result.
        $runner   = new FakeCheckRunner();
        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertEmpty($notifier->notifications, 'fail -> ok should not notify');
    }

    public function test_unchanged_ok_does_not_trigger_notification(): void
    {
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $this->seedWithStatus($store, $repository, 'example.com', $this->healthyResult());

        $runner   = new FakeCheckRunner();
        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertEmpty($notifier->notifications, 'ok -> ok should not notify');
    }

    // ----------------------------------------------------------------
    // First check transitions (never-checked domain starts as warn)
    // ----------------------------------------------------------------

    public function test_first_successful_check_is_warn_to_ok_no_notification(): void
    {
        // Never-checked domain starts as warn (task 1 behavior).
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $repository->upsertDomain('new.example.com', 'manual');

        $runner   = new FakeCheckRunner();
        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertEmpty($notifier->notifications, 'first successful check (warn -> ok) should not notify');
    }

    public function test_first_failing_check_is_warn_to_fail_triggers_notification(): void
    {
        // Never-checked domain starts as warn; first check returns fail.
        $store      = new ArrayDomainStore();
        $repository = new DomainRepository($store);
        $repository->upsertDomain('new.example.com', 'manual');

        $runner = new FakeCheckRunner();
        $runner->overrideResult('new.example.com', $this->failResult());

        $notifier = new FakeNotifier();
        $plugin   = $this->makePlugin($repository, $runner, $notifier);

        $plugin->handleDailyCheck();

        self::assertCount(1, $notifier->notifications, 'first failing check (warn -> fail) should notify');
        self::assertSame('warn', $notifier->notifications[0]['from']);
        self::assertSame('fail', $notifier->notifications[0]['to']);
    }
}
