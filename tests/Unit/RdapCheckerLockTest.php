<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Checks\RdapChecker;
use PHPUnit\Framework\TestCase;

final class RdapCheckerLockTest extends TestCase
{
    private function makeChecker(array $payload): RdapChecker
    {
        $body = json_encode($payload);
        $httpClient = new class($body) {
            private string $body;
            public function __construct(string $body) { $this->body = $body; }
            public function get(string $url): array
            {
                return ['status' => 200, 'body' => $this->body];
            }
        };

        return new RdapChecker($httpClient);
    }

    public function test_client_transfer_prohibited_means_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName' => 'example.com',
            'status'  => ['clientDeleteProhibited', 'clientTransferProhibited', 'clientUpdateProhibited'],
            'events'  => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertSame('ok', $result->status());
        self::assertTrue($result->transferLocked());
        self::assertContains('clientTransferProhibited', $result->domainStatuses());
    }

    public function test_server_transfer_prohibited_means_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName' => 'example.com',
            'status'  => ['serverTransferProhibited'],
            'events'  => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertTrue($result->transferLocked());
    }

    public function test_no_lock_statuses_means_not_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName' => 'example.com',
            'status'  => ['active'],
            'events'  => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertFalse($result->transferLocked());
        self::assertSame(['active'], $result->domainStatuses());
    }

    public function test_missing_status_key_returns_null_transfer_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName'  => 'example.com',
            'events'   => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertNull($result->transferLocked());
        self::assertSame([], $result->domainStatuses());
    }

    /**
     * Real-world RDAP (e.g. rdap.net) returns human-readable spaced forms like
     * "client transfer prohibited" instead of camelCase "clientTransferProhibited".
     * The checker must normalize both sides before comparing.
     */
    public function test_spaced_client_transfer_prohibited_means_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName'  => 'example.com',
            'status'   => ['client transfer prohibited'],
            'events'   => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertTrue($result->transferLocked(), 'Spaced form "client transfer prohibited" must be treated as locked.');
    }

    public function test_hyphenated_server_transfer_prohibited_means_locked(): void
    {
        $checker = $this->makeChecker([
            'ldhName'  => 'example.com',
            'status'   => ['server-transfer-prohibited'],
            'events'   => [],
            'entities' => [],
        ]);

        $result = $checker->check('example.com');

        self::assertTrue($result->transferLocked(), 'Hyphenated form "server-transfer-prohibited" must be treated as locked.');
    }
}
