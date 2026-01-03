<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\HealthchecksClientInterface;

/**
 * Service for managing system maintenance cron jobs.
 *
 * Handles the creation and management of recurring maintenance tasks
 * like log rotation. These are system-level cron jobs (not user-scheduled)
 * that should be set up during deployment.
 *
 * Integrates with Healthchecks.io for monitoring:
 * - Creates a health check when the cron is first set up
 * - The check uses the server's timezone so Healthchecks knows when to expect pings
 * - The MaintenanceController pings the check on successful log rotation
 */
class MaintenanceCronService
{
    private const LOG_ROTATION_MARKER = 'HOTTUB:log-rotation';

    // Run at 3am on the 1st of every month (approximately every 30 days)
    private const LOG_ROTATION_SCHEDULE = '0 3 1 * *';

    // Grace period: 1 day (86400 seconds) - gives buffer for log rotation to complete
    private const HEALTHCHECK_GRACE_SECONDS = 86400;

    public function __construct(
        private CrontabAdapterInterface $crontabAdapter,
        private string $cronScriptPath,
        private ?HealthchecksClientInterface $healthchecksClient = null,
        private ?string $healthcheckStateFile = null,
        private ?string $serverTimezone = null
    ) {}

    /**
     * Check if the log rotation cron job exists.
     */
    public function logRotationCronExists(): bool
    {
        $entries = $this->crontabAdapter->listEntries();
        foreach ($entries as $entry) {
            if (strpos($entry, self::LOG_ROTATION_MARKER) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Ensure the log rotation cron job exists, creating it if not.
     *
     * This method is idempotent - safe to call multiple times on deploy.
     * When creating the cron for the first time, also creates a Healthchecks.io
     * monitoring check (if enabled).
     *
     * If the cron already exists but the healthcheck state file is missing
     * (upgrade scenario), creates the healthcheck without touching the cron.
     *
     * @return array{created: bool, entry: string, healthcheck: ?array}
     */
    public function ensureLogRotationCronExists(): array
    {
        $cronExists = $this->logRotationCronExists();
        $healthcheckExists = $this->healthcheckStateFileExists();

        if ($cronExists && $healthcheckExists) {
            // Both exist - nothing to do
            return [
                'created' => false,
                'entry' => $this->getExistingLogRotationEntry(),
                'healthcheck' => null,
            ];
        }

        if ($cronExists && !$healthcheckExists) {
            // Cron exists but healthcheck doesn't - upgrade scenario
            // Create healthcheck without touching the cron
            $healthcheck = $this->createHealthCheck();

            return [
                'created' => false, // Cron wasn't created
                'entry' => $this->getExistingLogRotationEntry(),
                'healthcheck' => $healthcheck, // But healthcheck was
            ];
        }

        // Fresh install - create both
        $entry = $this->buildLogRotationCronEntry();
        $this->crontabAdapter->addEntry($entry);
        $healthcheck = $this->createHealthCheck();

        return [
            'created' => true,
            'entry' => $entry,
            'healthcheck' => $healthcheck,
        ];
    }

    /**
     * Check if the healthcheck state file exists.
     */
    private function healthcheckStateFileExists(): bool
    {
        return $this->healthcheckStateFile !== null
            && file_exists($this->healthcheckStateFile);
    }

    /**
     * Remove the log rotation cron job if it exists.
     *
     * Also deletes the associated Healthchecks.io check.
     *
     * @return array{removed: bool}
     */
    public function removeLogRotationCron(): array
    {
        if (!$this->logRotationCronExists()) {
            return ['removed' => false];
        }

        // Delete health check if it exists
        $this->deleteHealthCheck();

        $this->crontabAdapter->removeByPattern(self::LOG_ROTATION_MARKER);
        return ['removed' => true];
    }

    /**
     * Get the Healthchecks.io ping URL for log rotation.
     *
     * Used by MaintenanceController to ping on successful log rotation.
     *
     * @return string|null The ping URL, or null if not configured
     */
    public function getHealthcheckPingUrl(): ?string
    {
        if ($this->healthcheckStateFile === null || !file_exists($this->healthcheckStateFile)) {
            return null;
        }

        $state = json_decode(file_get_contents($this->healthcheckStateFile), true);
        return $state['ping_url'] ?? null;
    }

    /**
     * Get the server timezone used for cron scheduling.
     *
     * @return string Timezone identifier (e.g., "America/New_York")
     */
    public function getServerTimezone(): string
    {
        return $this->serverTimezone ?? TimeConverter::getSystemTimezone();
    }

    /**
     * Build the cron entry for log rotation.
     *
     * Calls the log-rotation-cron.sh script which handles:
     * - Reading CRON_JWT from .env file
     * - Reading API_BASE_URL from .env file
     * - Calling the log rotation API endpoint
     * - Logging results to storage/logs/cron.log
     */
    private function buildLogRotationCronEntry(): string
    {
        return sprintf(
            '%s %s # %s',
            self::LOG_ROTATION_SCHEDULE,
            $this->cronScriptPath,
            self::LOG_ROTATION_MARKER
        );
    }

    /**
     * Get the existing log rotation cron entry.
     */
    private function getExistingLogRotationEntry(): string
    {
        $entries = $this->crontabAdapter->listEntries();
        foreach ($entries as $entry) {
            if (strpos($entry, self::LOG_ROTATION_MARKER) !== false) {
                return $entry;
            }
        }
        return '';
    }

    /**
     * Create a Healthchecks.io check for log rotation monitoring.
     *
     * The check uses:
     * - The same cron schedule as the actual cron job
     * - The server's timezone (so Healthchecks knows when to expect pings)
     * - A 1-day grace period (buffer for log rotation to complete)
     *
     * @return array{uuid: string, ping_url: string}|null Check data or null if disabled
     */
    private function createHealthCheck(): ?array
    {
        if ($this->healthchecksClient === null || !$this->healthchecksClient->isEnabled()) {
            return null;
        }

        $timezone = $this->serverTimezone ?? TimeConverter::getSystemTimezone();

        $check = $this->healthchecksClient->createCheck(
            'log-rotation | Monthly Log Maintenance',
            self::LOG_ROTATION_SCHEDULE,
            $timezone,
            self::HEALTHCHECK_GRACE_SECONDS
        );

        if ($check === null) {
            return null;
        }

        // Ping immediately to arm the check (transitions from 'new' to 'up')
        $this->healthchecksClient->ping($check['ping_url']);

        // Save state for later pings
        $this->saveHealthCheckState($check);

        return [
            'uuid' => $check['uuid'],
            'ping_url' => $check['ping_url'],
        ];
    }

    /**
     * Delete the Healthchecks.io check for log rotation.
     */
    private function deleteHealthCheck(): void
    {
        if ($this->healthchecksClient === null || $this->healthcheckStateFile === null) {
            return;
        }

        if (!file_exists($this->healthcheckStateFile)) {
            return;
        }

        $state = json_decode(file_get_contents($this->healthcheckStateFile), true);
        $uuid = $state['uuid'] ?? null;

        if ($uuid !== null) {
            $this->healthchecksClient->delete($uuid);
        }

        // Remove state file
        @unlink($this->healthcheckStateFile);
    }

    /**
     * Save health check state to file.
     */
    private function saveHealthCheckState(array $check): void
    {
        if ($this->healthcheckStateFile === null) {
            return;
        }

        $dir = dirname($this->healthcheckStateFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->healthcheckStateFile,
            json_encode([
                'uuid' => $check['uuid'],
                'ping_url' => $check['ping_url'],
                'created_at' => date('c'),
            ], JSON_PRETTY_PRINT)
        );
    }
}
