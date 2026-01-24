<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\HealthchecksClientInterface;
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
        'heat-to-target' => '/api/equipment/heat-to-target',
    ];

    /** @var array<string, string> Human-readable labels for crontab comments */
    private const ACTION_LABELS = [
        'heater-on' => 'ON',
        'heater-off' => 'OFF',
        'pump-run' => 'PUMP',
        'heat-to-target' => 'TARGET',
    ];

    /** Default grace period for health checks (30 minutes) */
    private const HEALTHCHECK_GRACE_SECONDS = 1800;

    private string $apiBaseUrl;
    private TimeConverter $timeConverter;
    private ?HealthchecksClientInterface $healthchecksClient;

    public function __construct(
        private string $jobsDir,
        private string $cronRunnerPath,
        string $apiBaseUrl,
        private CrontabAdapterInterface $crontabAdapter,
        ?TimeConverter $timeConverter = null,
        ?HealthchecksClientInterface $healthchecksClient = null
    ) {
        // Normalize apiBaseUrl by stripping trailing slashes to prevent double-slash URLs
        // when concatenating with endpoints like '/api/equipment/heater/on'
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->timeConverter = $timeConverter ?? new TimeConverter();
        $this->healthchecksClient = $healthchecksClient;
    }

    /**
     * Schedule a job to execute an equipment action.
     *
     * @param string $action One of: heater-on, heater-off, pump-run, heat-to-target
     * @param string $scheduledTime ISO 8601 datetime string for one-off, or HH:MM time for recurring
     * @param bool $recurring If true, creates a daily recurring job
     * @param array<string, mixed> $params Optional parameters for the action (e.g., target_temp_f for heat-to-target)
     * @return array{jobId: string, action: string, scheduledTime: string, createdAt: string, recurring: bool}
     * @throws InvalidArgumentException If action is invalid or time is in the past (one-off only)
     */
    public function scheduleJob(string $action, string $scheduledTime, bool $recurring = false, array $params = []): array
    {
        // Validate action
        if (!isset(self::VALID_ACTIONS[$action])) {
            throw new InvalidArgumentException(
                'Invalid action: ' . $action . '. Valid actions: ' . implode(', ', array_keys(self::VALID_ACTIONS))
            );
        }

        $now = new \DateTime();
        $createdAt = $now->format(\DateTime::ATOM);

        if ($recurring) {
            // For recurring jobs, scheduledTime is just HH:MM format
            return $this->scheduleRecurringJob($action, $scheduledTime, $createdAt, $params);
        }

        // Parse and validate scheduled time for one-off jobs
        // The input may include a timezone offset from the client (e.g., "2030-12-11T06:30:00-08:00")
        $dateTime = new \DateTime($scheduledTime);

        if ($dateTime <= $now) {
            throw new InvalidArgumentException('Scheduled time must be in the future, not in the past');
        }

        // Use TimeConverter for consistent timezone handling
        // Convert to UTC for storage (industry standard for APIs)
        $utcDateTime = $this->timeConverter->toUtc($scheduledTime);

        // Convert to server-local timezone for cron scheduling
        // Cron runs in the server's local timezone, so we must schedule accordingly
        $serverDateTime = $this->timeConverter->toServerTimezone($scheduledTime);

        // Generate unique job ID with job- prefix for one-off
        $jobId = 'job-' . bin2hex(random_bytes(4));

        // Create health check if monitoring is enabled
        // Use date-specific cron expression (e.g., "30 14 15 12 *" for 2:30pm on Dec 15)
        $cronExpressionUtc = $this->dateToCronExpression($utcDateTime);
        $checkName = $this->formatCheckName($jobId, $action, false);
        $healthcheckData = $this->createHealthCheck($checkName, $cronExpressionUtc);
        $healthcheckUuid = $healthcheckData['uuid'] ?? null;

        // Create job data (store time in UTC)
        $jobData = [
            'jobId' => $jobId,
            'action' => $action,
            'endpoint' => self::VALID_ACTIONS[$action],
            'apiBaseUrl' => rtrim($this->apiBaseUrl, '/'),
            'scheduledTime' => $utcDateTime->format(\DateTime::ATOM),
            'recurring' => false,
            'createdAt' => $createdAt,
        ];

        // Add action-specific parameters (e.g., target_temp_f for heat-to-target)
        if (!empty($params)) {
            $jobData['params'] = $params;
        }

        // Add health check UUID if monitoring is enabled
        if ($healthcheckUuid !== null) {
            $jobData['healthcheckUuid'] = $healthcheckUuid;
        }

        // Write job file
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create crontab entry with descriptive label (e.g., HOTTUB:job-xxx:ON:ONCE)
        // Use server-local time for cron expression since cron runs in server timezone
        $cronExpression = $this->dateToCronExpression($serverDateTime);
        $actionLabel = self::ACTION_LABELS[$action];
        $cronEntry = sprintf(
            '%s %s %s # HOTTUB:%s:%s:ONCE',
            $cronExpression,
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId),
            $jobId,
            $actionLabel
        );

        $this->crontabAdapter->addEntry($cronEntry);

        return [
            'jobId' => $jobId,
            'action' => $action,
            'scheduledTime' => $utcDateTime->format(\DateTime::ATOM),
            'recurring' => false,
            'createdAt' => $createdAt,
        ];
    }

    /**
     * Schedule a daily recurring job.
     *
     * Supports two formats for $time:
     * - "HH:MM" (bare) - assumes server timezone (backward compatible)
     * - "HH:MM+/-HH:MM" (with offset) - converts to server timezone for cron, UTC for storage
     *
     * @param string $action One of: heater-on, heater-off, pump-run, heat-to-target
     * @param string $time Time in HH:MM or HH:MM+/-HH:MM format
     * @param string $createdAt ISO 8601 timestamp when job was created
     * @param array<string, mixed> $params Optional parameters for the action (e.g., target_temp_f for heat-to-target)
     * @return array{jobId: string, action: string, scheduledTime: string, createdAt: string, recurring: bool}
     */
    private function scheduleRecurringJob(string $action, string $time, string $createdAt, array $params = []): array
    {
        // Generate unique job ID with rec- prefix for recurring
        $jobId = 'rec-' . bin2hex(random_bytes(4));

        // Check if time includes timezone offset (HH:MM+/-HH:MM format)
        $hasTimezoneOffset = preg_match('/^\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $time);

        if ($hasTimezoneOffset) {
            // New format: time with timezone offset
            // Convert to UTC for storage, server-local for cron
            $storedTime = $this->timeConverter->formatTimeUtc($time);
            $serverDateTime = $this->timeConverter->parseTimeWithOffset($time, toServerTz: true);
            $cronExpression = $this->dateTimeToCronExpression($serverDateTime);
            // For health check, we need the UTC time
            $utcDateTime = $this->timeConverter->parseTimeWithOffset($time, toUtc: true);
            $healthcheckCronExpression = $this->dateTimeToCronExpression($utcDateTime);
        } else {
            // Legacy format: bare HH:MM (assumes server timezone)
            // We treat bare time as server timezone, so health check should also use that
            $storedTime = $time;
            $cronExpression = $this->timeToCronExpression($time);
            // Note: For bare HH:MM format, we assume server timezone which may not be UTC
            // The health check will use UTC, so we need to document this limitation
            $healthcheckCronExpression = $cronExpression;
        }

        // Create health check for recurring job monitoring
        // Uses same unified method as one-off jobs, just with daily cron expression
        $checkName = $this->formatCheckName($jobId, $action, true);
        $healthcheckData = $this->createHealthCheck($checkName, $healthcheckCronExpression);
        $healthcheckUuid = $healthcheckData['uuid'] ?? null;
        $healthcheckPingUrl = $healthcheckData['ping_url'] ?? null;

        // Create job data
        $jobData = [
            'jobId' => $jobId,
            'action' => $action,
            'endpoint' => self::VALID_ACTIONS[$action],
            'apiBaseUrl' => rtrim($this->apiBaseUrl, '/'),
            'scheduledTime' => $storedTime,
            'recurring' => true,
            'createdAt' => $createdAt,
        ];

        // Add action-specific parameters (e.g., target_temp_f for heat-to-target)
        if (!empty($params)) {
            $jobData['params'] = $params;
        }

        // Add health check data if monitoring is enabled
        if ($healthcheckUuid !== null) {
            $jobData['healthcheckUuid'] = $healthcheckUuid;
        }
        // Recurring jobs need ping_url for cron-runner.sh to ping on success
        if ($healthcheckPingUrl !== null) {
            $jobData['healthcheckPingUrl'] = $healthcheckPingUrl;
        }

        // Write job file
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        // Create crontab entry (e.g., HOTTUB:rec-xxx:ON:DAILY)
        $actionLabel = self::ACTION_LABELS[$action];
        $cronEntry = sprintf(
            '%s %s %s # HOTTUB:%s:%s:DAILY',
            $cronExpression,
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId),
            $jobId,
            $actionLabel
        );

        $this->crontabAdapter->addEntry($cronEntry);

        return [
            'jobId' => $jobId,
            'action' => $action,
            'scheduledTime' => $storedTime,
            'recurring' => true,
            'createdAt' => $createdAt,
        ];
    }

    /**
     * Convert a DateTime to cron expression for recurring daily jobs.
     * Output: "minute hour * * *" (runs every day at that time)
     */
    private function dateTimeToCronExpression(\DateTime $dateTime): string
    {
        return sprintf(
            '%d %d * * *',
            (int) $dateTime->format('i'), // minute
            (int) $dateTime->format('G')  // hour (24-hour)
        );
    }

    /**
     * List all pending scheduled jobs (both one-off and recurring).
     *
     * Also cleans up any orphaned crontab entries (crontab entries without
     * corresponding job files). This can happen if a job file is manually
     * deleted, or due to a crash during job execution.
     *
     * @return array<array{jobId: string, action: string, scheduledTime: string, createdAt: string, recurring: bool}>
     */
    public function listJobs(): array
    {
        // First, clean up any orphaned crontab entries
        $this->cleanupOrphanedCrontabEntries();

        $jobs = [];

        // Get both one-off (job-*) and recurring (rec-*) job files
        $patterns = [
            $this->jobsDir . '/job-*.json',
            $this->jobsDir . '/rec-*.json',
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $jobData = json_decode($content, true);
                if (!is_array($jobData)) {
                    continue;
                }

                $job = [
                    'jobId' => $jobData['jobId'] ?? basename($file, '.json'),
                    'action' => $jobData['action'] ?? 'unknown',
                    'scheduledTime' => $jobData['scheduledTime'] ?? '',
                    'createdAt' => $jobData['createdAt'] ?? '',
                    'recurring' => $jobData['recurring'] ?? false,
                ];

                // Include action-specific parameters (e.g., target_temp_f for heat-to-target)
                if (isset($jobData['params']) && is_array($jobData['params'])) {
                    $job['params'] = $jobData['params'];
                }

                $jobs[] = $job;
            }
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
            // Extract job ID from HOTTUB: comment pattern (handles both job- and rec- prefixes)
            if (preg_match('/HOTTUB:((?:job|rec)-[a-f0-9]+)/', $entry, $matches)) {
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

        // Read job data to get health check UUID before deleting
        $jobData = json_decode(file_get_contents($jobFile), true);
        $healthcheckUuid = $jobData['healthcheckUuid'] ?? null;

        // Delete health check if it exists
        if ($healthcheckUuid !== null && $this->healthchecksClient !== null) {
            $this->healthchecksClient->delete($healthcheckUuid);
        }

        // Remove from crontab
        $this->crontabAdapter->removeByPattern('HOTTUB:' . $jobId);

        // Delete job file
        unlink($jobFile);
    }

    /**
     * Convert a DateTime to cron expression for one-off jobs.
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

    /**
     * Convert a time string to cron expression for recurring daily jobs.
     * Input: "HH:MM" (e.g., "06:30" or "18:00")
     * Output: "minute hour * * *" (runs every day at that time)
     */
    private function timeToCronExpression(string $time): string
    {
        $parts = explode(':', $time);
        $hour = (int) $parts[0];
        $minute = (int) ($parts[1] ?? 0);

        return sprintf('%d %d * * *', $minute, $hour);
    }

    /**
     * Format a descriptive check name for Healthchecks.io.
     *
     * The name includes job ID, action, and type (ONCE/DAILY) so checks
     * are easily identifiable in the Healthchecks.io admin panel.
     *
     * @param string $jobId The job ID (e.g., "job-abc123" or "rec-abc123")
     * @param string $action The action (e.g., "heater-on")
     * @param bool $recurring Whether this is a recurring job
     * @return string Descriptive name (e.g., "job-abc123 | heater-on | ONCE")
     */
    private function formatCheckName(string $jobId, string $action, bool $recurring): string
    {
        $type = $recurring ? 'DAILY' : 'ONCE';
        return "{$jobId} | {$action} | {$type}";
    }

    /**
     * Create a health check for job monitoring.
     *
     * Creates a Healthchecks.io check with a cron schedule that will alert
     * if the job doesn't execute according to its schedule.
     *
     * Both one-off and recurring jobs use the same method:
     * - One-off: cron expression like "30 14 15 12 *" (specific date/time)
     * - Recurring: cron expression like "30 14 * * *" (daily at that time)
     *
     * Architecture:
     * 1. Create check with schedule (cron expression) and timezone
     * 2. Ping immediately to arm the check (transitions new → up)
     * 3. Return both uuid and ping_url for storage in job file
     *
     * @param string $name Descriptive check name
     * @param string $cronExpression Cron expression for the schedule (in UTC)
     * @return array{uuid: string, ping_url: string}|null Check data or null if disabled/failed
     */
    private function createHealthCheck(string $name, string $cronExpression): ?array
    {
        // Skip if monitoring is not configured
        if ($this->healthchecksClient === null || !$this->healthchecksClient->isEnabled()) {
            return null;
        }

        // Create the health check with schedule
        $check = $this->healthchecksClient->createCheck(
            $name,
            $cronExpression,
            'UTC', // Health check schedule is in UTC
            self::HEALTHCHECK_GRACE_SECONDS
        );

        if ($check === null) {
            // API call failed - job continues without monitoring
            return null;
        }

        // Ping immediately to arm the check (transitions "new" → "up")
        // Without this ping, the check will never alert!
        $this->healthchecksClient->ping($check['ping_url']);

        return [
            'uuid' => $check['uuid'],
            'ping_url' => $check['ping_url'],
        ];
    }
}
