<?php
declare(strict_types=1);

namespace DomainMonitor\Tests\Unit;

use DomainMonitor\Diff\SnapshotDiffer;
use PHPUnit\Framework\TestCase;

final class SnapshotDifferTest extends TestCase
{
    public function test_it_reports_named_a_record_replacement(): void
    {
        $old = ['dns' => ['apex' => ['a' => ['203.0.113.10']]]];
        $new = ['dns' => ['apex' => ['a' => ['198.51.100.5']]]];

        $diffs = (new SnapshotDiffer())->diff($old, $new);

        self::assertCount(1, $diffs);
        self::assertSame('dns_change', $diffs[0]->type());
        self::assertStringContainsString('A record changed', $diffs[0]->message());
        self::assertStringContainsString('203.0.113.10', $diffs[0]->message());
        self::assertStringContainsString('198.51.100.5', $diffs[0]->message());
    }

    public function test_it_reports_named_mx_record_addition(): void
    {
        $old = ['dns' => ['apex' => ['mx' => []]]];
        $new = ['dns' => ['apex' => ['mx' => [['priority' => 10, 'host' => 'mail.example.com']]]]];

        $diffs = (new SnapshotDiffer())->diff($old, $new);

        self::assertCount(1, $diffs);
        self::assertSame('dns_change', $diffs[0]->type());
        self::assertStringContainsString('MX record added', $diffs[0]->message());
        self::assertStringContainsString('10 mail.example.com', $diffs[0]->message());
    }

    public function test_it_ignores_record_order_changes(): void
    {
        $old = ['dns' => ['apex' => ['a' => ['203.0.113.10', '198.51.100.5']]]];
        $new = ['dns' => ['apex' => ['a' => ['198.51.100.5', '203.0.113.10']]]];

        $diffs = (new SnapshotDiffer())->diff($old, $new);

        self::assertSame([], $diffs);
    }

    public function test_it_skips_txt_records_for_v1(): void
    {
        $old = ['dns' => ['apex' => ['txt' => []]]];
        $new = ['dns' => ['apex' => ['txt' => ['v=spf1 include:example.com ~all']]]];

        $diffs = (new SnapshotDiffer())->diff($old, $new);

        self::assertSame([], $diffs);
    }
}
