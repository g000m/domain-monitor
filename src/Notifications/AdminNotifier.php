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
        $domain  = $record->domain();
        $subject = sprintf('[Domain Monitor] %s status changed: %s to %s', $domain, strtoupper($from), strtoupper($to));

        $body  = "Domain Monitor has detected a status change for one of your monitored domains.\n\n";
        $body .= sprintf("Domain  : %s\n", $domain);
        $body .= sprintf("Previous: %s\n", strtoupper($from));
        $body .= sprintf("Current : %s\n", strtoupper($to));

        $reason = $record->statusReason();
        if ($reason !== '') {
            $body .= sprintf("Reason  : %s\n", $reason);
        }

        $body .= "\nLog in to your WordPress admin to review the domain status.";

        return ['subject' => $subject, 'body' => $body];
    }
}
