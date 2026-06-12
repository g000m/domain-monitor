<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

/**
 * Minimal interface for objects that can run a domain check.
 * Allows test doubles to be injected into Plugin without extending
 * the concrete DomainCheckRunner.
 */
interface CheckRunner
{
    /** @return array<string,string|null> */
    public function check(string $domain): array;
}
