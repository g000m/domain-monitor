<?php
declare(strict_types=1);

namespace DomainMonitor\Rest;

use DateTimeImmutable;
use DomainMonitor\Domain\StatusCalculator;
use DomainMonitor\Storage\AlertStore;
use DomainMonitor\Storage\DomainRecord;
use DomainMonitor\Storage\DomainRepository;

/**
 * REST controller for the domain-monitor/v1 namespace.
 *
 * Endpoints:
 *   GET /domain-monitor/v1/status          — all monitored domains, compact summary.
 *   GET /domain-monitor/v1/status/<domain> — single domain detail; 404 if not monitored.
 *
 * Permission callback defaults to manage_options but is filterable via
 * the domain_monitor_rest_permission filter so external/token-based auth
 * can be wired in later without touching this class.
 *
 * The REST route registration is handled by Plugin.php via rest_api_init.
 * This class contains only pure data-assembly logic so it can be unit-tested
 * without booting the full WP REST framework.
 */
final class StatusController
{
    public const NAMESPACE = 'domain-monitor/v1';
    public const ROUTE_ALL = '/status';
    public const ROUTE_SINGLE = '/status/(?P<domain>[a-zA-Z0-9._-]+)';

    private DomainRepository $repository;
    private AlertStore $alertStore;
    private StatusCalculator $calculator;

    public function __construct(DomainRepository $repository, AlertStore $alertStore, StatusCalculator $calculator)
    {
        $this->repository = $repository;
        $this->alertStore = $alertStore;
        $this->calculator = $calculator;
    }

    /**
     * Register REST routes via the WP REST API.
     * Called on rest_api_init; no-ops outside WordPress.
     */
    public function register(): void
    {
        if (! function_exists('register_rest_route')) {
            return;
        }

        register_rest_route(self::NAMESPACE, self::ROUTE_ALL, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleAll'],
            'permission_callback' => [$this, 'checkPermission'],
            'schema'              => [$this, 'allSchema'],
        ]);

