<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Contract for Healthchecks.io monitoring clients.
 *
 * This interface supports a "feature flag" pattern where the monitoring
 * can be silently disabled when no API key is configured.
 *
 * All checks use schedule-based monitoring with cron expressions.
 * Both one-off and recurring jobs use the same check creation method,
 * differing only in their cron expressions and success handling:
 * - One-off: "30 14 15 12 *" (specific date/time), DELETE on success
 * - Recurring: "30 14 * * *" (daily), PING on success
 *
 * Implementations:
 * - HealthchecksClient: Real API calls to Healthchecks.io
 * - NullHealthchecksClient: No-op implementation when monitoring is disabled
 */
interface HealthchecksClientInterface
{
    /**
     * Check if monitoring is enabled.
     *
     * @return bool True if API calls will be made, false if disabled
     */
    public function isEnabled(): bool;

    /**
     * Create a new health check with a cron schedule.
     *
     * Both one-off and recurring jobs use this method with different cron expressions:
     * - One-off: "30 14 15 12 *" (runs at specific date/time)
     * - Recurring: "30 14 * * *" (runs daily at that time)
     *
     * @param string $name Human-readable name for the check (e.g., "job-abc123 | heater-on | ONCE")
     * @param string $schedule Cron expression (e.g., "30 14 15 12 *" or "30 6 * * *")
     * @param string $timezone Timezone for the schedule (typically "UTC")
     * @param int $grace Additional seconds before alerting after missed schedule
     * @param string|null $channels Channel UUID(s) to notify, or null for default
     * @return array{uuid: string, ping_url: string, status: string}|null Check data or null if disabled/failed
     */
    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array;

    /**
     * Ping a health check to signal it's alive.
     *
     * For recurring jobs, ping on each successful execution.
     * For one-off jobs, delete instead of ping.
     *
     * @param string $pingUrl The ping URL returned from createCheck
     * @return bool True on success, false on failure
     */
    public function ping(string $pingUrl): bool;

    /**
     * Delete a health check.
     *
     * Used for one-off jobs after successful execution, or when canceling any job.
     *
     * @param string $uuid The check UUID
     * @return bool True on success, false on failure
     */
    public function delete(string $uuid): bool;

    /**
     * Get a health check's current status.
     *
     * @param string $uuid The check UUID
     * @return array{uuid: string, status: string, n_pings: int}|null Check data or null if not found
     */
    public function getCheck(string $uuid): ?array;
}
