<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

use InvalidArgumentException;

final class DomainName
{
    private string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    public static function fromUserInput(string $input): self
    {
        $input = trim($input);

        if ($input === '') {
            throw new InvalidArgumentException('Domain is required.');
        }

        $host = self::extractHost($input);
        $host = strtolower(rtrim($host, '.'));

        if ($host === '') {
            throw new InvalidArgumentException('Domain is required.');
        }

        if (strlen($host) > 253) {
            throw new InvalidArgumentException('Domain must be 253 characters or fewer.');
        }

        if (! self::isValidHostname($host)) {
            throw new InvalidArgumentException('Please enter a valid domain.');
        }

        return new self($host);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->toString();
    }

    private static function extractHost(string $input): string
    {
        if (preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $input) === 1) {
            $host = parse_url($input, PHP_URL_HOST);
            return is_string($host) ? $host : '';
        }

        $withoutPath = preg_split('/[\/?#]/', $input, 2)[0];
        return trim((string) $withoutPath);
    }

    private static function isValidHostname(string $host): bool
    {
        if (strpos($host, '.') === false) {
            return false;
        }

        $labels = explode('.', $host);
        foreach ($labels as $label) {
            $length = strlen($label);
            if ($length < 1 || $length > 63) {
                return false;
            }

            if (preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                return false;
            }
        }

        $tld = $labels[count($labels) - 1];
        return preg_match('/[a-z]/', $tld) === 1;
    }
}
