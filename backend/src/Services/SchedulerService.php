<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\HealthchecksClientInterface;
use InvalidArgumentException;
use HotTub\Services\CronSchedulingService;

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
    private string $stateDir;
    private TimeConverter $timeConverter;
    private ?HealthchecksClientInterface $healthchecksClient;
    private CronSchedulingService $cronSchedulingService;

    public function __construct(
        private string $jobsDir,
        private string $cronRunnerPath,
        string $apiBaseUrl,
        private CrontabAdapterInterface $crontabAdapter,
        ?TimeConverter $timeConverter = null,
        ?HealthchecksClientInterface $healthchecksClient = null,
        ?CronSchedulingService $cronSchedulingService = null,
        ?string $stateDir = null
    ) {
        // Normalize apiBaseUrl by stripping trailing slashes to prevent double-slash URLs
        // when concatenating with endpoints like '/api/equipment/heater/on'
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->stateDir = $stateDir ?? dirname($jobsDir) . '/state';
        $this->timeConverter = $timeConverter ?? new TimeConverter();
        $this->healthchecksClient = $healthchecksClient;
        $this->cronSchedulingService = $cronSchedulingService ?? new CronSchedulingService($crontabAdapter);
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
    public function scheduleJob(
        string $action,
        string $scheduledTime,
        bool $recurring = false,
        array $params = [],
        ?string $endpointOverride = null,
        ?string $cronTime = null
    ): array {
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
            return $this->scheduleRecurringJob($action, $scheduledTime, $createdAt, $params, $endpointOverride, $cronTime);
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

        // Generate unique job ID with job- prefix for one-off
        $jobId = 'job-' . bin2hex(random_bytes(4));

        // Create health check if monitoring is enabled
        // Use CronSchedulingService for UTC cron expression (healthchecks use UTC)
        $timestamp = $utcDateTime->getTimestamp();
        $cronExpressionUtc = $this->cronSchedulingService->getCronExpression($timestamp, useUtc: true);
        $checkName = $this->formatCheckName($jobId, $action, false);
        $healthcheckData = $this->createHealthCheck($checkName, $cronExpressionUtc);
        $healthcheckUuid = $healthcheckData['uuid'] ?? null;

        // Create job data (store time in UTC)
        $jobData = [
            'jobId' => $jobId,
            'action' => $action,
            'endpoint' => $endpointOverride ?? self::VALID_ACTIONS[$action],
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

        // Schedule cron job using centralized service (handles timezone correctly)
        $actionLabel = self::ACTION_LABELS[$action];
        $command = sprintf('%s %s', escapeshellarg($this->cronRunnerPath), escapeshellarg($jobId));
        $comment = sprintf('HOTTUB:%s:%s:ONCE', $jobId, $actionLabel);
        $this->cronSchedulingService->scheduleAt($timestamp, $command, $comment);

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
    private function scheduleRecurringJob(
        string $action,
        string $time,
        string $createdAt,
        array $params = [],
        ?string $endpointOverride = null,
        ?string $cronTime = null
    ): array {
        // Generate unique job ID with rec- prefix for recurring
        $jobId = 'rec-' . bin2hex(random_bytes(4));

        // Check if time includes timezone offset (HH:MM+/-HH:MM format)
        $hasTimezoneOffset = preg_match('/^\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $time);

        if ($hasTimezoneOffset) {
            // New format: time with timezone offset
            // Convert to UTC for storage, server-local for cron
            $storedTime = $this->timeConverter->formatTimeUtc($time);
        } else {
            // Legacy format: bare HH:MM (assumes server timezone)
            $storedTime = $time;
        }

        // Health check schedule must match the ACTUAL cron fire time, not the display time.
        // For DTDT ready_by jobs, $cronTime is the wake-up time (earlier than display time).
        $healthcheckTime = $cronTime ?? $time;
        $healthcheckHasOffset = preg_match('/^\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $healthcheckTime);

        if ($healthcheckHasOffset) {
            $utcDateTime = $this->timeConverter->parseTimeWithOffset($healthcheckTime, toUtc: true);
            $healthcheckCronExpression = $this->formatDailyCronExpression($utcDateTime);
        } else {
            $healthcheckCronExpression = $this->formatDailyCronFromTime($healthcheckTime);
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
            'endpoint' => $endpointOverride ?? self::VALID_ACTIONS[$action],
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

        // Schedule recurring cron job using centralized service
        $actionLabel = self::ACTION_LABELS[$action];
        $command = sprintf('%s %s', escapeshellarg($this->cronRunnerPath), escapeshellarg($jobId));
        $comment = sprintf('HOTTUB:%s:%s:DAILY', $jobId, $actionLabel);

        // Use cronTime override if provided, otherwise use display time
        $actualCronTime = $cronTime ?? $time;
        $actualHasTimezoneOffset = preg_match('/^\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $actualCronTime);

        if ($actualHasTimezoneOffset) {
            // Use CronSchedulingService for correct timezone conversion
            $this->cronSchedulingService->scheduleDaily($actualCronTime, $command, $comment);
        } else {
            // Legacy bare HH:MM format - schedule directly (assumes server timezone)
            $cronExpression = $this->formatDailyCronFromTime($actualCronTime);
            $this->crontabAdapter->addEntry(sprintf('%s %s # %s', $cronExpression, $command, $comment));
        }

        return [
            'jobId' => $jobId,
            'action' => $action,
            'scheduledTime' => $storedTime,
            'recurring' => true,
            'createdAt' => $createdAt,
        ];
    }

    /**
     * Format daily cron expression from DateTime (for healthcheck schedules).
     * Output: "minute hour * * *" (runs every day at that time)
     */
    private function formatDailyCronExpression(\DateTime $dateTime): string
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

                // Add skip info for recurring jobs
                if ($job['recurring']) {
                    $jobId = $job['jobId'];
                    $isSkipped = $this->isSkipped($jobId);
                    $job['skipped'] = $isSkipped;

                    if ($isSkipped) {
                        $skipData = $this->getSkipData($jobId);
                        if ($skipData !== null) {
                            $skipDate = new \DateTime($skipData['skip_date']);
                            $resumeDate = clone $skipDate;
                            $resumeDate->modify('+1 day');

                            // Include scheduled time in the ISO dates for frontend display
                            $scheduledTime = $jobData['scheduledTime'] ?? '00:00';
                            // Parse hour/minute from scheduledTime
                            if (preg_match('/^(\d{2}):(\d{2})/', $scheduledTime, $matches)) {
                                $hour = (int) $matches[1];
                                $minute = (int) $matches[2];
                                $skipDate->setTime($hour, $minute, 0);
                                $resumeDate->setTime($hour, $minute, 0);
                            }

                            // If scheduledTime has UTC offset, keep it as UTC
                            if (str_contains($scheduledTime, '+') || str_contains($scheduledTime, 'Z')) {
                                $skipDate->setTimezone(new \DateTimeZone('UTC'));
                                $resumeDate->setTimezone(new \DateTimeZone('UTC'));
                            }

                            $job['skipDate'] = $skipDate->format(\DateTime::ATOM);
                            $job['resumeDate'] = $resumeDate->format(\DateTime::ATOM);
                        }
                    }
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
     * Skip the next occurrence of a recurring job.
     *
     * Creates a dated skip file that the cron-runner will consume.
     *
     * @param string $jobId The recurring job ID
     * @throws InvalidArgumentException If job not found, not recurring, or already skipped
     */
    public function skipNextOccurrence(string $jobId): void
    {
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';

        if (!file_exists($jobFile)) {
            throw new InvalidArgumentException('Job not found: ' . $jobId);
        }

        $jobData = json_decode(file_get_contents($jobFile), true);

        if (!($jobData['recurring'] ?? false)) {
            throw new InvalidArgumentException('Can only skip recurring jobs');
        }

        if ($this->isSkipped($jobId)) {
            throw new InvalidArgumentException('Job is already skipped');
        }

        // Compute skip_date in system timezone
        $systemTz = TimeConverter::getSystemTimezone();
        $systemTzObj = new \DateTimeZone($systemTz);
        $now = new \DateTime('now', $systemTzObj);

        // Parse the job's scheduled time to determine if it has passed today
        $scheduledTime = $jobData['scheduledTime'] ?? '';
        $scheduledHour = 0;
        $scheduledMinute = 0;

        if (preg_match('/^(\d{2}):(\d{2})/', $scheduledTime, $matches)) {
            // Bare HH:MM or HH:MM:SS+... format - extract hour/minute
            $scheduledHour = (int) $matches[1];
            $scheduledMinute = (int) $matches[2];

            // If it has a UTC offset (e.g., "14:30:00+00:00"), convert to system timezone
            if (str_contains($scheduledTime, '+') || str_contains($scheduledTime, 'Z')) {
                $refDate = new \DateTime("2030-01-01T{$scheduledTime}", new \DateTimeZone('UTC'));
                $refDate->setTimezone($systemTzObj);
                $scheduledHour = (int) $refDate->format('G');
                $scheduledMinute = (int) $refDate->format('i');
            }
        }

        // Build today's fire time in system timezone
        $todayFire = clone $now;
        $todayFire->setTime($scheduledHour, $scheduledMinute, 0);

        // If the scheduled time hasn't passed yet today, skip today; otherwise skip tomorrow
        if ($todayFire > $now) {
            $skipDate = $now->format('Y-m-d');
        } else {
            $tomorrow = clone $now;
            $tomorrow->modify('+1 day');
            $skipDate = $tomorrow->format('Y-m-d');
        }

        $skipData = [
            'skip_date' => $skipDate,
            'created_at' => (new \DateTime())->format(\DateTime::ATOM),
        ];

        $skipFile = $this->stateDir . '/skip-' . $jobId . '.json';
        file_put_contents($skipFile, json_encode($skipData, JSON_PRETTY_PRINT));
    }

    /**
     * Remove skip for the next occurrence of a recurring job.
     *
     * @param string $jobId The recurring job ID
     * @throws InvalidArgumentException If job not found or not skipped
     */
    public function unskipNextOccurrence(string $jobId): void
    {
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';

        if (!file_exists($jobFile)) {
            throw new InvalidArgumentException('Job not found: ' . $jobId);
        }

        if (!$this->isSkipped($jobId)) {
            throw new InvalidArgumentException('Job is not skipped');
        }

        $skipFile = $this->stateDir . '/skip-' . $jobId . '.json';
        unlink($skipFile);
    }

    /**
     * Check if a job has its next occurrence skipped.
     */
    public function isSkipped(string $jobId): bool
    {
        return file_exists($this->stateDir . '/skip-' . $jobId . '.json');
    }

    /**
     * Get skip data for a job, or null if not skipped.
     *
     * @return array{skip_date: string, created_at: string}|null
     */
    public function getSkipData(string $jobId): ?array
    {
        $skipFile = $this->stateDir . '/skip-' . $jobId . '.json';
        if (!file_exists($skipFile)) {
            return null;
        }
        $content = file_get_contents($skipFile);
        if ($content === false) {
            return null;
        }
        return json_decode($content, true);
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

        // Clean up any skip file
        $skipFile = $this->stateDir . '/skip-' . $jobId . '.json';
        if (file_exists($skipFile)) {
            unlink($skipFile);
        }
    }

    /**
     * Format daily cron expression from bare HH:MM time string (legacy format).
     * Input: "HH:MM" (e.g., "06:30" or "18:00")
     * Output: "minute hour * * *" (runs every day at that time)
     */
    private function formatDailyCronFromTime(string $time): string
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
