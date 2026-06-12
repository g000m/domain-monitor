<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit\Rest;

use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Rest\StatusController;
use DomainMonitor\Storage\ArrayAlertStore;
use DomainMonitor\Storage\ArrayDomainStore;
use DomainMonitor\Storage\DomainRepository;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for StatusController data-assembly logic.
 *
 * Tests call assembleAll() and assembleSingle() directly — no WP REST
 * framework needed. This matches the approach used by the existing
 * DomainCheckRunner and DashboardWidget tests which also avoid WP boot.
 */
final class StatusControllerTest extends TestCase
{
    private ArrayDomainStore $store;
    private DomainRepository $repository;
    private ArrayAlertStore $alertStore;
    private StatusController $controller;

    protected function setUp(): void
    {
        $this->store      = new ArrayDomainStore();
        $this->repository = new DomainRepository($this->store);
        $this->alertStore = new ArrayAlertStore();
        $this->controller = new StatusController(
            $this->repository,
            $this->alertStore,
            new StatusCalculator()
        );
    }

    // -----------------------------------------------------------------
    // assembleAll() — empty store
    // -----------------------------------------------------------------

    public function test_assemble_all_returns_empty_domains_when_no_records(): void
    {
        $data = $this->controller->assembleAll();

        self::assertSame([], $data['domains']);
        self::assertSame(0, $data['count']);
    }

    // -----------------------------------------------------------------
    // assembleAll() — single unchecked domain
    // -----------------------------------------------------------------

    public function test_assemble_all_returns_unchecked_domain_as_warn(): void
    {
        $this->repository->upsertDomain('example.com', 'auto');

        $data = $this->controller->assembleAll();

        self::assertSame(1, $data['count']);
        $row = $data['domains'][0];
        self::assertSame('example.com', $row['domain']);
        self::assertSame('warn', $row['status']);
        self::assertSame('auto', $row['source']);
    }

    // -----------------------------------------------------------------
    // assembleAll() — multiple domains
    // -----------------------------------------------------------------

    public function test_assemble_all_lists_multiple_domains(): void
    {
        $this->repository->upsertDomain('alpha.example.com', 'manual');
        $this->repository->upsertDomain('beta.example.com', 'auto');

        $data = $this->controller->assembleAll();

        self::assertSame(2, $data['count']);
        $domains = array_column($data['domains'], 'domain');
        self::assertContains('alpha.example.com', $domains);
        self::assertContains('beta.example.com', $domains);
    }

    public function test_assemble_all_domain_with_ok_check_result(): void
    {
        $id = $this->repository->upsertDomain('ok.example.com', 'manual');
        $this->repository->saveCheckResult($id, [
            'dns_status'      => 'ok',
            'dns_message'     => 'DNS found.',
            'rdap_status'     => 'ok',
            'rdap_message'    => 'RDAP ok.',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2027-06-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ]);

        $data = $this->controller->assembleAll();

        $row = $data['domains'][0];
        self::assertSame('ok.example.com', $row['domain']);
        self::assertSame('ok', $row['status']);
        self::assertSame('2026-06-12 00:00:00', $row['checked_at']);
        self::assertSame('2027-06-01T00:00:00Z', $row['expires_at']);
    }

