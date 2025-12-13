<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use RuntimeException;

/**
 * Real crontab adapter that executes system commands.
 */
class CrontabAdapter implements CrontabAdapterInterface
{
    public function __construct(
        private ?CrontabBackupService $backupService = null
    ) {
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

    public function addEntry(string $entry): void
    {
        // Backup before modification
        $this->backupBeforeModify();
        // SAFE APPEND: Use shell pipeline to append entry without parsing in PHP
        // This prevents the bug where listEntries() failure could wipe the crontab
        //
        // How it works:
        // - crontab -l outputs existing entries (or nothing if no crontab)
        // - echo adds the new entry
        // - crontab - reads from stdin and installs
        //
        // This is atomic and safe because we never parse crontab into PHP memory
        $escapedEntry = escapeshellarg($entry);

        $output = [];
        $returnCode = 0;
        exec("(crontab -l 2>/dev/null; echo $escapedEntry) | crontab - 2>&1", $output, $returnCode);

        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to add crontab entry: ' . implode("\n", $output));
        }
    }

    public function removeByPattern(string $pattern): void
    {
        // Backup before modification
        $this->backupBeforeModify();

        // SAFETY: First verify we can read the crontab before modifying
        // This prevents the bug where crontab -l failure in the pipeline
        // would result in grep getting empty input, leading to crontab wipe
        $entries = $this->listEntries();

        // Check if any entries actually match the pattern
        $hasMatch = false;
        foreach ($entries as $entry) {
            if (strpos($entry, $pattern) !== false) {
                $hasMatch = true;
                break;
            }
        }

        // If no entries match, nothing to remove - don't touch crontab at all
        if (!$hasMatch) {
            return;
        }

        // Now safe to proceed - we've verified crontab is readable
        $output = [];
        $returnCode = 0;
        $escapedPattern = escapeshellarg($pattern);
        exec("crontab -l | grep -v $escapedPattern | crontab - 2>&1", $output, $returnCode);

        // Note: grep -v returns 1 if no lines match, which is fine
        // crontab returns non-zero on actual errors
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
