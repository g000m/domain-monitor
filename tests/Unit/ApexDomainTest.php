<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Domain\ApexDomain;
use PHPUnit\Framework\TestCase;

final class ApexDomainTest extends TestCase
{
    public function test_it_uses_registered_apex_for_tunnel_subdomain(): void
    {
        self::assertSame(
            'trycloudflare.com',
            ApexDomain::fromHost('expo-mel-featuring-attractions.trycloudflare.com')
        );
    }

    public function test_it_removes_common_www_prefix(): void
    {
        self::assertSame('example.com', ApexDomain::fromHost('www.example.com'));
    }

    public function test_it_keeps_two_part_public_suffix_domains_together(): void
    {
        self::assertSame('example.co.uk', ApexDomain::fromHost('shop.example.co.uk'));
    }
}
