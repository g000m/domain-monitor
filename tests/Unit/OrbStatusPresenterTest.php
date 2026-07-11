<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Admin\OrbStatusPresenter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OrbStatusPresenter: colour derivation and fact-line priority ordering.
 */
final class OrbStatusPresenterTest extends TestCase
{
    private OrbStatusPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new OrbStatusPresenter();
    }

    // -----------------------------------------------------------------
    // Colour mapping from status code
    // -----------------------------------------------------------------

    public function test_empty_status_maps_to_gray(): void
    {
        $row = ['status' => '', 'domain' => 'example.com'];
        self::assertSame(OrbStatusPresenter::COLOR_GRAY, $this->presenter->color($row));
    }

    public function test_ok_status_maps_to_green(): void
    {
        $row = ['status' => 'ok', 'domain' => 'example.com'];
        self::assertSame(OrbStatusPresenter::COLOR_GREEN, $this->presenter->color($row));
    }

    public function test_warn_status_maps_to_amber(): void
    {
        $row = ['status' => 'warn', 'domain' => 'example.com'];
        self::assertSame(OrbStatusPresenter::COLOR_AMBER, $this->presenter->color($row));
    }

    public function test_fail_status_maps_to_red(): void
    {
        $row = ['status' => 'fail', 'domain' => 'example.com'];
        self::assertSame(OrbStatusPresenter::COLOR_RED, $this->presenter->color($row));
    }

    // -----------------------------------------------------------------
    // factLine() returns empty for non-alerting states
    // -----------------------------------------------------------------

    public function test_green_returns_empty_fact_line(): void
    {
        $row = ['status' => 'ok', 'checked_at' => '2026-06-01 12:00:00'];
        self::assertSame('', $this->presenter->factLine($row));
    }

    public function test_gray_returns_empty_fact_line(): void
    {
        $row = ['status' => ''];
        self::assertSame('', $this->presenter->factLine($row));
    }

    // -----------------------------------------------------------------
    // Priority 1: Domain registration expired beats everything
    // -----------------------------------------------------------------

    public function test_expired_domain_beats_ssl_expiring_soon(): void
    {
        $row = [
            'status'          => 'fail',
            'message'         => 'Domain registration appears to be expired.',
            'rdap_expires_at' => '2020-01-01T00:00:00Z', // expired in the past
            'ssl_expires_at'  => gmdate('Y-m-d\TH:i:s\Z', strtotime('+5 days')), // expiring soon
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Domain registration expired', $fact);
    }

    public function test_expired_domain_beats_open_ns_hijack_alert(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => '2020-06-01T00:00:00Z', // expired
            'ssl_expires_at'   => '',
            'open_alert_types' => ['ns_changed'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Domain registration expired', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 2: SSL expired beats NS alert
    // -----------------------------------------------------------------

    public function test_expired_ssl_beats_ns_hijack_alert(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => '', // no domain expiry issue
            'ssl_expires_at'   => '2020-01-01T00:00:00Z', // expired
            'open_alert_types' => ['ns_changed'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('SSL certificate expired', $fact);
    }

    public function test_expired_ssl_beats_transfer_lock_removed(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '2019-12-31T00:00:00Z',
            'open_alert_types' => ['transfer_lock_removed'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('SSL certificate expired', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 3: NS hijack alert beats domain expiring soon
    // -----------------------------------------------------------------

    public function test_ns_hijack_alert_beats_domain_expiring_in_20_days(): void
    {
        $soonExpiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+20 days'));

        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => $soonExpiry,
            'ssl_expires_at'   => '',
            'open_alert_types' => ['ns_changed'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Nameserver change detected — possible hijack', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 4: Domain expiring soon (within amber threshold)
    // -----------------------------------------------------------------

    public function test_domain_expiring_in_25_days_shown(): void
    {
        $expiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+25 days'));

        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => $expiry,
            'ssl_expires_at'   => '',
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertStringContainsString('Domain expires in', $fact);
        self::assertStringContainsString('25 days', $fact);
    }

    public function test_domain_expiring_in_1_day_uses_singular(): void
    {
        $expiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+1 day'));

        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => $expiry,
            'ssl_expires_at'   => '',
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertStringContainsString('1 day', $fact);
        self::assertStringNotContainsString('1 days', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 4 > Priority 5: Domain expiring beats SSL expiring
    // -----------------------------------------------------------------

    public function test_domain_expiring_beats_ssl_expiring(): void
    {
        $domainExpiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+10 days'));
        $sslExpiry    = gmdate('Y-m-d\TH:i:s\Z', strtotime('+5 days'));

        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => $domainExpiry,
            'ssl_expires_at'   => $sslExpiry,
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertStringContainsString('Domain expires in', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 5: SSL expiring
    // -----------------------------------------------------------------

    public function test_ssl_expiring_in_10_days_shown_when_no_domain_expiry(): void
    {
        $sslExpiry = gmdate('Y-m-d\TH:i:s\Z', strtotime('+10 days'));

        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => $sslExpiry,
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertStringContainsString('SSL expires in', $fact);
        self::assertStringContainsString('10 days', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 6: Transfer lock removed
    // -----------------------------------------------------------------

    public function test_transfer_lock_removed_shown_when_no_expiry_or_ns_alert(): void
    {
        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '',
            'open_alert_types' => ['transfer_lock_removed'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Registrar transfer lock removed', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 7: SPF / DMARC regression
    // -----------------------------------------------------------------

    public function test_spf_missing_shown_when_no_higher_priority_issue(): void
    {
        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '',
            'open_alert_types' => ['spf_missing'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('SPF record missing', $fact);
    }

    public function test_dmarc_missing_shown_when_no_spf_alert(): void
    {
        $row = [
            'status'           => 'warn',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '',
            'open_alert_types' => ['dmarc_missing'],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('DMARC record missing', $fact);
    }

    // -----------------------------------------------------------------
    // Priority 8: Generic fallback
    // -----------------------------------------------------------------

    public function test_generic_fallback_uses_status_calculator_message(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => 'Rdap check failed.',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '',
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Rdap check failed.', $fact);
    }

    public function test_generic_fallback_uses_check_failed_when_no_message(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => '',
            'ssl_expires_at'   => '',
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Check failed', $fact);
    }

    // -----------------------------------------------------------------
    // expires_at alias (backward compatibility for legacy row shape)
    // -----------------------------------------------------------------

    public function test_expires_at_alias_used_when_rdap_expires_at_absent(): void
    {
        $row = [
            'status'     => 'fail',
            'message'    => '',
            'expires_at' => '2020-01-01T00:00:00Z', // alias key, no rdap_expires_at
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Domain registration expired', $fact);
    }

    // -----------------------------------------------------------------
    // Priority: expired domain (P1) beats ssl expired (P2)
    // -----------------------------------------------------------------

    public function test_expired_domain_beats_expired_ssl(): void
    {
        $row = [
            'status'           => 'fail',
            'message'          => '',
            'rdap_expires_at'  => '2020-01-01T00:00:00Z',
            'ssl_expires_at'   => '2019-06-01T00:00:00Z',
            'open_alert_types' => [],
        ];

        $fact = $this->presenter->factLine($row);
        self::assertSame('Domain registration expired', $fact);
    }

    // -----------------------------------------------------------------
    // checkedAgoText fallback when human_time_diff is unavailable
    // -----------------------------------------------------------------

    public function test_checked_ago_text_returns_empty_for_empty_checked_at(): void
    {
        $row = ['checked_at' => ''];
        self::assertSame('', $this->presenter->checkedAgoText($row));
    }

    public function test_checked_ago_text_returns_non_empty_for_recent_timestamp(): void
    {
        // Use a timestamp 2 hours ago so the plain fallback returns something.
        $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
        $row = ['checked_at' => $twoHoursAgo];
        $text = $this->presenter->checkedAgoText($row);
        self::assertNotSame('', $text);
        self::assertStringContainsString('ago', $text);
    }
}
