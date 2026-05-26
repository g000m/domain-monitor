<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DomainCheckRunner
{
    /** @var object */
    private $dnsChecker;
    /** @var object */
    private $rdapChecker;
    /** @var callable */
    private $clock;

    /**
     * @param object $dnsChecker Object exposing check(string $domain): DnsResult.
     * @param object $rdapChecker Object exposing check(string $domain): RdapResult.
     * @param callable|null $clock Returns MySQL datetime string.
     */
    public function __construct($dnsChecker, $rdapChecker, ?callable $clock = null)
    {
        $this->dnsChecker = $dnsChecker;
        $this->rdapChecker = $rdapChecker;
        $this->clock = $clock ?? static function (): string {
            if (function_exists('current_time')) {
                return (string) current_time('mysql');
            }

            return gmdate('Y-m-d H:i:s');
        };
    }

    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        /** @var DnsResult $dns */
        $dns = $this->dnsChecker->check($domain);
        /** @var RdapResult $rdap */
        $rdap = $this->rdapChecker->check($domain);
        $clock = $this->clock;

        return [
            'dns_status' => $dns->status(),
            'dns_message' => $dns->message(),
            'rdap_status' => $rdap->status(),
            'rdap_message' => $rdap->message(),
            'rdap_registrar' => $rdap->registrar(),
            'rdap_expires_at' => $rdap->expiresAt(),
            'last_checked_at' => $clock(),
        ];
    }
}
