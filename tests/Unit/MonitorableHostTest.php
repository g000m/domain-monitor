<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Domain\MonitorableHost;
use PHPUnit\Framework\TestCase;

/**
 * Task 1: MonitorableHost guard matrix.
 */
final class MonitorableHostTest extends TestCase
{
    // -----------------------------------------------------------------
    // Non-monitorable: bare hosts / no-dot names
    // -----------------------------------------------------------------

    public function test_empty_string_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable(''));
    }

    public function test_localhost_without_tld_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('localhost'));
    }

    public function test_single_label_intranet_name_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('intranet'));
    }

    public function test_single_label_foo_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('foo'));
    }

    // -----------------------------------------------------------------
    // Non-monitorable: IPv4 literals
    // -----------------------------------------------------------------

    public function test_private_ipv4_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('192.168.1.1'));
    }

    public function test_loopback_ipv4_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('127.0.0.1'));
    }

    public function test_private_class_a_ipv4_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('10.0.0.0'));
    }

    public function test_public_ipv4_is_not_monitorable(): void
    {
        // IP literals are never domain names -- even public ones.
        self::assertFalse(MonitorableHost::isMonitorable('8.8.8.8'));
    }

    // -----------------------------------------------------------------
    // Non-monitorable: IPv6 literals
    // -----------------------------------------------------------------

    public function test_bracketed_ipv6_loopback_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('[::1]'));
    }

    public function test_bare_ipv6_loopback_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('::1'));
    }

    public function test_bracketed_ipv6_with_port_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('[::1]:8080'));
    }

    public function test_full_ipv6_address_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('2001:db8::1'));
    }

    // -----------------------------------------------------------------
    // Non-monitorable: reserved / dev TLDs
    // -----------------------------------------------------------------

    public function test_site_local_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('site.local'));
    }

    public function test_site_test_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('site.test'));
    }

    public function test_example_dot_example_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('example.example'));
    }

    public function test_site_invalid_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('site.invalid'));
    }

    public function test_site_internal_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('site.internal'));
    }

    public function test_subdomain_localhost_tld_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('app.localhost'));
    }

    // -----------------------------------------------------------------
    // Non-monitorable: unknown TLDs not in PSL
    // -----------------------------------------------------------------

    public function test_unknown_tld_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('site.notarealtld'));
    }

    // -----------------------------------------------------------------
    // Non-monitorable: port / scheme stripping still results in bad host
    // -----------------------------------------------------------------

    public function test_localhost_with_port_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('localhost:8080'));
    }

    public function test_scheme_plus_localhost_is_not_monitorable(): void
    {
        self::assertFalse(MonitorableHost::isMonitorable('http://localhost'));
    }

    // -----------------------------------------------------------------
    // Monitorable: real public domains
    // -----------------------------------------------------------------

    public function test_plain_com_domain_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('example.com'));
    }

    public function test_subdomain_under_com_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('sub.example.com'));
    }

    public function test_two_part_suffix_co_uk_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('sub.example.co.uk'));
    }

    public function test_net_tld_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('example.net'));
    }

    public function test_org_tld_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('example.org'));
    }

    public function test_domain_with_port_strips_and_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('example.com:443'));
    }

    public function test_domain_with_scheme_strips_and_is_monitorable(): void
    {
        self::assertTrue(MonitorableHost::isMonitorable('https://example.com'));
    }

    public function test_punycode_domain_is_monitorable(): void
    {
        // xn--nxasmq6b.com is a valid punycode domain under .com (PSL knows 'com').
        self::assertTrue(MonitorableHost::isMonitorable('xn--nxasmq6b.com'));
    }
}
