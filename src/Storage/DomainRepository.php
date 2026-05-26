<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

final class DomainRepository
{
    private DomainStore $store;

    public function __construct(DomainStore $store)
    {
        $this->store = $store;
    }

    public function upsertDomain(string $domain, string $source): int
    {
        return $this->store->upsertDomain($domain, $source);
    }

    /** @return list<DomainRecord> */
    public function all(): array
    {
        return array_map(static function (array $row): DomainRecord {
            return new DomainRecord($row);
        }, $this->store->all());
    }

    public function find(int $id): ?DomainRecord
    {
        $row = $this->store->find($id);
        return $row === null ? null : new DomainRecord($row);
    }

    /** @param array<string,string|null> $result */
    public function saveCheckResult(int $id, array $result): void
    {
        $this->store->saveCheckResult($id, $result);
    }
}
