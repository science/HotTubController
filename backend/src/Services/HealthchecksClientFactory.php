<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HealthchecksClientInterface;

/**
 * Factory for creating Healthchecks.io clients.
 *
 * Feature Flag Behavior:
 * - If HEALTHCHECKS_IO_KEY is not set or empty: returns NullHealthchecksClient
 * - If HEALTHCHECKS_IO_KEY is set: returns HealthchecksClient
 *
 * This allows monitoring to be completely optional - the application
 * works identically whether monitoring is enabled or not.
 */
class HealthchecksClientFactory
{
    /** @var array<string, string|null> */
    private array $config;

    /**
     * @param array<string, string|null> $config Environment configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Create a Healthchecks.io client based on configuration.
     *
     * EXTERNAL_API_MODE support:
     * - 'stub' mode: Always returns NullHealthchecksClient (no API calls)
     * - 'live' mode: Returns HealthchecksClient if API key is present
     * - When not set: Uses feature flag pattern (null client if no key)
     *
     * @return HealthchecksClientInterface
     */
    public function create(): HealthchecksClientInterface
    {
        $apiKey = $this->config['HEALTHCHECKS_IO_KEY'] ?? null;
        $externalApiMode = $this->config['EXTERNAL_API_MODE'] ?? null;

        // EXTERNAL_API_MODE=stub forces null client (no external calls)
        if ($externalApiMode === 'stub') {
            return new NullHealthchecksClient();
        }

        // Feature flag: if no API key, return null client
        if (empty($apiKey)) {
            return new NullHealthchecksClient();
        }

        // Get optional channel ID for notifications
        $channelId = $this->config['HEALTHCHECKS_IO_CHANNEL'] ?? null;

        // Get log file path (default to storage/logs)
        $logFile = $this->config['HEALTHCHECKS_LOG_FILE']
            ?? dirname(__DIR__, 2) . '/storage/logs/healthchecks.log';

        return new HealthchecksClient($apiKey, $channelId, $logFile);
    }
}
