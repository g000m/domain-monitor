<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Domain\DomainName;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class DomainNameTest extends TestCase
{
    public function test_it_normalizes_url_input_to_lowercase_host(): void
    {
        $domain = DomainName::fromUserInput('HTTPS://Example.COM/some/path?x=1');

        self::assertSame('example.com', $domain->toString());
    }

    public function test_it_preserves_www_when_www_is_the_actual_host(): void
    {
        $domain = DomainName::fromUserInput('https://www.Example.com/');

        self::assertSame('www.example.com', $domain->toString());
    }

    public function test_it_removes_trailing_dot_for_comparison(): void
    {
        $domain = DomainName::fromUserInput('Example.com.');

        self::assertSame('example.com', $domain->toString());
    }

    public function test_it_rejects_empty_input(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Domain is required');

        DomainName::fromUserInput('   ');
    }

    public function test_it_rejects_invalid_hostnames(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid domain');

        DomainName::fromUserInput('not a domain');
    }

    public function test_it_rejects_domains_longer_than_253_characters(): void
    {
        $tooLong = str_repeat('a', 250) . '.com';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('253 characters');

        DomainName::fromUserInput($tooLong);
    }
}
