<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\DnsChecker;
use PHPUnit\Framework\TestCase;

final class DnsCheckerTest extends TestCase
{
    public function test_it_returns_ok_when_domain_has_address_or_ns_records(): void
    {
        $resolver = new FakeDnsResolver([
            'A' => [['ip' => '93.184.216.34']],
            'AAAA' => [],
            'NS' => [['target' => 'a.iana-servers.net']],
        ]);

        $result = (new DnsChecker($resolver))->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertStringContainsString('A record', $result->message());
        self::assertSame(['example.com:A', 'example.com:AAAA', 'example.com:NS'], $resolver->queries());
    }

    public function test_it_returns_degraded_when_no_relevant_records_exist(): void
    {
        $resolver = new FakeDnsResolver(['A' => [], 'AAAA' => [], 'NS' => []]);

        $result = (new DnsChecker($resolver))->check('example.invalid');

        self::assertSame('degraded', $result->status());
        self::assertStringContainsString('No A, AAAA, or NS records', $result->message());
    }
}

final class FakeDnsResolver
{
    /** @var array<string,list<array<string,string>>> */
    private array $records;
    /** @var list<string> */
    private array $queries = [];

    /** @param array<string,list<array<string,string>>> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    /** @return list<array<string,string>> */
    public function records(string $domain, string $type): array
    {
        $this->queries[] = $domain . ':' . $type;
        return $this->records[$type] ?? [];
    }

    /** @return list<string> */
    public function queries(): array
    {
        return $this->queries;
    }
}
