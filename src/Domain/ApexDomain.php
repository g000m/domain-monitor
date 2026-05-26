<?php
declare(strict_types=1);

namespace DomainMonitor\Domain;

final class ApexDomain
{
    /** @var array<string,true> */
    private const COMMON_TWO_PART_PUBLIC_SUFFIXES = [
        'co.uk' => true,
        'org.uk' => true,
        'ac.uk' => true,
        'com.au' => true,
        'net.au' => true,
        'org.au' => true,
        'co.nz' => true,
        'com.br' => true,
        'com.mx' => true,
        'co.jp' => true,
    ];

    public static function fromHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('/^https?:\/\//', '', $host) ?? $host;
        $host = preg_replace('/[\/?#].*$/', '', $host) ?? $host;
        $host = preg_replace('/:\d+$/', '', $host) ?? $host;
        $host = trim($host, '.');

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        $labels = array_values(array_filter(explode('.', $host), static fn (string $label): bool => $label !== ''));
        $count = count($labels);

        if ($count <= 2) {
            return implode('.', $labels);
        }

        $lastTwo = $labels[$count - 2] . '.' . $labels[$count - 1];
        if (isset(self::COMMON_TWO_PART_PUBLIC_SUFFIXES[$lastTwo]) && $count >= 3) {
            return $labels[$count - 3] . '.' . $lastTwo;
        }

        return $lastTwo;
    }
}
