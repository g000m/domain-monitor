<?php
declare(strict_types=1);

namespace DomainMonitor\Checks;

/**
 * Value object holding the result of an email DNS health check.
 *
 * Covers SPF, DMARC, DKIM (informational), and MX presence.
 */
final class EmailDnsResult
{
    // SPF states.
    public const SPF_PRESENT  = 'present';
    public const SPF_MISSING  = 'missing';
    public const SPF_MULTIPLE = 'multiple';
    public const SPF_INVALID  = 'invalid';

    // DMARC states.
    public const DMARC_PRESENT = 'present';
    public const DMARC_MISSING = 'missing';
    public const DMARC_INVALID = 'invalid';

    // MX states.
    public const MX_PRESENT = 'present';
    public const MX_MISSING = 'missing';

    private string $spfState;
    private string $spfRecord;
    private string $dmarcState;
    private string $dmarcRecord;
    private string $mxState;
    /** @var list<string> */
    private array $dkimFoundSelectors;
    private string $message;

    /**
     * @param list<string> $dkimFoundSelectors Selectors for which a DKIM TXT record was found.
     */
    public function __construct(
        string $spfState,
        string $spfRecord,
        string $dmarcState,
        string $dmarcRecord,
        string $mxState,
        array $dkimFoundSelectors = [],
        string $message = ''
    ) {
        $this->spfState           = $spfState;
        $this->spfRecord          = $spfRecord;
        $this->dmarcState         = $dmarcState;
        $this->dmarcRecord        = $dmarcRecord;
        $this->mxState            = $mxState;
        $this->dkimFoundSelectors = $dkimFoundSelectors;
        $this->message            = $message;
    }

    public function spfState(): string
    {
        return $this->spfState;
    }

    /**
     * The first SPF TXT record found (empty string if none).
     */
    public function spfRecord(): string
    {
        return $this->spfRecord;
    }

    public function dmarcState(): string
    {
        return $this->dmarcState;
    }

    /**
     * The DMARC TXT record value (empty string if none).
     */
    public function dmarcRecord(): string
    {
        return $this->dmarcRecord;
    }

    public function mxState(): string
    {
        return $this->mxState;
    }

    /**
     * Selectors for which a DKIM TXT record was discovered.
     * An empty list means none of the common selectors matched — this is
     * informational ("unknown"), not a failure.
     *
     * @return list<string>
     */
    public function dkimFoundSelectors(): array
    {
        return $this->dkimFoundSelectors;
    }

    public function message(): string
    {
        return $this->message;
    }

    /**
     * Overall health status as a machine string (ok / warn / degraded).
     *
     * - ok:      SPF present, DMARC present (any policy including none), MX present or absent.
     * - warn:    SPF missing, DMARC missing, multiple SPF, or no MX records.
     * - degraded: SPF invalid record syntax.
     *
     * DKIM absence is never a warning — it is informational only.
     */
    public function overallStatus(): string
    {
        if ($this->spfState === self::SPF_INVALID) {
            return 'degraded';
        }

        if (
            $this->spfState === self::SPF_MISSING
            || $this->spfState === self::SPF_MULTIPLE
            || $this->dmarcState === self::DMARC_MISSING
            || $this->dmarcState === self::DMARC_INVALID
            || $this->mxState === self::MX_MISSING
        ) {
            return 'warn';
        }

        return 'ok';
    }

    /**
     * Serialise to an array for snapshot embedding.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'spf_state'             => $this->spfState,
            'spf_record'            => $this->spfRecord,
            'dmarc_state'           => $this->dmarcState,
            'dmarc_record'          => $this->dmarcRecord,
            'mx_state'              => $this->mxState,
            'dkim_found_selectors'  => $this->dkimFoundSelectors,
            'message'               => $this->message,
            'status'                => $this->overallStatus(),
        ];
    }
}
