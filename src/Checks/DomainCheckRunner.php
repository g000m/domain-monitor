<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DomainCheckRunner implements CheckRunner
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
        $clock = $this->clock;
        $checkedAt = $clock();

        try {
            /** @var DnsResult $dns */
            $dns = $this->dnsChecker->check($domain);
            $dnsStatus  = $dns->status();
            $dnsMessage = $dns->message();
        } catch (\Throwable $e) {
            $dnsStatus  = 'degraded';
            $dnsMessage = $e->getMessage();
        }

        try {
            /** @var RdapResult $rdap */
            $rdap = $this->rdapChecker->check($domain);
            $rdapStatus    = $rdap->status();
            $rdapMessage   = $rdap->message();
            $rdapRegistrar = $rdap->registrar();
            $rdapExpiresAt = $rdap->expiresAt();
        } catch (\Throwable $e) {
            $rdapStatus    = 'degraded';
            $rdapMessage   = $e->getMessage();
            $rdapRegistrar = null;
            $rdapExpiresAt = null;
        }

        return [
            'dns_status'      => $dnsStatus,
            'dns_message'     => $dnsMessage,
            'rdap_status'     => $rdapStatus,
            'rdap_message'    => $rdapMessage,
            'rdap_registrar'  => $rdapRegistrar,
            'rdap_expires_at' => $rdapExpiresAt,
            'last_checked_at' => $checkedAt,
        ];
    }
}
