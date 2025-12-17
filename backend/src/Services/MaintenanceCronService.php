<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;

/**
 * Service for managing system maintenance cron jobs.
 *
 * Handles the creation and management of recurring maintenance tasks
 * like log rotation. These are system-level cron jobs (not user-scheduled)
 * that should be set up during deployment.
 */
class MaintenanceCronService
{
    private const LOG_ROTATION_MARKER = 'HOTTUB:log-rotation';
    private const LOG_ROTATION_ENDPOINT = '/api/maintenance/logs/rotate';

    // Run at 3am on the 1st of every month (approximately every 30 days)
    private const LOG_ROTATION_SCHEDULE = '0 3 1 * *';

    public function __construct(
        private CrontabAdapterInterface $crontabAdapter,
        private string $apiBaseUrl
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
     *
     * @return array{created: bool, entry: string}
     */
    public function ensureLogRotationCronExists(): array
    {
        if ($this->logRotationCronExists()) {
            return [
                'created' => false,
                'entry' => $this->getExistingLogRotationEntry(),
            ];
        }

        $entry = $this->buildLogRotationCronEntry();
        $this->crontabAdapter->addEntry($entry);

        return [
            'created' => true,
            'entry' => $entry,
        ];
    }

    /**
     * Remove the log rotation cron job if it exists.
     *
     * @return array{removed: bool}
     */
    public function removeLogRotationCron(): array
    {
        if (!$this->logRotationCronExists()) {
            return ['removed' => false];
        }

        $this->crontabAdapter->removeByPattern(self::LOG_ROTATION_MARKER);
        return ['removed' => true];
    }

    /**
     * Build the cron entry for log rotation.
     *
     * Uses curl to call the API endpoint with JWT authentication.
     * The JWT token is read from the CRON_JWT environment variable at runtime.
     */
    private function buildLogRotationCronEntry(): string
    {
        $url = rtrim($this->apiBaseUrl, '/') . self::LOG_ROTATION_ENDPOINT;

        // Note: $CRON_JWT is expanded by the shell at runtime from .env
        // The cron command reads the JWT from environment
        return sprintf(
            '%s curl -s -X POST -H "Authorization: Bearer $CRON_JWT" %s # %s',
            self::LOG_ROTATION_SCHEDULE,
            escapeshellarg($url),
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
}
