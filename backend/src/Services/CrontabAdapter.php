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
    public function addEntry(string $entry): void
    {
        // Get current crontab, append new entry, and reinstall
        $currentEntries = $this->listEntries();
        $currentEntries[] = $entry;

        $newCrontab = implode("\n", $currentEntries) . "\n";

        $tempFile = tempnam(sys_get_temp_dir(), 'crontab');
        if ($tempFile === false) {
            throw new RuntimeException('Failed to create temp file for crontab');
        }

        file_put_contents($tempFile, $newCrontab);

        $output = [];
        $returnCode = 0;
        exec("crontab $tempFile 2>&1", $output, $returnCode);
        unlink($tempFile);

        if ($returnCode !== 0) {
            throw new RuntimeException('Failed to add crontab entry: ' . implode("\n", $output));
        }
    }

    public function removeByPattern(string $pattern): void
    {
        $output = [];
        $returnCode = 0;

        // Use shell to filter and reinstall crontab
        $escapedPattern = escapeshellarg($pattern);
        exec("crontab -l 2>/dev/null | grep -v $escapedPattern | crontab - 2>&1", $output, $returnCode);

        // grep -v returns 1 if no lines match, which is fine
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
