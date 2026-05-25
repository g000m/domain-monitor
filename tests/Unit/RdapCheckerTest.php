<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\RdapChecker;
use PHPUnit\Framework\TestCase;

final class RdapCheckerTest extends TestCase
{
    public function test_it_requests_rdap_net_domain_endpoint(): void
    {
        $http = new FakeHttpClient([
            'https://www.rdap.net/domain/example.com' => [200, json_encode([
                'events' => [[
                    'eventAction' => 'expiration',
                    'eventDate' => '2027-01-01T00:00:00Z',
                ]],
                'entities' => [[
                    'roles' => ['registrar'],
                    'vcardArray' => ['vcard', [['fn', [], 'text', 'Example Registrar']]],
                ]],
            ])],
        ]);

        $result = (new RdapChecker($http))->check('example.com');

        self::assertSame(['https://www.rdap.net/domain/example.com'], $http->requestedUrls());
        self::assertSame('ok', $result->status());
        self::assertSame('2027-01-01T00:00:00Z', $result->expiresAt());
        self::assertSame('Example Registrar', $result->registrar());
    }

    public function test_unknown_tld_returns_degraded_message_not_exception(): void
    {
        $http = new FakeHttpClient([
            'https://www.rdap.net/domain/example.invalidtld' => [404, '{"errorCode":404}'],
        ]);

        $result = (new RdapChecker($http))->check('example.invalidtld');

        self::assertSame('degraded', $result->status());
        self::assertStringContainsString('trouble looking up registration details for this TLD', $result->message());
    }
}

final class FakeHttpClient
{
    /** @var array<string, array{0:int, 1:string}> */
    private array $responses;

    /** @var list<string> */
    private array $requestedUrls = [];

    /** @param array<string, array{0:int, 1:string}> $responses */
    public function __construct(array $responses)
    {
        $this->responses = $responses;
    }

    /** @return array{status:int, body:string} */
    public function get(string $url): array
    {
        $this->requestedUrls[] = $url;
        [$status, $body] = $this->responses[$url] ?? [500, ''];

        return ['status' => $status, 'body' => $body];
    }

    /** @return list<string> */
    public function requestedUrls(): array
    {
        return $this->requestedUrls;
    }
}
