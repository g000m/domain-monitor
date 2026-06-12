<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit\Support;

use DomainMonitor\Checks\CheckRunner;

/**
 * Stands in for DomainCheckRunner in Plugin tests.
 *
 * By default returns a healthy result for every domain.
 * Per-domain overrides and throw-for-domain can be configured before the test.
 */
final class FakeCheckRunner implements CheckRunner
{
    /** @var array<string, array<string,string|null>> domain -> result override */
    private array $resultOverrides = [];

    /** @var array<string, \Throwable> domain -> exception to throw */
    private array $exceptions = [];

    /** @var list<string> domains that were checked */
    public array $checked = [];

    /**
     * @param array<string,string|null> $result
     */
    public function overrideResult(string $domain, array $result): void
    {
        $this->resultOverrides[$domain] = $result;
    }

    public function throwFor(string $domain, \Throwable $e): void
    {
        $this->exceptions[$domain] = $e;
    }

    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        $this->checked[] = $domain;

        if (isset($this->exceptions[$domain])) {
            throw $this->exceptions[$domain];
        }

        if (isset($this->resultOverrides[$domain])) {
            return $this->resultOverrides[$domain];
        }

        return [
            'dns_status'      => 'ok',
            'dns_message'     => '',
            'rdap_status'     => 'ok',
            'rdap_message'    => '',
            'rdap_registrar'  => 'Example Registrar',
            'rdap_expires_at' => '2030-01-01T00:00:00Z',
            'last_checked_at' => gmdate('Y-m-d H:i:s'),
        ];
    }
}
