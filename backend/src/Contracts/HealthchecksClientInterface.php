<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Contract for Healthchecks.io monitoring clients.
 *
 * This interface supports a "feature flag" pattern where the monitoring
 * can be silently disabled when no API key is configured.
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
     * Create a new health check.
     *
     * @param string $name Human-readable name for the check
     * @param int $timeout Seconds until check is considered late
     * @param int $grace Additional seconds before alerting
     * @param string|null $channels Channel UUID(s) to notify, or null for default
     * @return array{uuid: string, ping_url: string}|null Check data or null if disabled/failed
     */
    public function createCheck(string $name, int $timeout, int $grace, ?string $channels = null): ?array;

    /**
     * Ping a health check to signal it's alive.
     *
     * @param string $pingUrl The ping URL returned from createCheck
     * @return bool True on success, false on failure
     */
    public function ping(string $pingUrl): bool;

    /**
     * Delete a health check.
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
