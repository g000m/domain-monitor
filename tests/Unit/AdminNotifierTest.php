<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Notifications\AdminNotifier;
use DomainMonitor\Notifications\DomainNotifier;
use DomainMonitor\Storage\DomainRecord;
use PHPUnit\Framework\TestCase;

final class AdminNotifierTest extends TestCase
{
    public function test_it_implements_domain_notifier_interface(): void
    {
        self::assertInstanceOf(DomainNotifier::class, new AdminNotifier());
    }

    public function test_it_sends_no_mail_when_wp_mail_not_defined(): void
    {
        // wp_mail and get_option are not available in the test environment.
        // AdminNotifier must return silently rather than calling undefined functions.
        $notifier = new AdminNotifier();
        $record   = new DomainRecord(['id' => 1, 'domain' => 'example.com', 'source' => 'auto']);

        // Should not throw even when WP functions are absent.
        $notifier->notifyStatusChange($record, 'ok', 'warn');

        // If we reach this line without a fatal, the guard worked.
        $this->addToAssertionCount(1);
    }

    public function test_compose_message_subject_contains_domain_and_both_statuses(): void
    {
        $notifier = new AdminNotifier();
        $record   = new DomainRecord(['id' => 1, 'domain' => 'shop.example.com', 'source' => 'auto']);

        ['subject' => $subject] = $notifier->composeMessage($record, 'ok', 'fail');

        self::assertStringContainsString('shop.example.com', $subject);
        self::assertStringContainsString('OK', $subject);
        self::assertStringContainsString('FAIL', $subject);
    }

    public function test_compose_message_body_contains_domain_and_both_statuses(): void
    {
        $notifier = new AdminNotifier();
        $record   = new DomainRecord(['id' => 1, 'domain' => 'shop.example.com', 'source' => 'auto']);

        ['body' => $body] = $notifier->composeMessage($record, 'warn', 'fail');

        self::assertStringContainsString('shop.example.com', $body);
        self::assertStringContainsString('WARN', $body);
        self::assertStringContainsString('FAIL', $body);
    }
}

/**
 * Fake notifier for use in other tests that need to capture notifications
 * without triggering real email delivery.
 */
final class FakeNotifier implements DomainNotifier
{
    /** @var list<array{record: DomainRecord, from: string, to: string}> */
    public array $notifications = [];

    public function notifyStatusChange(DomainRecord $record, string $from, string $to): void
    {
        $this->notifications[] = [
            'record' => $record,
            'from'   => $from,
            'to'     => $to,
        ];
    }
}