    public function test_assemble_all_domain_with_open_alert_returns_warn(): void
    {
        $id = $this->repository->upsertDomain('alert.example.com', 'manual');
        $this->repository->saveCheckResult($id, [
            'dns_status'      => 'ok',
            'dns_message'     => 'DNS found.',
            'rdap_status'     => 'ok',
            'rdap_message'    => 'RDAP ok.',
            'rdap_expires_at' => '2027-06-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ]);
        $this->alertStore->createAlert($id, 'ns_changed', 'NS records changed.', null);

        $data = $this->controller->assembleAll();

        self::assertSame('warn', $data['domains'][0]['status']);
    }

    // -----------------------------------------------------------------
    // assembleAll() — schema keys present
    // -----------------------------------------------------------------

    public function test_assemble_all_row_has_required_keys(): void
    {
        $id = $this->repository->upsertDomain('schema.example.com', 'manual');
        $this->repository->saveCheckResult($id, [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_expires_at' => '2027-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ]);

        $data = $this->controller->assembleAll();
        $row  = $data['domains'][0];

        foreach (['domain', 'status', 'message', 'checked_at', 'expires_at', 'source'] as $key) {
            self::assertArrayHasKey($key, $row, "Missing key: {$key}");
        }
    }

    // -----------------------------------------------------------------
    // assembleSingle() — found / not found
    // -----------------------------------------------------------------

    public function test_assemble_single_returns_null_for_unknown_domain(): void
    {
        $result = $this->controller->assembleSingle('notmonitored.example.com');

        self::assertNull($result);
    }

    public function test_assemble_single_returns_null_for_empty_param(): void
    {
        $this->repository->upsertDomain('example.com', 'auto');

        $result = $this->controller->assembleSingle('');

        self::assertNull($result);
    }

    public function test_assemble_single_returns_detail_for_known_domain(): void
    {
        $id = $this->repository->upsertDomain('detail.example.com', 'manual');
        $this->repository->saveCheckResult($id, [
            'dns_status'      => 'ok',
            'dns_message'     => 'DNS ok.',
            'rdap_status'     => 'ok',
            'rdap_message'    => 'RDAP ok.',
            'rdap_registrar'  => 'Acme Registrar',
            'rdap_expires_at' => '2027-06-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ]);

        $result = $this->controller->assembleSingle('detail.example.com');

        self::assertNotNull($result);
        self::assertSame('detail.example.com', $result['domain']);
        self::assertSame('ok', $result['status']);
        self::assertArrayHasKey('dns', $result);
        self::assertArrayHasKey('rdap', $result);
        self::assertArrayHasKey('ssl', $result);
        self::assertSame('ok', $result['dns']['status']);
        self::assertSame('Acme Registrar', $result['rdap']['registrar']);
    }

    public function test_assemble_single_is_case_insensitive(): void
    {
        $this->repository->upsertDomain('example.com', 'auto');

        $result = $this->controller->assembleSingle('EXAMPLE.COM');

        self::assertNotNull($result);
        self::assertSame('example.com', $result['domain']);
    }

    public function test_assemble_single_includes_email_dns_when_in_snapshot(): void
    {
        $id = $this->repository->upsertDomain('mail.example.com', 'manual');

        $snapshot = [
            'schema_version' => 1,
            'checked_at'     => '2026-06-12 00:00:00',
            'domain'         => 'mail.example.com',
            'rdap'           => ['status' => 'ok', 'expires_at' => '2027-01-01T00:00:00Z', 'message' => '', 'registrar' => '', 'transfer_locked' => null, 'domain_statuses' => []],
            'dns'            => ['status' => 'ok', 'message' => ''],
            'email_dns'      => [
                'spf_state'   => 'present',
                'spf_record'  => 'v=spf1 ~all',
                'dmarc_state' => 'present',
                'dmarc_record' => 'v=DMARC1; p=none',
                'mx_state'    => 'present',
                'status'      => 'ok',
            ],
            'errors' => [],
        ];

        $this->store->overwriteSnapshot($id, $snapshot);

        $result = $this->controller->assembleSingle('mail.example.com');

        self::assertNotNull($result);
        self::assertArrayHasKey('email_dns', $result);
        self::assertSame('present', $result['email_dns']['spf_state']);
    }

    public function test_assemble_single_omits_email_dns_when_not_in_snapshot(): void
    {
        $id = $this->repository->upsertDomain('nomail.example.com', 'manual');
        $this->repository->saveCheckResult($id, [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_expires_at' => '2027-01-01T00:00:00Z',
            'last_checked_at' => '2026-06-12 00:00:00',
        ]);

        $result = $this->controller->assembleSingle('nomail.example.com');

        self::assertNotNull($result);
        self::assertArrayNotHasKey('email_dns', $result);
    }

    // -----------------------------------------------------------------
    // assembleAll() count
    // -----------------------------------------------------------------

    public function test_assemble_all_count_matches_domains_array_length(): void
    {
        $this->repository->upsertDomain('a.example.com', 'manual');
        $this->repository->upsertDomain('b.example.com', 'manual');
        $this->repository->upsertDomain('c.example.com', 'manual');

        $data = $this->controller->assembleAll();

        self::assertCount($data['count'], $data['domains']);
        self::assertSame(3, $data['count']);
    }

    // -----------------------------------------------------------------
    // Schema declarations
    // -----------------------------------------------------------------

    public function test_all_schema_has_expected_structure(): void
    {
        $schema = $this->controller->allSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('domains', $schema['properties']);
        self::assertArrayHasKey('count', $schema['properties']);
    }

    public function test_single_schema_has_expected_structure(): void
    {
        $schema = $this->controller->singleSchema();

        self::assertSame('object', $schema['type']);
        self::assertArrayHasKey('domain', $schema['properties']);
        self::assertArrayHasKey('status', $schema['properties']);
        self::assertArrayHasKey('dns', $schema['properties']);
        self::assertArrayHasKey('rdap', $schema['properties']);
        self::assertArrayHasKey('ssl', $schema['properties']);
        self::assertArrayHasKey('email_dns', $schema['properties']);
    }

    // -----------------------------------------------------------------
    // checkPermission() — no WP functions in test env → defaults to false
    // -----------------------------------------------------------------

    public function test_check_permission_returns_false_without_wp_functions(): void
    {
        // In the unit test bootstrap, current_user_can() is not defined,
        // so the default evaluates to false (safe default).
        $result = $this->controller->checkPermission();

        self::assertFalse($result);
    }
}
