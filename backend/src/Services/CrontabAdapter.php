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
    private function getTempFile(): string
    {
        return sys_get_temp_dir() . '/crontab_' . getmypid() . '_' . microtime(true);
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

        // PRE: Capture state before modification
        $entriesBefore = $this->listEntries();

        // FILE-BASED APPROACH: Write to temp file, then install
        // This is required for CloudLinux CageFS compatibility where
        // pipe-based crontab commands are unreliable.
        $tempFile = $this->getTempFile();

        try {
            // Write current entries to temp file
            $content = !empty($entriesBefore)
                ? implode("\n", $entriesBefore) . "\n"
                : '';

            // Append new entry
            $content .= $entry . "\n";

            if (file_put_contents($tempFile, $content) === false) {
                throw new RuntimeException('Failed to write crontab temp file');
            }

            // Install from file
            $this->installFromFile($tempFile);

        } finally {
            // Always clean up temp file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        // POST: Verify the modification was correct
        $entriesAfter = $this->listEntries();
        $this->verifyAddition($entriesBefore, $entriesAfter, $entry);
    }

    /**
     * Verify that addEntry() only added the expected entry.
     */
    private function verifyAddition(array $before, array $after, string $addedEntry): void
    {
        // Expected: after should have exactly one more entry than before
        $expectedCount = count($before) + 1;
        $actualCount = count($after);

        if ($actualCount !== $expectedCount) {
            $this->logCritical('CRONTAB_ADD_COUNT_MISMATCH', [
                'operation' => 'addEntry',
                'added_entry' => $addedEntry,
                'before_count' => count($before),
                'after_count' => $actualCount,
                'expected_count' => $expectedCount,
                'entries_before' => $before,
                'entries_after' => $after,
            ]);
            return;
        }

        // Verify the new entry is present
        $found = false;
        foreach ($after as $entry) {
            if ($entry === $addedEntry) {
                $found = true;
                break;
            }
        }

        if (!$found) {
            $this->logCritical('CRONTAB_ADD_ENTRY_MISSING', [
                'operation' => 'addEntry',
                'added_entry' => $addedEntry,
                'entries_after' => $after,
            ]);
            return;
        }

        // Verify all previous entries are still present
        foreach ($before as $oldEntry) {
            if (!in_array($oldEntry, $after, true)) {
                $this->logCritical('CRONTAB_ADD_LOST_ENTRY', [
                    'operation' => 'addEntry',
                    'added_entry' => $addedEntry,
                    'lost_entry' => $oldEntry,
                    'entries_before' => $before,
                    'entries_after' => $after,
                ]);
                return;
            }
        }
    }

    public function removeByPattern(string $pattern): void
    {
        // Backup before modification
        $this->backupBeforeModify();

        // PRE: Capture state and identify entries to remove
        $entriesBefore = $this->listEntries();

        // Identify which entries match the pattern
        $entriesToKeep = [];
        $entriesToRemove = [];

        foreach ($entriesBefore as $entry) {
            if (strpos($entry, $pattern) !== false) {
                $entriesToRemove[] = $entry;
            } else {
                $entriesToKeep[] = $entry;
            }
        }

        // If no entries match, nothing to remove - don't touch crontab at all
        if (empty($entriesToRemove)) {
            return;
        }

        // FILE-BASED APPROACH: Write filtered entries to temp file, then install
        $tempFile = $this->getTempFile();

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

        } finally {
            // Always clean up temp file
            if (file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        // POST: Verify the modification was correct
        $entriesAfter = $this->listEntries();
        $this->verifyRemoval($entriesBefore, $entriesAfter, $pattern, $entriesToRemove, $entriesToKeep);
    }

    /**
     * Verify that removeByPattern() only removed the expected entries.
     */
    private function verifyRemoval(
        array $before,
        array $after,
        string $pattern,
        array $expectedRemoved,
        array $expectedKept
    ): void {
        // Verify count matches expected
        $expectedCount = count($expectedKept);
        $actualCount = count($after);

        if ($actualCount !== $expectedCount) {
            $this->logCritical('CRONTAB_REMOVE_COUNT_MISMATCH', [
                'operation' => 'removeByPattern',
                'pattern' => $pattern,
                'before_count' => count($before),
                'after_count' => $actualCount,
                'expected_count' => $expectedCount,
                'expected_removed' => $expectedRemoved,
                'expected_kept' => $expectedKept,
                'entries_after' => $after,
            ]);
            return;
        }

        // Verify all expected-kept entries are still present
        foreach ($expectedKept as $entry) {
            if (!in_array($entry, $after, true)) {
                $this->logCritical('CRONTAB_REMOVE_LOST_ENTRY', [
                    'operation' => 'removeByPattern',
                    'pattern' => $pattern,
                    'lost_entry' => $entry,
                    'expected_kept' => $expectedKept,
                    'entries_after' => $after,
                ]);
                return;
            }
        }

        // Verify all expected-removed entries are actually gone
        foreach ($expectedRemoved as $entry) {
            if (in_array($entry, $after, true)) {
                $this->logCritical('CRONTAB_REMOVE_STILL_PRESENT', [
                    'operation' => 'removeByPattern',
                    'pattern' => $pattern,
                    'still_present' => $entry,
                    'expected_removed' => $expectedRemoved,
                    'entries_after' => $after,
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