        register_rest_route(self::NAMESPACE, self::ROUTE_SINGLE, [
            'methods'             => 'GET',
            'callback'            => [$this, 'handleSingle'],
            'permission_callback' => [$this, 'checkPermission'],
            'schema'              => [$this, 'singleSchema'],
        ]);
    }

    /**
     * Permission callback.
     * Default: require manage_options. Filterable for token/app-password auth.
     */
    public function checkPermission(): bool
    {
        $default = function_exists('current_user_can') && current_user_can('manage_options');

        if (function_exists('apply_filters')) {
            /** @var mixed $result */
            $result = apply_filters('domain_monitor_rest_permission', $default);
            return (bool) $result;
        }

        return $default;
    }

    // -----------------------------------------------------------------
    // WP REST callbacks
    // -----------------------------------------------------------------

    /**
     * GET /domain-monitor/v1/status
     *
     * @return mixed WP_REST_Response|array
     */
    public function handleAll()
    {
        $data = $this->assembleAll();

        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }

    /**
     * GET /domain-monitor/v1/status/<domain>
     *
     * @param mixed $request WP_REST_Request or array with 'params'
     * @return mixed WP_REST_Response|WP_Error|array
     */
    public function handleSingle($request)
    {
        $domainParam = '';

        if (is_array($request)) {
            $domainParam = (string) ($request['domain'] ?? '');
        } elseif (is_object($request) && method_exists($request, 'get_param')) {
            $domainParam = (string) $request->get_param('domain');
        }

        $data = $this->assembleSingle($domainParam);

        if ($data === null) {
            if (function_exists('rest_ensure_response')) {
                return new \WP_Error(
                    'domain_monitor_not_found',
                    'Domain not found.',
                    ['status' => 404]
                );
            }
            return null;
        }

        if (function_exists('rest_ensure_response')) {
            return rest_ensure_response($data);
        }

        return $data;
    }

    // -----------------------------------------------------------------
    // Public data-assembly methods (testable without WP REST bootstrap)
    // -----------------------------------------------------------------

    /**
     * Assemble the all-domains response payload.
     *
     * @return array<string,mixed>
     */
    public function assembleAll(): array
    {
        $records = $this->repository->all();
        $domains = [];

        foreach ($records as $record) {
            if (! $record->isActive()) {
                continue;
            }
            $domains[] = $this->summaryRow($record);
        }

        return [
            'domains' => $domains,
            'count'   => count($domains),
        ];
    }

    /**
     * Assemble the single-domain response payload.
     * Returns null when the domain is not monitored (callers should 404).
     *
     * @return array<string,mixed>|null
     */
    public function assembleSingle(string $domainParam): ?array
    {
        $domainParam = strtolower(trim($domainParam));
        if ($domainParam === '') {
            return null;
        }

        $records = $this->repository->all();

        foreach ($records as $record) {
            if (! $record->isActive()) {
                continue;
            }
            if (strtolower(trim($record->domain())) === $domainParam) {
                return $this->detailRow($record);
            }
        }

        return null;
    }

    // -----------------------------------------------------------------
    // Schema declarations
    // -----------------------------------------------------------------

    /**
     * JSON Schema for the all-domains endpoint response.
     *
     * @return array<string,mixed>
     */
    public function allSchema(): array
    {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'domain-monitor-status-list',
            'type'       => 'object',
            'properties' => [
                'domains' => [
                    'type'        => 'array',
                    'description' => 'List of monitored domain summaries.',
                    'items'       => $this->domainSummarySchema(),
                ],
                'count' => [
                    'type'        => 'integer',
                    'description' => 'Number of active monitored domains.',
                ],
            ],
        ];
    }

    /**
     * JSON Schema for the single-domain endpoint response.
     *
     * @return array<string,mixed>
     */
    public function singleSchema(): array
    {
        return [
            '$schema'    => 'http://json-schema.org/draft-04/schema#',
            'title'      => 'domain-monitor-status-single',
        ] + $this->domainDetailSchema();
    }

    // -----------------------------------------------------------------
    // Private helpers
    // -----------------------------------------------------------------

    /**
     * Compact summary row used in the list endpoint.
     *
     * @return array<string,mixed>
     */
    private function summaryRow(DomainRecord $record): array
    {
        $status = $this->computeStatus($record);

        return [
            'domain'     => $record->domain(),
            'status'     => $status->code(),
            'message'    => $status->message(),
            'checked_at' => $record->lastCheckedAt(),
            'expires_at' => $record->rdapExpiresAt(),
            'source'     => $record->source(),
        ];
    }

    /**
     * Full detail row used in the single-domain endpoint.
     *
     * @return array<string,mixed>
     */
    private function detailRow(DomainRecord $record): array
    {
        $status   = $this->computeStatus($record);
        $snapshot = $record->snapshot();

        $detail = [
            'domain'     => $record->domain(),
            'status'     => $status->code(),
            'message'    => $status->message(),
            'checked_at' => $record->lastCheckedAt(),
            'expires_at' => $record->rdapExpiresAt(),
            'source'     => $record->source(),
            'dns'        => [
                'status'  => $record->dnsStatus(),
                'message' => $record->dnsMessage(),
            ],
            'rdap'       => [
                'status'          => $record->rdapStatus(),
                'message'         => $record->rdapMessage(),
                'registrar'       => $record->rdapRegistrar(),
                'expires_at'      => $record->rdapExpiresAt(),
                'transfer_locked' => $record->rdapTransferLocked(),
            ],
            'ssl'        => [
                'status'     => $record->sslStatus(),
                'expires_at' => $record->sslExpiresAt(),
                'issuer'     => $record->sslIssuer(),
            ],
        ];

        // Include email DNS data when present in snapshot.
        if (isset($snapshot['email_dns']) && is_array($snapshot['email_dns'])) {
            $detail['email_dns'] = $snapshot['email_dns'];
        }

        return $detail;
    }

    private function computeStatus(DomainRecord $record): \DomainMonitor\Domain\DomainStatus
    {
        if ($record->lastCheckedAt() === '' && $record->snapshot() === []) {
            return new \DomainMonitor\Domain\DomainStatus(StatusCalculator::STATUS_WARN, 'Not checked yet.');
        }

        $open   = $this->alertStore->openAlertsForDomain($record->id());
        $recent = $this->alertStore->recentlyResolvedAlertsForDomain($record->id(), 3, gmdate('Y-m-d H:i:s'));

        $alerts     = [];
        $combined   = array_merge($open, $recent);
        foreach ($combined as $row) {
            $row['is_active'] = ($row['resolved_at'] ?? null) === null;
            $row['severity']  = $row['severity'] ?? StatusCalculator::STATUS_WARN;
            $alerts[]         = $row;
        }

        return $this->calculator->calculate($record->snapshot(), $alerts, new DateTimeImmutable());
    }

    /**
     * @return array<string,mixed>
     */
    private function domainSummarySchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'domain'     => ['type' => 'string'],
                'status'     => ['type' => 'string', 'enum' => ['ok', 'warn', 'fail']],
                'message'    => ['type' => 'string'],
                'checked_at' => ['type' => 'string'],
                'expires_at' => ['type' => 'string'],
                'source'     => ['type' => 'string'],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function domainDetailSchema(): array
    {
        return [
            'type'       => 'object',
            'properties' => [
                'domain'     => ['type' => 'string'],
                'status'     => ['type' => 'string', 'enum' => ['ok', 'warn', 'fail']],
                'message'    => ['type' => 'string'],
                'checked_at' => ['type' => 'string'],
                'expires_at' => ['type' => 'string'],
                'source'     => ['type' => 'string'],
                'dns'        => ['type' => 'object'],
                'rdap'       => ['type' => 'object'],
                'ssl'        => ['type' => 'object'],
                'email_dns'  => ['type' => 'object'],
            ],
        ];
    }
}
