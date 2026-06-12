<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ApexDomain;
use DomainMonitor\Domain\DomainName;
use DomainMonitor\Domain\MonitorableHost;
use DomainMonitor\Storage\DomainRepository;

final class DomainAdminActions
{
    private DomainRepository $repository;
    /** @var object */
    private $runner;
    private string $currentHost;

    /**
     * An optional callable that resolves the effective host for auto-detection.
     * Signature: (): string
     * When provided, its return value overrides $currentHost in ensureAutoDetectedDomain.
     * Intended for testability (simulating DOMAIN_MONITOR_PRIMARY_DOMAIN constant
     * or the domain_monitor_primary_domain filter without touching global state).
     *
     * @var callable|null
     */
    private $domainSource;

    /**
     * @param object        $runner       Object exposing check(string $domain): array.
     * @param callable|null $domainSource Optional: callable(): string that supplies the
     *                                    effective host. When null the class resolves it
     *                                    via the constant/filter/currentHost chain.
     */
    public function __construct(DomainRepository $repository, $runner, string $currentHost, ?callable $domainSource = null)
    {
        $this->repository   = $repository;
        $this->runner       = $runner;
        $this->currentHost  = $currentHost;
        $this->domainSource = $domainSource;
    }

    /**
     * Auto-insert the site's apex domain when it is publicly monitorable.
     *
     * Resolution order:
     *   1. If a $domainSource callable was injected, use it (test seam).
     *   2. If constant DOMAIN_MONITOR_PRIMARY_DOMAIN is defined and non-empty, use it.
     *   3. If WP filters are available, apply domain_monitor_primary_domain filter.
     *   4. Fall back to $currentHost.
     *
     * Steps 1 and 2 BYPASS the MonitorableHost guard (an explicit override is
     * intentional). Steps 3 and 4 are subject to the guard.
     *
     * Returns 0 when the host is not monitorable (no domain inserted).
     */
    public function ensureAutoDetectedDomain(): int
    {
        $domain = $this->resolvePrimaryDomain();
        if ($domain === '') {
            return 0;
        }

        return $this->repository->upsertDomain($domain, 'auto');
    }

    /**
     * Resolve the effective primary apex domain for this site, or '' when the
     * site has no publicly monitorable host and no override. Same resolution
     * order as ensureAutoDetectedDomain; used by the dashboard widget so the
     * displayed domain always matches what auto-detection would insert.
     */
    public function resolvePrimaryDomain(): string
    {
        // Injected source (test seam) or constant override: bypass monitorable guard.
        if ($this->domainSource !== null) {
            $override = (string) ($this->domainSource)();
            if ($override !== '') {
                return ApexDomain::fromHost($override);
            }
        }

        // Constant override: bypass monitorable guard.
        if (defined('DOMAIN_MONITOR_PRIMARY_DOMAIN')) {
            $constant = (string) constant('DOMAIN_MONITOR_PRIMARY_DOMAIN');
            if ($constant !== '') {
                return ApexDomain::fromHost($constant);
            }
        }

        // Resolve the host from the current site, then apply optional WP filter.
        $host = $this->currentHost;

        if (function_exists('apply_filters')) {
            /** @var mixed $filtered */
            $filtered = apply_filters('domain_monitor_primary_domain', $host);
            if (is_string($filtered) && $filtered !== '') {
                $host = $filtered;
            }
        }

        // Guard: only monitor publicly-monitorable hosts.
        if (! MonitorableHost::isMonitorable($host)) {
            return '';
        }

        return ApexDomain::fromHost($host);
    }

    public function addDomain(string $input): int
    {
        $domain = DomainName::fromUserInput($input)->toString();
        return $this->repository->upsertDomain(ApexDomain::fromHost($domain), 'manual');
    }

    public function runManualCheck(?int $domainId = null): int
    {
        $id = $domainId ?? $this->ensureAutoDetectedDomain();
        $record = $this->repository->find($id);
        if ($record === null) {
            return 0;
        }

        $result = $this->runner->check($record->domain());
        $this->repository->saveCheckResult($id, $result);

        return $id;
    }
}
