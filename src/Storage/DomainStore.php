<?php
declare(strict_types=1);

namespace DomainMonitor\Storage;

interface DomainStore
{
    public function upsertDomain(string $domain, string $source): int;

    /** @return list<array<string,string|int|null>> */
    public function all(): array;

    /** @return array<string,string|int|null>|null */
    public function find(int $id): ?array;

    /** @param array<string,string|null> $result */
    public function saveCheckResult(int $id, array $result): void;
}
