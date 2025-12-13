<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for rotating log files with compression and deletion.
 *
 * Provides:
 * - Compression of files older than a threshold (default 7 days)
 * - Deletion of files older than a threshold (default 90 days)
 * - Pattern-based file filtering
 * - Multi-directory batch processing
 */
class LogRotationService
{
    /**
     * Rotate log files in a directory.
     *
     * @param string $directory Directory containing log files
     * @param string $pattern Glob pattern for files to process (default: all files)
     * @param int $daysToCompress Compress files older than this many days (default: 7)
     * @param int $daysToDelete Delete files older than this many days (default: 90)
     * @return array{compressed: string[], deleted: string[]} Lists of processed file paths
     */
    public function rotate(
        string $directory,
        string $pattern = '*',
        int $daysToCompress = 7,
        int $daysToDelete = 90
    ): array {
        $compressed = [];
        $deleted = [];

        if (!is_dir($directory)) {
            return ['compressed' => $compressed, 'deleted' => $deleted];
        }

        $now = time();
        $compressThreshold = $now - ($daysToCompress * 24 * 60 * 60);
        $deleteThreshold = $now - ($daysToDelete * 24 * 60 * 60);

        // Get files matching pattern
        $files = glob($directory . '/' . $pattern) ?: [];

        foreach ($files as $path) {
            // Skip directories
            if (is_dir($path)) {
                continue;
            }

            $mtime = filemtime($path);

            // Delete files older than delete threshold
            if ($mtime < $deleteThreshold) {
                unlink($path);
                $deleted[] = $path;
                continue;
            }

            // Compress files older than compress threshold (but not already compressed)
            if ($mtime < $compressThreshold && !str_ends_with($path, '.gz')) {
                $this->compressFile($path);
                $compressed[] = $path;
            }
        }

        return ['compressed' => $compressed, 'deleted' => $deleted];
    }

    /**
     * Rotate log files in multiple directories with different configurations.
     *
     * @param array<array{path: string, pattern?: string, daysToCompress?: int, daysToDelete?: int}> $configs
     * @return array<string, array{compressed: string[], deleted: string[]}> Results keyed by directory path
     */
    public function rotateMultiple(array $configs): array
    {
        $results = [];

        foreach ($configs as $config) {
            $path = $config['path'];
            $pattern = $config['pattern'] ?? '*';
            $daysToCompress = $config['daysToCompress'] ?? 7;
            $daysToDelete = $config['daysToDelete'] ?? 90;

            $results[$path] = $this->rotate($path, $pattern, $daysToCompress, $daysToDelete);
        }

        return $results;
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
