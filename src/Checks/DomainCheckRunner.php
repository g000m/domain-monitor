<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

final class DomainCheckRunner implements CheckRunner
{
    /** @var object */
    private $dnsChecker;
    /** @var object */
    private $rdapChecker;
    /** @var object|null */
    private $sslChecker;
    /** @var object|null */
    private $emailDnsChecker;
    /** @var callable */
    private $clock;

    /**
     * @param object        $dnsChecker       Object exposing check(string $domain): DnsResult.
     * @param object        $rdapChecker      Object exposing check(string $domain): RdapResult.
     * @param object|callable|null $sslCheckerOrClock Object exposing check(string $domain): SslResult,
     *                             OR (legacy) a callable clock for backward-compat with existing tests.
     * @param callable|null $clock            Returns MySQL datetime string (when sslChecker is provided explicitly).
     * @param object|null   $emailDnsChecker  Object exposing check(string $domain): EmailDnsResult. Null = disabled.
     */
    public function __construct($dnsChecker, $rdapChecker, $sslCheckerOrClock = null, ?callable $clock = null, $emailDnsChecker = null)
    {
        $this->dnsChecker      = $dnsChecker;
        $this->rdapChecker     = $rdapChecker;
        $this->emailDnsChecker = $emailDnsChecker;

        // Back-compat: if arg 3 is callable it is the old clock, not an ssl checker.
        if (is_callable($sslCheckerOrClock)) {
            $this->sslChecker = null;
            $this->clock      = $sslCheckerOrClock;
        } else {
            $this->sslChecker = $sslCheckerOrClock;
            $this->clock      = $clock ?? static function (): string {
                if (function_exists('current_time')) {
                    return (string) current_time('mysql');
                }

                return gmdate('Y-m-d H:i:s');
            };
        }
    }

    /** @return array<string,string|null> */
    public function check(string $domain): array
    {
        $clock = $this->clock;
        $checkedAt = $clock();

        $dnsApex = null;
        try {
            /** @var DnsResult $dns */
            $dns = $this->dnsChecker->check($domain);
            $dnsStatus  = $dns->status();
            $dnsMessage = $dns->message();
            $records    = $dns->records();
            if ($records !== []) {
                $dnsApex = json_encode($records);
            }
        } catch (\Throwable $e) {
            $dnsStatus  = 'degraded';
            $dnsMessage = $e->getMessage();
        }

        try {
            /** @var RdapResult $rdap */
            $rdap = $this->rdapChecker->check($domain);
            $rdapStatus         = $rdap->status();
            $rdapMessage        = $rdap->message();
            $rdapRegistrar      = $rdap->registrar();
            $rdapExpiresAt      = $rdap->expiresAt();
            $rdapTransferLocked = $rdap->transferLocked();
            $rdapDomainStatuses = $rdap->domainStatuses();
        } catch (\Throwable $e) {
            $rdapStatus         = 'degraded';
            $rdapMessage        = $e->getMessage();
            $rdapRegistrar      = null;
            $rdapExpiresAt      = null;
            $rdapTransferLocked = null;
            $rdapDomainStatuses = [];
        }

        $sslStatus    = null;
        $sslMessage   = null;
        $sslExpiresAt = null;
        $sslIssuer    = null;

        if ($this->sslChecker !== null) {
            try {
                /** @var SslResult $ssl */
                $ssl          = $this->sslChecker->check($domain);
                $sslStatus    = $ssl->status();
                $sslMessage   = $ssl->message();
                $sslExpiresAt = $ssl->expiresAt();
                $sslIssuer    = $ssl->issuer();
            } catch (\Throwable $e) {
                $sslStatus  = 'degraded';
                $sslMessage = $e->getMessage();
            }
        }

        // Email DNS check (opt-in; null checker means disabled).
        $emailDnsData = null;
        if ($this->emailDnsChecker !== null) {
            try {
                /** @var EmailDnsResult $emailDns */
                $emailDns     = $this->emailDnsChecker->check($domain);
                $emailDnsData = json_encode($emailDns->toArray());
            } catch (\Throwable $e) {
                $emailDnsData = json_encode([
                    'status'  => 'degraded',
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $result = [
            'dns_status'           => $dnsStatus,
            'dns_message'          => $dnsMessage,
            'rdap_status'          => $rdapStatus,
            'rdap_message'         => $rdapMessage,
            'rdap_registrar'       => $rdapRegistrar,
            'rdap_expires_at'      => $rdapExpiresAt,
            'rdap_transfer_locked' => $rdapTransferLocked !== null ? ($rdapTransferLocked ? '1' : '0') : null,
            'rdap_domain_statuses' => $rdapDomainStatuses !== [] ? json_encode($rdapDomainStatuses) : null,
            'ssl_status'           => $sslStatus,
            'ssl_message'          => $sslMessage,
            'ssl_expires_at'       => $sslExpiresAt,
            'ssl_issuer'           => $sslIssuer,
            'last_checked_at'      => $checkedAt,
        ];

        if ($dnsApex !== null) {
            $result['dns_apex'] = $dnsApex;
        }

        if ($emailDnsData !== null) {
            $result['email_dns'] = $emailDnsData;
        }

        return $result;
    }
}
