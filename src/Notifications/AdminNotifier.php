<?php
declare(strict_types=1);

namespace DomainMonitor\Notifications;

use DomainMonitor\Storage\DomainRecord;

/**
 * Sends an email to the site admin via wp_mail when a domain's status
 * transitions to a worse state.
 */
final class AdminNotifier implements DomainNotifier
{
    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        if (! function_exists('wp_mail') || ! function_exists('get_option')) {
            return;
        }

        $adminEmail = (string) get_option('admin_email', '');
        if ($adminEmail === '') {
            return;
        }

        ['subject' => $subject, 'body' => $body] = $this->composeMessage($record, $from, $to);

        wp_mail($adminEmail, $subject, $body);
    }

    /**
     * Compose the subject and body strings for a status-change notification.
     * Pure function: no WP calls, safe to call in tests.
     *
     * @return array{subject: string, body: string}
     */
    public function composeMessage(DomainRecord $record, string $from, string $to): array
    {
        $domain = $record->domain();

        // Subject: translate the template, then sprintf the data values in.
        $subjectTemplate = $this->translate('[Domain Monitor] %s status changed: %s to %s');
        $subject = sprintf($subjectTemplate, $domain, strtoupper($from), strtoupper($to));

        // Body: each translatable segment is wrapped independently so translators
        // can reorder the label/value pairs for their locale.
        $body  = $this->translate('Domain Monitor has detected a status change for one of your monitored domains.') . "\n\n";
        $body .= sprintf($this->translate('Domain  : %s') . "\n", $domain);
        $body .= sprintf($this->translate('Previous: %s') . "\n", strtoupper($from));
        $body .= sprintf($this->translate('Current : %s') . "\n", strtoupper($to));

        $reason = $record->statusReason();
        if ($reason !== '') {
            $body .= sprintf($this->translate('Reason  : %s') . "\n", $reason);
        }

        $body .= "\n" . $this->translate('Log in to your WordPress admin to review the domain status.');

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Translate a string using the plugin text domain.
     * Falls back to the raw string when the WP i18n functions are not available
     * (e.g. in unit tests that run outside WordPress).
     */
    private function translate(string $text): string
    {
        if (function_exists('__')) {
            // @phan-suppress-next-line PhanUndeclaredFunction
            return __($text, 'domain-monitor');
        }

        return $text;
    }
}
