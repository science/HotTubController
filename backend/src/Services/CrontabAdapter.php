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

        exec('crontab -l 2>/dev/null', $output, $returnCode);

        // Return empty array if no crontab exists
        if ($returnCode !== 0) {
            return [];
        }

        // Filter out empty lines
        return array_values(array_filter($output, fn($line) => trim($line) !== ''));
    }
}
