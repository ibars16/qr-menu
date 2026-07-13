<?php

namespace App\Service\Classification;

use App\Entity\ClassificationLog;

/**
 * Human-readable export of pending ClassificationLog rows, and the reverse:
 * reading back which rows a human marked approved/rejected in that file.
 * Generic across every classification type — the file only ever carries a
 * log_id plus display context, never anything the import step trusts for
 * more than a log_id lookup.
 */
final class ClassificationReviewFileService
{
    private const COLUMNS = ['log_id', 'subject_code', 'subject_name', 'label', 'attributes', 'confidence', 'status', 'decision', 'notes'];

    /**
     * @param ClassificationLog[] $logs
     */
    public function export(array $logs, ClassificationTaskInterface $task, string $path): int
    {
        $rows = [];
        foreach ($logs as $log) {
            $subject = $task->getSubjectById($log->getSubjectId());
            $rows[] = [
                'log_id'       => $log->getId(),
                'subject_code' => $subject ? $task->getSubjectCode($subject) : '(deleted #' . $log->getSubjectId() . ')',
                'subject_name' => $subject ? $task->getSubjectDisplayText($subject) : '',
                'label'        => $log->getLabel() ?? '',
                'attributes'   => $log->getAttributes() ? json_encode($log->getAttributes()) : '',
                'confidence'   => $log->getConfidence() !== null ? round($log->getConfidence() * 100) . '%' : '',
                'status'       => $log->getStatus()->value,
                'decision'     => '',
                'notes'        => '',
            ];
        }

        $this->write($path, $rows);

        return count($rows);
    }

    /**
     * @return array<int, array{decision: ?string, notes: ?string}> log_id => decision
     */
    public function readDecisions(string $path): array
    {
        $rows = $this->read($path);
        $decisions = [];

        foreach ($rows as $row) {
            $logId = (int) ($row['log_id'] ?? 0);
            if ($logId <= 0) {
                continue;
            }

            $decisions[$logId] = [
                'decision' => $this->normalizeDecision($row['decision'] ?? ''),
                'notes'    => trim((string) ($row['notes'] ?? '')) ?: null,
            ];
        }

        return $decisions;
    }

    private function normalizeDecision(string $raw): ?string
    {
        $v = strtolower(trim($raw));

        return match (true) {
            in_array($v, ['approve', 'approved', 'yes', 'y', '1'], true) => 'approve',
            in_array($v, ['reject', 'rejected', 'no', 'n', '0'], true)   => 'reject',
            default => null,
        };
    }

    private function write(string $path, array $rows): void
    {
        if (str_ends_with($path, '.json')) {
            file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return;
        }

        $handle = fopen($path, 'w');
        fputcsv($handle, self::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($col) => $row[$col] ?? '', self::COLUMNS));
        }
        fclose($handle);
    }

    private function read(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Cannot read review file: {$path}");
        }

        if (str_ends_with($path, '.json')) {
            $decoded = json_decode(file_get_contents($path), true);
            return is_array($decoded) ? $decoded : [];
        }

        $rows = [];
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle, escape: '\\');
        if ($header === false) {
            fclose($handle);
            return [];
        }

        while (($cols = fgetcsv($handle, escape: '\\')) !== false) {
            if (count($cols) !== count($header)) {
                continue;
            }
            $rows[] = array_combine($header, $cols);
        }
        fclose($handle);

        return $rows;
    }
}
