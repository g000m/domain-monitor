<?php
declare(strict_types=1);

namespace DomainMonitor\Admin;

use DomainMonitor\Domain\ApexDomain;
use DomainMonitor\Domain\DomainName;
use DomainMonitor\Storage\DomainRepository;

final class DomainAdminActions
{
    private DomainRepository $repository;
    /** @var object */
    private $runner;
    private string $currentHost;

    /** @param object $runner Object exposing check(string $domain): array. */
    public function __construct(DomainRepository $repository, $runner, string $currentHost)
    {
        $this->repository = $repository;
        $this->runner = $runner;
        $this->currentHost = $currentHost;
    }

    public function ensureAutoDetectedDomain(): int
    {
        return $this->repository->upsertDomain(ApexDomain::fromHost($this->currentHost), 'auto');
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
