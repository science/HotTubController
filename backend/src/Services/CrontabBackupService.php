<?php

declare(strict_types=1);

namespace HotTub\Services;

use RuntimeException;

/**
 * Service for backing up crontab before modifications.
 *
 * Provides:
 * - Timestamped backups before each crontab change
 * - Listing of existing backups with metadata
 * - Cleanup with compression and retention policies
 */
class CrontabBackupService
{
    public function __construct(
        private string $backupDir
    ) {
    }

    /**
     * Create a timestamped backup of crontab content.
     *
     * @param string $crontabContent The crontab content to backup
     * @return string|null Path to backup file, or null if content was empty
     */
    public function backup(string $crontabContent): ?string
    {
        // Skip empty or whitespace-only content
        if (trim($crontabContent) === '') {
            return null;
        }

        // Ensure backup directory exists
        if (!is_dir($this->backupDir)) {
            if (!mkdir($this->backupDir, 0755, true)) {
                throw new RuntimeException('Failed to create backup directory: ' . $this->backupDir);
            }
        }

        // Generate timestamped filename
        $timestamp = date('Y-m-d-His');
        $filename = "crontab-{$timestamp}.txt";
        $path = $this->backupDir . '/' . $filename;

        // Write backup
        if (file_put_contents($path, $crontabContent) === false) {
            throw new RuntimeException('Failed to write backup file: ' . $path);
        }

        return $path;
    }

    /**
     * List all backup files with metadata.
     *
     * @return array<array{filename: string, path: string, size: int, created: int}>
     *         Sorted by creation time descending (newest first)
     */
    public function listBackups(): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $backups = [];
        $files = glob($this->backupDir . '/crontab-*.txt*') ?: [];

        foreach ($files as $path) {
            $backups[] = [
                'filename' => basename($path),
                'path' => $path,
                'size' => filesize($path),
                'created' => filemtime($path),
            ];
        }

        // Sort by created time descending (newest first)
        usort($backups, fn($a, $b) => $b['created'] <=> $a['created']);

        return $backups;
    }

    /**
     * Clean up old backups according to retention policy.
     *
     * @param int $daysToKeep Delete files older than this many days
     * @param int $daysToCompress Compress files older than this many days (but younger than $daysToKeep)
     * @return array<string> List of deleted file paths
     */
    public function cleanup(int $daysToKeep = 30, int $daysToCompress = 7): array
    {
        if (!is_dir($this->backupDir)) {
            return [];
        }

        $deleted = [];
        $now = time();
        $deleteThreshold = $now - ($daysToKeep * 24 * 60 * 60);
        $compressThreshold = $now - ($daysToCompress * 24 * 60 * 60);

        $files = glob($this->backupDir . '/crontab-*.txt*') ?: [];

        foreach ($files as $path) {
            $mtime = filemtime($path);

            // Delete files older than retention period
            if ($mtime < $deleteThreshold) {
                unlink($path);
                $deleted[] = $path;
                continue;
            }

            // Compress files older than compress threshold (but not already compressed)
            if ($mtime < $compressThreshold && !str_ends_with($path, '.gz')) {
                $this->compressFile($path);
            }
        }

        return $deleted;
    }

    /**
     * Compress a file using gzip and remove the original.
     */
    private function compressFile(string $path): void
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return;
        }

        $gzPath = $path . '.gz';
        $compressed = gzencode($content);
        if ($compressed === false) {
            return;
        }

        if (file_put_contents($gzPath, $compressed) !== false) {
            // Preserve original mtime on compressed file
            touch($gzPath, filemtime($path));
            unlink($path);
        }
    }
}
