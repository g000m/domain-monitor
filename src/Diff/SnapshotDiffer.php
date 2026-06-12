<?php
declare(strict_types=1);

namespace DomainMonitor\Diff;

final class SnapshotDiffer
{
    /** @return list<SnapshotDiff> */
    public function diff(array $oldSnapshot, array $newSnapshot): array
    {
        $diffs = [];

        foreach (['apex', 'www'] as $scope) {
            foreach (['a', 'aaaa', 'cname', 'mx', 'ns'] as $recordType) {
                $old = $this->normaliseRecords($oldSnapshot['dns'][$scope][$recordType] ?? [], $recordType);
                $new = $this->normaliseRecords($newSnapshot['dns'][$scope][$recordType] ?? [], $recordType);

                if ($old === $new) {
                    continue;
                }

                $label   = strtoupper($recordType) . ' record';
                $removed = array_values(array_diff($old, $new));
                $added   = array_values(array_diff($new, $old));

                if ($old !== [] && $new !== [] && $removed !== [] && $added !== []) {
                    $diffs[] = new SnapshotDiff('dns_change', sprintf(
                        '%s changed from %s to %s.',
                        $label,
                        implode(', ', $old),
                        implode(', ', $new)
                    ), $recordType);
                    continue;
                }

                foreach ($added as $value) {
                    $diffs[] = new SnapshotDiff('dns_change', sprintf('%s added: %s.', $label, $value), $recordType);
                }

                foreach ($removed as $value) {
                    $diffs[] = new SnapshotDiff('dns_change', sprintf('%s removed: %s.', $label, $value), $recordType);
                }
            }
        }

        return $diffs;
    }

    /**
     * @param mixed $records
     * @return list<string>
     */
    private function normaliseRecords($records, string $recordType): array
    {
        if (! is_array($records)) {
            return [];
        }

        $normalised = [];
        foreach ($records as $record) {
            if ($recordType === 'mx' && is_array($record)) {
                $normalised[] = trim((string) ($record['priority'] ?? '') . ' ' . (string) ($record['host'] ?? ''));
                continue;
            }

            $normalised[] = (string) $record;
        }

        $normalised = array_values(array_filter($normalised, static fn (string $record): bool => $record !== ''));
        sort($normalised, SORT_STRING);

        return $normalised;
    }
}
