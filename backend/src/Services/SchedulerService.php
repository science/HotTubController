<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use InvalidArgumentException;

/**
 * Service for scheduling one-off equipment control jobs.
 */
class SchedulerService
{
    private const VALID_ACTIONS = [
        'heater-on' => '/api/equipment/heater/on',
        'heater-off' => '/api/equipment/heater/off',
        'pump-run' => '/api/equipment/pump/run',
    ];

    public function __construct(
        private string $jobsDir,
        private string $cronRunnerPath,
        string $apiBaseUrl,
        private CrontabAdapterInterface $crontabAdapter
    ) {
        // Normalize apiBaseUrl by stripping trailing slashes to prevent double-slash URLs
        // when concatenating with endpoints like '/api/equipment/heater/on'
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
    }

    private string $apiBaseUrl;

    /**
     * Schedule a one-off job to execute an equipment action.
     *
     * @param string $action One of: heater-on, heater-off, pump-run
     * @param string $scheduledTime ISO 8601 datetime string
     * @return array{jobId: string, action: string, scheduledTime: string, createdAt: string}
     * @throws InvalidArgumentException If action is invalid or time is in the past
     */
    public function scheduleJob(string $action, string $scheduledTime): array
    {
        // Validate action
        if (!isset(self::VALID_ACTIONS[$action])) {
            throw new InvalidArgumentException(
                'Invalid action: ' . $action . '. Valid actions: ' . implode(', ', array_keys(self::VALID_ACTIONS))
            );
        }

        // Parse and validate scheduled time
        $dateTime = new \DateTime($scheduledTime);
        $now = new \DateTime();

        if ($dateTime <= $now) {
            throw new InvalidArgumentException('Scheduled time must be in the future, not in the past');
        }

        // Generate unique job ID
        $jobId = 'job-' . bin2hex(random_bytes(4));

        // Create job data
        $createdAt = $now->format(\DateTime::ATOM);
        $jobData = [
            'jobId' => $jobId,
            'action' => $action,
            'endpoint' => self::VALID_ACTIONS[$action],
            'apiBaseUrl' => $this->apiBaseUrl,
            'scheduledTime' => $dateTime->format(\DateTime::ATOM),
            'createdAt' => $createdAt,
        ];

        // Write job file
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create crontab entry
        $cronExpression = $this->dateToCronExpression($dateTime);
        $cronEntry = sprintf(
            '%s %s %s # HOTTUB:%s',
            $cronExpression,
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId),
            $jobId
        );

        $this->crontabAdapter->addEntry($cronEntry);

        return [
            'jobId' => $jobId,
            'action' => $action,
            'scheduledTime' => $dateTime->format(\DateTime::ATOM),
            'createdAt' => $createdAt,
        ];
    }

    /**
     * List all pending scheduled jobs.
     *
     * Also cleans up any orphaned crontab entries (crontab entries without
     * corresponding job files). This can happen if a job file is manually
     * deleted, or due to a crash during job execution.
     *
     * @return array<array{jobId: string, action: string, scheduledTime: string, createdAt: string}>
     */
    public function listJobs(): array
    {
        // First, clean up any orphaned crontab entries
        $this->cleanupOrphanedCrontabEntries();

        $jobs = [];
        $pattern = $this->jobsDir . '/job-*.json';

        foreach (glob($pattern) ?: [] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $jobData = json_decode($content, true);
            if (!is_array($jobData)) {
                continue;
            }

            $jobs[] = [
                'jobId' => $jobData['jobId'] ?? basename($file, '.json'),
                'action' => $jobData['action'] ?? 'unknown',
                'scheduledTime' => $jobData['scheduledTime'] ?? '',
                'createdAt' => $jobData['createdAt'] ?? '',
            ];
        }

        // Sort by scheduled time ascending
        usort($jobs, function ($a, $b) {
            return strcmp($a['scheduledTime'], $b['scheduledTime']);
        });

        return $jobs;
    }

    /**
     * Clean up orphaned crontab entries that don't have corresponding job files.
     *
     * An orphan can occur if:
     * 1. A job file was manually deleted
     * 2. cron-runner.sh crashed after removing cron but before deleting the job file (unlikely)
     * 3. Prior installation left stale entries
     */
    private function cleanupOrphanedCrontabEntries(): void
    {
        $entries = $this->crontabAdapter->listEntries();

        foreach ($entries as $entry) {
            // Extract job ID from HOTTUB: comment pattern
            if (preg_match('/HOTTUB:(job-[a-f0-9]+)/', $entry, $matches)) {
                $jobId = $matches[1];
                $jobFile = $this->jobsDir . '/' . $jobId . '.json';

                // If no job file exists, this is an orphaned crontab entry
                if (!file_exists($jobFile)) {
                    $this->crontabAdapter->removeByPattern('HOTTUB:' . $jobId);
                }
            }
        }
    }

    /**
     * Cancel a scheduled job.
     *
     * @param string $jobId The job ID to cancel
     * @throws InvalidArgumentException If job is not found
     */
    public function cancelJob(string $jobId): void
    {
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';

        if (!file_exists($jobFile)) {
            throw new InvalidArgumentException('Job not found: ' . $jobId);
        }

        // Remove from crontab first
        $this->crontabAdapter->removeByPattern('HOTTUB:' . $jobId);

        // Delete job file
        unlink($jobFile);
    }

    /**
     * Convert a DateTime to cron expression.
     * Format: minute hour day month *
     */
    private function dateToCronExpression(\DateTime $dateTime): string
    {
        return sprintf(
            '%d %d %d %d *',
            (int) $dateTime->format('i'), // minute (cast to int to remove leading zeros)
            (int) $dateTime->format('G'), // hour (24-hour, no leading zero)
            (int) $dateTime->format('j'), // day of month (no leading zero)
            (int) $dateTime->format('n')  // month (no leading zero)
        );
    }
}
