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

    // --- PSL-backed tests ---

    public function test_plain_dot_com(): void
    {
        self::assertSame('example.com', ApexDomain::fromHost('example.com'));
    }

    public function test_subdomain_under_plain_com(): void
    {
        self::assertSame('example.com', ApexDomain::fromHost('sub.example.com'));
    }

    public function test_gov_uk_two_part_suffix(): void
    {
        self::assertSame('example.gov.uk', ApexDomain::fromHost('www.example.gov.uk'));
    }

    public function test_ltd_uk_two_part_suffix(): void
    {
        self::assertSame('example.ltd.uk', ApexDomain::fromHost('shop.example.ltd.uk'));
    }

    public function test_com_sg_two_part_suffix(): void
    {
        self::assertSame('example.com.sg', ApexDomain::fromHost('sub.example.com.sg'));
    }

    public function test_co_in_two_part_suffix(): void
    {
        self::assertSame('example.co.in', ApexDomain::fromHost('sub.example.co.in'));
    }

    public function test_wildcard_ck_rule(): void
    {
        // *.ck is a wildcard rule: bar.ck is itself a public suffix, so
        // foo.bar.ck is an apex domain.
        self::assertSame('foo.bar.ck', ApexDomain::fromHost('sub.foo.bar.ck'));
    }

    public function test_wildcard_exception_www_ck(): void
    {
        // !www.ck is an exception to the *.ck wildcard: www.ck is NOT a public suffix,
        // it is itself a registrable domain. A subdomain of www.ck should resolve to
        // www.ck as the apex (exception entry returned as-is).
        self::assertSame('www.ck', ApexDomain::fromHost('sub.www.ck'));
    }
}
