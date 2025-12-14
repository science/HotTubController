<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use RuntimeException;

/**
 * Real crontab adapter that executes system commands.
 *
 * IMPORTANT: Uses file-based crontab manipulation instead of pipes.
 * This is required for compatibility with CloudLinux CageFS and other
 * virtualized hosting environments where pipe-based crontab commands
 * are unreliable.
 *
 * Also implements pre/post verification to detect unexpected crontab
 * modifications (belt-and-suspenders approach after two wipe bugs).
 */
class CrontabAdapter implements CrontabAdapterInterface
{
    private ?string $criticalLogFile = null;

    public function __construct(
        private ?CrontabBackupService $backupService = null,
        ?string $criticalLogFile = null
    ) {
        // Default critical log location
        $this->criticalLogFile = $criticalLogFile
            ?? dirname(__DIR__, 2) . '/storage/logs/crontab-critical.log';
    }

    /**
     * Log a critical crontab error.
     *
     * These are "kernel panic" level events - something went very wrong
     * with crontab manipulation and the system may be in an inconsistent state.
     */
    private function logCritical(string $message, array $context = []): void
    {
        $logDir = dirname($this->criticalLogFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => 'CRITICAL',
            'message' => $message,
            'context' => $context,
        ];

        @file_put_contents(
            $this->criticalLogFile,
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Backup the current crontab before modification.
     * Does nothing if no backup service is configured.
     */
    private function backupBeforeModify(): void
    {
        if ($this->backupService === null) {
            return;
        }

        try {
            $entries = $this->listEntries();
            if (!empty($entries)) {
                $content = implode("\n", $entries) . "\n";
                $this->backupService->backup($content);
            }
        } catch (RuntimeException $e) {
            // If we can't read the crontab, don't fail the backup
            // The modification will handle errors appropriately
        }
    }

    /**
     * Generate a unique temp file path for crontab operations.
     */
    private function getTempFile(string $suffix = ''): string
    {
        return sys_get_temp_dir() . '/crontab_' . getmypid() . '_' . microtime(true) . $suffix;
    }

    /**
     * Save current crontab to a temp file for diff comparison.
     *
     * @return string Path to temp file containing crontab snapshot
     */
    private function saveCrontabSnapshot(string $suffix): string
    {
        $tempFile = $this->getTempFile($suffix);
        $entries = $this->listEntries();
        $content = !empty($entries) ? implode("\n", $entries) . "\n" : '';
        file_put_contents($tempFile, $content);
        return $tempFile;
    }

    /**
     * Run diff on two files and return only the changed lines.
     *
     * Uses unified diff format with context stripped to show only:
     * - Lines starting with '+' (added, not including +++ header)
     * - Lines starting with '-' (removed, not including --- header)
     *
     * @return array{added: string[], removed: string[]}
     */
    private function diffFiles(string $fileA, string $fileB): array
    {
        $output = [];
        // Use diff with no context (-U0) for minimal output
        // Exit code 0 = no diff, 1 = differences found, 2 = error
        exec(
            'diff -U0 ' . escapeshellarg($fileA) . ' ' . escapeshellarg($fileB) . ' 2>/dev/null',
            $output
        );

        $added = [];
        $removed = [];

        foreach ($output as $line) {
            // Skip headers (---, +++, @@)
            if (str_starts_with($line, '---') || str_starts_with($line, '+++') || str_starts_with($line, '@@')) {
                continue;
            }
            // Added lines start with +
            if (str_starts_with($line, '+')) {
                $added[] = substr($line, 1); // Remove the + prefix
            }
            // Removed lines start with -
            if (str_starts_with($line, '-')) {
                $removed[] = substr($line, 1); // Remove the - prefix
            }
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Install crontab from a file.
     *
     * @throws RuntimeException if installation fails
     */
    private function installFromFile(string $tempFile): void
    {
        $output = [];
        $returnCode = 0;
        exec("crontab " . escapeshellarg($tempFile) . " 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException(
                'Failed to install crontab from file: ' . implode("\n", $output)
            );
        }
    }

    public function addEntry(string $entry): void
    {
        // Backup before modification
        $this->backupBeforeModify();

        // PRE: Save crontab snapshot for diff comparison
        $snapshotBefore = $this->saveCrontabSnapshot('_before');

        // FILE-BASED APPROACH: Write to temp file, then install
        // This is required for CloudLinux CageFS compatibility where
        // pipe-based crontab commands are unreliable.
        $tempFile = $this->getTempFile('_install');
        $snapshotAfter = null;

        try {
            // Read current entries and append new entry
            $entriesBefore = $this->listEntries();
            $content = !empty($entriesBefore)
                ? implode("\n", $entriesBefore) . "\n"
                : '';
            $content .= $entry . "\n";

            if (file_put_contents($tempFile, $content) === false) {
                throw new RuntimeException('Failed to write crontab temp file');
            }

            // Install from file
            $this->installFromFile($tempFile);

            // POST: Save crontab snapshot and verify via diff
            $snapshotAfter = $this->saveCrontabSnapshot('_after');
            $this->verifyAdditionViaDiff($snapshotBefore, $snapshotAfter, $entry);

        } finally {
            // Always clean up temp files
            @unlink($tempFile);
            @unlink($snapshotBefore);
            if ($snapshotAfter !== null) {
                @unlink($snapshotAfter);
            }
        }
    }

    /**
     * Verify addEntry() via external diff command.
     *
     * Uses Unix diff to compare before/after snapshots. This is more reliable
     * than PHP array comparisons because:
     * 1. External tool verification (not PHP checking its own work)
     * 2. Battle-tested diff utility
     * 3. Diff output is excellent forensic evidence
     */
    private function verifyAdditionViaDiff(string $fileBefore, string $fileAfter, string $expectedEntry): void
    {
        $diff = $this->diffFiles($fileBefore, $fileAfter);

        // For addEntry: expect exactly one added line, zero removed lines
        $addedCount = count($diff['added']);
        $removedCount = count($diff['removed']);

        // Check for unexpected removals
        if ($removedCount > 0) {
            $this->logCritical('CRONTAB_ADD_UNEXPECTED_REMOVAL', [
                'operation' => 'addEntry',
                'expected_entry' => $expectedEntry,
                'unexpected_removed' => $diff['removed'],
                'diff_added' => $diff['added'],
            ]);
            return;
        }

        // Check we added exactly one line
        if ($addedCount !== 1) {
            $this->logCritical('CRONTAB_ADD_WRONG_COUNT', [
                'operation' => 'addEntry',
                'expected_entry' => $expectedEntry,
                'expected_added_count' => 1,
                'actual_added_count' => $addedCount,
                'diff_added' => $diff['added'],
            ]);
            return;
        }

        // Check the added line matches what we expected
        $actualAdded = $diff['added'][0];
        if ($actualAdded !== $expectedEntry) {
            $this->logCritical('CRONTAB_ADD_WRONG_ENTRY', [
                'operation' => 'addEntry',
                'expected_entry' => $expectedEntry,
                'actual_added' => $actualAdded,
            ]);
        }
    }

    public function removeByPattern(string $pattern): void
    {
        // Backup before modification
        $this->backupBeforeModify();

        // Identify entries to remove before taking snapshot
        $entriesBefore = $this->listEntries();
        $entriesToRemove = array_filter(
            $entriesBefore,
            fn($entry) => strpos($entry, $pattern) !== false
        );

        // If no entries match, nothing to remove - don't touch crontab at all
        if (empty($entriesToRemove)) {
            return;
        }

        $entriesToKeep = array_filter(
            $entriesBefore,
            fn($entry) => strpos($entry, $pattern) === false
        );

        // PRE: Save crontab snapshot for diff comparison
        $snapshotBefore = $this->saveCrontabSnapshot('_before');

        // FILE-BASED APPROACH: Write filtered entries to temp file, then install
        $tempFile = $this->getTempFile('_install');
        $snapshotAfter = null;

        try {
            // Write entries to keep (or empty string if none)
            $content = !empty($entriesToKeep)
                ? implode("\n", $entriesToKeep) . "\n"
                : '';

            if (file_put_contents($tempFile, $content) === false) {
                throw new RuntimeException('Failed to write crontab temp file');
            }

            // Install from file (or remove crontab if empty)
            if (empty($entriesToKeep)) {
                // If no entries remain, remove crontab entirely
                $output = [];
                $returnCode = 0;
                exec("crontab -r 2>&1", $output, $returnCode);
                // crontab -r returns error if no crontab exists, which is fine
            } else {
                $this->installFromFile($tempFile);
            }

            // POST: Save crontab snapshot and verify via diff
            $snapshotAfter = $this->saveCrontabSnapshot('_after');
            $this->verifyRemovalViaDiff($snapshotBefore, $snapshotAfter, $pattern, array_values($entriesToRemove));

        } finally {
            // Always clean up temp files
            @unlink($tempFile);
            @unlink($snapshotBefore);
            if ($snapshotAfter !== null) {
                @unlink($snapshotAfter);
            }
        }
    }

    /**
     * Verify removeByPattern() via external diff command.
     *
     * Uses Unix diff to compare before/after snapshots. Expected result:
     * - Only removed lines (no additions)
     * - All removed lines must match the pattern
     */
    private function verifyRemovalViaDiff(
        string $fileBefore,
        string $fileAfter,
        string $pattern,
        array $expectedRemoved
    ): void {
        $diff = $this->diffFiles($fileBefore, $fileAfter);

        // For removeByPattern: expect zero added lines
        if (!empty($diff['added'])) {
            $this->logCritical('CRONTAB_REMOVE_UNEXPECTED_ADDITION', [
                'operation' => 'removeByPattern',
                'pattern' => $pattern,
                'unexpected_added' => $diff['added'],
                'diff_removed' => $diff['removed'],
            ]);
            return;
        }

        // Verify removed count matches expected
        $removedCount = count($diff['removed']);
        $expectedCount = count($expectedRemoved);

        if ($removedCount !== $expectedCount) {
            $this->logCritical('CRONTAB_REMOVE_WRONG_COUNT', [
                'operation' => 'removeByPattern',
                'pattern' => $pattern,
                'expected_removed_count' => $expectedCount,
                'actual_removed_count' => $removedCount,
                'expected_removed' => $expectedRemoved,
                'diff_removed' => $diff['removed'],
            ]);
            return;
        }

        // Verify each removed line matches pattern (extra safety check)
        foreach ($diff['removed'] as $removedLine) {
            if (strpos($removedLine, $pattern) === false) {
                $this->logCritical('CRONTAB_REMOVE_WRONG_ENTRY', [
                    'operation' => 'removeByPattern',
                    'pattern' => $pattern,
                    'removed_without_pattern' => $removedLine,
                    'diff_removed' => $diff['removed'],
                ]);
                return;
            }
        }
    }

    public function listEntries(): array
    {
        $output = [];
        $returnCode = 0;

        // Capture both stdout and stderr to distinguish between
        // "no crontab" and actual errors
        $descriptorspec = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = proc_open('crontab -l', $descriptorspec, $pipes);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to execute crontab -l');
        }

        fclose($pipes[0]);  // Close stdin
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $returnCode = proc_close($process);

        if ($returnCode !== 0) {
            // "no crontab for user" is normal - return empty array
            // This message varies by system, but typically contains "no crontab"
            if (stripos($stderr, 'no crontab') !== false) {
                return [];
            }

            // Any other error is a real problem - throw exception to prevent
            // addEntry() from accidentally wiping the crontab
            throw new RuntimeException(
                'Failed to read crontab (exit code ' . $returnCode . '): ' . trim($stderr)
            );
        }

        // Parse stdout into lines and filter empty lines
        $lines = explode("\n", $stdout);
        return array_values(array_filter($lines, fn($line) => trim($line) !== ''));
    }
}
