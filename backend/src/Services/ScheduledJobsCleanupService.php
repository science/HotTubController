<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;

/**
 * Service for cleaning up orphaned scheduled job files.
 *
 * SAFETY FIRST: This service deletes files. Multiple checks ensure we only
 * delete files that are truly orphaned:
 *
 * 1. Only .json files are considered
 * 2. File must contain valid JSON with a 'jobId' field
 * 3. File must be older than minimum age (default 1 hour)
 * 4. File must NOT have a matching crontab entry
 *
 * If any check fails, the file is skipped and logged.
 */
class ScheduledJobsCleanupService
{
    private string $jobsDir;
    private CrontabAdapterInterface $crontabAdapter;
    private int $minAgeSeconds;

    /**
     * @param string $jobsDir Directory containing job files
     * @param CrontabAdapterInterface $crontabAdapter Adapter to check crontab entries
     * @param int $minAgeSeconds Minimum file age in seconds before cleanup (default: 1 hour)
     */
    public function __construct(
        string $jobsDir,
        CrontabAdapterInterface $crontabAdapter,
        int $minAgeSeconds = 3600
    ) {
        $this->jobsDir = $jobsDir;
        $this->crontabAdapter = $crontabAdapter;
        $this->minAgeSeconds = $minAgeSeconds;
    }

    /**
     * Clean up orphaned job files.
     *
     * @return array{
     *     deleted: string[],
     *     skipped_active: string[],
     *     skipped_recent: string[],
     *     skipped_invalid: string[]
     * }
     */
    public function cleanup(): array
    {
        $result = [
            'deleted' => [],
            'skipped_active' => [],
            'skipped_recent' => [],
            'skipped_invalid' => [],
        ];

        if (!is_dir($this->jobsDir)) {
            return $result;
        }

        // Get all crontab entries once (efficient)
        $crontabEntries = $this->crontabAdapter->listEntries();
        $crontabContent = implode("\n", $crontabEntries);

        // Get all .json files in the jobs directory
        $files = glob($this->jobsDir . '/*.json') ?: [];
        $now = time();
        $ageThreshold = $now - $this->minAgeSeconds;

        foreach ($files as $path) {
            $filename = basename($path, '.json');

            // Check 1: Is the file old enough?
            $mtime = filemtime($path);
            if ($mtime >= $ageThreshold) {
                $result['skipped_recent'][] = $filename;
                continue;
            }

            // Check 2: Is it valid JSON with a jobId?
            $content = file_get_contents($path);
            if ($content === false) {
                $result['skipped_invalid'][] = $filename;
                continue;
            }

            $data = json_decode($content, true);
            if (!is_array($data) || !isset($data['jobId'])) {
                $result['skipped_invalid'][] = $filename;
                continue;
            }

            $jobId = $data['jobId'];

            // Check 3: Is there a matching crontab entry?
            // Look for the jobId in any crontab entry (handles various formats)
            if (str_contains($crontabContent, $jobId)) {
                $result['skipped_active'][] = $jobId;
                continue;
            }

            // All checks passed - this file is orphaned, delete it
            if (unlink($path)) {
                $result['deleted'][] = $jobId;
            }
        }

        return $result;
    }
}
