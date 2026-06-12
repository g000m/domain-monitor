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
            'A'    => [['ip' => '93.184.216.34']],
            'AAAA' => [],
            'NS'   => [['target' => 'a.iana-servers.net']],
            'MX'   => [],
        ]);

        $result = (new DnsChecker($resolver))->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertStringContainsString('A record', $result->message());
        // Checker now also queries MX.
        self::assertSame(
            ['example.com:A', 'example.com:AAAA', 'example.com:NS', 'example.com:MX'],
            $resolver->queries()
        );
    }

    public function test_it_returns_degraded_when_no_relevant_records_exist(): void
    {
        $resolver = new FakeDnsResolver(['A' => [], 'AAAA' => [], 'NS' => [], 'MX' => []]);

        $result = (new DnsChecker($resolver))->check('example.invalid');

        self::assertSame('degraded', $result->status());
        self::assertStringContainsString('No A, AAAA, or NS records', $result->message());
    }

    public function test_degraded_result_has_empty_records(): void
    {
        $resolver = new FakeDnsResolver(['A' => [], 'AAAA' => [], 'NS' => [], 'MX' => []]);

        $result = (new DnsChecker($resolver))->check('example.invalid');

        self::assertSame([], $result->records());
    }

    public function test_it_captures_a_and_ns_record_values(): void
    {
        $resolver = new FakeDnsResolver([
            'A'    => [['ip' => '1.2.3.4'], ['ip' => '5.6.7.8']],
            'AAAA' => [],
            'NS'   => [['target' => 'ns1.example.com.'], ['target' => 'ns2.example.com']],
            'MX'   => [],
        ]);

        $result  = (new DnsChecker($resolver))->check('example.com');
        $records = $result->records();

        self::assertSame(['1.2.3.4', '5.6.7.8'], $records['a']);
        self::assertSame([], $records['aaaa']);
        // Trailing dots stripped, values lowercased.
        self::assertSame(['ns1.example.com', 'ns2.example.com'], $records['ns']);
        self::assertSame([], $records['mx']);
    }

    public function test_it_captures_mx_record_values(): void
    {
        $resolver = new FakeDnsResolver([
            'A'    => [['ip' => '1.2.3.4']],
            'AAAA' => [],
            'NS'   => [['target' => 'ns1.example.com']],
            'MX'   => [
                ['pri' => 10, 'target' => 'mail.example.com.'],
                ['pri' => 20, 'target' => 'mail2.example.com'],
            ],
        ]);

        $result  = (new DnsChecker($resolver))->check('example.com');
        $records = $result->records();

        self::assertSame(
            [
                ['priority' => 10, 'host' => 'mail.example.com'],
                ['priority' => 20, 'host' => 'mail2.example.com'],
            ],
            $records['mx']
        );
    }

    public function test_it_normalises_ns_values_to_lowercase(): void
    {
        $resolver = new FakeDnsResolver([
            'A'    => [['ip' => '1.2.3.4']],
            'AAAA' => [],
            'NS'   => [['target' => 'NS1.EXAMPLE.COM.']],
            'MX'   => [],
        ]);

        $result  = (new DnsChecker($resolver))->check('example.com');
        $records = $result->records();

        self::assertSame(['ns1.example.com'], $records['ns']);
    }

    public function test_mx_host_normalised_to_lowercase_and_trailing_dot_stripped(): void
    {
        $resolver = new FakeDnsResolver([
            'A'    => [['ip' => '1.2.3.4']],
            'AAAA' => [],
            'NS'   => [['target' => 'ns1.example.com']],
            'MX'   => [['pri' => 10, 'target' => 'MAIL.EXAMPLE.COM.']],
        ]);

        $result  = (new DnsChecker($resolver))->check('example.com');
        $records = $result->records();

        self::assertSame([['priority' => 10, 'host' => 'mail.example.com']], $records['mx']);
    }
}

final class FakeDnsResolver
{
    /** @var array<string,list<array<string,mixed>>> */
    private array $records;
    /** @var list<string> */
    private array $queries = [];

    /** @param array<string,list<array<string,mixed>>> $records */
    public function __construct(array $records)
    {
        $this->records = $records;
    }

    /** @return list<array<string,mixed>> */
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
