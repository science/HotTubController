<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Services\CrontabBackupService;
use HotTub\Services\LogRotationService;
use HotTub\Services\ScheduledJobsCleanupService;

/**
 * Controller for maintenance tasks like log rotation and job cleanup.
 *
 * Used by cron jobs to perform periodic maintenance operations.
 * Integrates with Healthchecks.io to signal successful completion.
 */
class MaintenanceController
{
    private const DAYS_TO_COMPRESS = 30;

    /**
     * Two years. Old temperature logs are the only record of how fast the tub
     * heated in past seasons, which is how a slowly scaling heater element
     * would show up. Gzipped they cost about 3 KB per day (~2.2 MB for two years).
     */
    private const DAYS_TO_DELETE = 730;

    /**
     * Crontab backups (one per crontab edit, ~17 a day) only help debug recent
     * scheduling problems. This runs monthly, so 30–60 days are on hand at any time.
     * Compression is skipped (compress threshold = delete threshold): each file
     * is ~500 bytes, and plain text stays greppable.
     */
    private const CRONTAB_BACKUP_DAYS_TO_KEEP = 30;

    public function __construct(
        private LogRotationService $logRotationService,
        private string $logsDirectory,
        private ?HealthchecksClientInterface $healthchecksClient = null,
        private ?string $healthcheckPingUrl = null,
        private ?ScheduledJobsCleanupService $jobsCleanupService = null,
        private ?CrontabBackupService $crontabBackupService = null
    ) {}

    /**
     * Rotate log files: compress old logs, delete very old compressed logs.
     *
     * Policy:
     * - Compress .log files older than 30 days
     * - Delete .log/.log.gz files older than 2 years (730 days)
     * - Delete crontab backups older than 30 days (when the backup service is wired)
     *
     * On success, pings Healthchecks.io to signal the job completed.
     *
     * @return array{status: int, body: array{timestamp: string, compressed: string[], deleted: string[], healthcheck_pinged: bool}}
     */
    public function rotateLogs(): array
    {
        $result = $this->logRotationService->rotate(
            $this->logsDirectory,
            '*.log*',
            self::DAYS_TO_COMPRESS,
            self::DAYS_TO_DELETE
        );
        $crontabBackupsDeleted = $this->pruneCrontabBackups();

        // Ping Healthchecks.io on successful completion
        $healthcheckPinged = $this->pingHealthcheck();

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'compressed' => $result['compressed'],
                'deleted' => $result['deleted'],
                'crontab_backups_deleted' => $crontabBackupsDeleted,
                'healthcheck_pinged' => $healthcheckPinged,
            ],
        ];
    }

    /**
     * Clean up orphaned scheduled job files.
     *
     * An orphaned job file is one that:
     * - Has no matching crontab entry
     * - Is older than 1 hour
     *
     * This prevents accumulation of stale job files from cancelled jobs
     * or jobs where the cron fired but failed to clean up properly.
     *
     * @return array{status: int, body: array{timestamp: string, deleted: string[], skipped_active: string[], skipped_recent: string[], skipped_invalid: string[]}}
     */
    public function cleanupOrphanedJobs(): array
    {
        if ($this->jobsCleanupService === null) {
            return [
                'status' => 200,
                'body' => [
                    'timestamp' => date('c'),
                    'deleted' => [],
                    'skipped_active' => [],
                    'skipped_recent' => [],
                    'skipped_invalid' => [],
                    'note' => 'Job cleanup service not configured',
                ],
            ];
        }

        $result = $this->jobsCleanupService->cleanup();

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'deleted' => $result['deleted'],
                'skipped_active' => $result['skipped_active'],
                'skipped_recent' => $result['skipped_recent'],
                'skipped_invalid' => $result['skipped_invalid'],
            ],
        ];
    }

    /**
     * Run all maintenance tasks: log rotation and job cleanup.
     *
     * This is the main entry point for scheduled maintenance.
     *
     * @return array{status: int, body: array{timestamp: string, logs: array, jobs: array, healthcheck_pinged: bool}}
     */
    public function runAll(): array
    {
        // Run log rotation
        $logsResult = $this->logRotationService->rotate(
            $this->logsDirectory,
            '*.log*',
            self::DAYS_TO_COMPRESS,
            self::DAYS_TO_DELETE
        );

        // Run job cleanup
        $jobsResult = $this->jobsCleanupService?->cleanup() ?? [
            'deleted' => [],
            'skipped_active' => [],
            'skipped_recent' => [],
            'skipped_invalid' => [],
        ];

        $crontabBackupsDeleted = $this->pruneCrontabBackups();

        // Ping Healthchecks.io on successful completion
        $healthcheckPinged = $this->pingHealthcheck();

        return [
            'status' => 200,
            'body' => [
                'timestamp' => date('c'),
                'logs' => [
                    'compressed' => $logsResult['compressed'],
                    'deleted' => $logsResult['deleted'],
                ],
                'jobs' => [
                    'deleted' => $jobsResult['deleted'],
                    'skipped_active' => $jobsResult['skipped_active'],
                    'skipped_recent' => $jobsResult['skipped_recent'],
                    'skipped_invalid' => $jobsResult['skipped_invalid'],
                ],
                'crontab_backups_deleted' => $crontabBackupsDeleted,
                'healthcheck_pinged' => $healthcheckPinged,
            ],
        ];
    }

    /**
     * Delete crontab backups past retention.
     *
     * @return int How many were deleted. A count rather than paths, because
     *             log-rotation-cron.sh copies the response body into cron.log.
     */
    private function pruneCrontabBackups(): int
    {
        if ($this->crontabBackupService === null) {
            return 0;
        }

        return count($this->crontabBackupService->cleanup(
            self::CRONTAB_BACKUP_DAYS_TO_KEEP,
            self::CRONTAB_BACKUP_DAYS_TO_KEEP
        ));
    }

    /**
     * Ping Healthchecks.io to signal successful log rotation.
     *
     * @return bool True if pinged successfully, false otherwise
     */
    private function pingHealthcheck(): bool
    {
        if ($this->healthcheckPingUrl === null) {
            return false;
        }

        if ($this->healthchecksClient === null || !$this->healthchecksClient->isEnabled()) {
            return false;
        }

        return $this->healthchecksClient->ping($this->healthcheckPingUrl);
    }
}
