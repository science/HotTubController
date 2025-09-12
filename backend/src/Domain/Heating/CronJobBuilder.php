<?php

declare(strict_types=1);

namespace HotTubController\Domain\Heating;

use DateTime;
use InvalidArgumentException;
use RuntimeException;

/**
 * CronJobBuilder - Constructs secure cron jobs with curl config files
 *
 * This class handles the construction of self-deleting cron jobs that use
 * curl config files to securely call API endpoints without exposing
 * sensitive information in process lists or crontab entries.
 */
class CronJobBuilder
{
    private const CONFIG_FILE_PREFIX = 'cron-config-';
    private const API_KEY_FILE = 'storage/cron-api-key.txt';
    private const CONFIG_DIR = 'storage/curl-configs';

    private string $projectRoot;
    private string $baseUrl;
    private string $apiKeyFile;
    private string $configDir;

    public function __construct(?string $projectRoot = null, ?string $baseUrl = null)
    {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 3);
        $this->baseUrl = $baseUrl ?? $this->detectBaseUrl();
        $this->apiKeyFile = $this->projectRoot . '/' . self::API_KEY_FILE;
        $this->configDir = $this->projectRoot . '/' . self::CONFIG_DIR;

        $this->ensureConfigDirectoryExists();
    }

    /**
     * Build a heating start cron job with curl config
     *
     * @param DateTime $startTime When to start heating
     * @param string $eventId Unique event identifier
     * @param float $targetTemp Target temperature
     * @return array Contains 'config_file' and 'cron_id'
     * @throws RuntimeException If config creation fails
     */
    public function buildStartHeatingCron(
        DateTime $startTime,
        string $eventId,
        float $targetTemp = 104.0
    ): array {
        $this->validateEventId($eventId);
        $this->validateTargetTemp($targetTemp);

        $cronId = "HOT_TUB_START:{$eventId}";
        $configFile = $this->generateConfigFilePath('start', $eventId);

        // Create curl config for start-heating endpoint
        $curlConfig = $this->buildCurlConfig(
            'start-heating',
            [
                'id' => $eventId,
                'target_temp' => (string) $targetTemp,
                'scheduled_time' => $startTime->format('Y-m-d H:i:s'),
            ]
        );

        $this->writeCurlConfig($configFile, $curlConfig);

        return [
            'config_file' => $configFile,
            'cron_id' => $cronId,
        ];
    }

    /**
     * Build a temperature monitoring cron job with curl config
     *
     * @param DateTime $checkTime When to check temperature
     * @param string $cycleId Active heating cycle identifier
     * @param string $monitorId Unique monitor identifier
     * @return array Contains 'config_file' and 'cron_id'
     * @throws RuntimeException If config creation fails
     */
    public function buildMonitorTempCron(
        DateTime $checkTime,
        string $cycleId,
        string $monitorId
    ): array {
        $this->validateEventId($cycleId);
        $this->validateEventId($monitorId);

        $cronId = "HOT_TUB_MONITOR:{$monitorId}";
        $configFile = $this->generateConfigFilePath('monitor', $monitorId);

        // Create curl config for monitor-temp endpoint
        $curlConfig = $this->buildCurlConfig(
            'monitor-temp',
            [
                'cycle_id' => $cycleId,
                'monitor_id' => $monitorId,
                'check_time' => $checkTime->format('Y-m-d H:i:s'),
            ]
        );

        $this->writeCurlConfig($configFile, $curlConfig);

        return [
            'config_file' => $configFile,
            'cron_id' => $cronId,
        ];
    }

    /**
     * Build a stop heating cron job with curl config (for emergency scenarios)
     *
     * @param string $cycleId Heating cycle to stop
     * @param string $reason Reason for stopping
     * @return array Contains 'config_file' and 'cron_id'
     */
    public function buildStopHeatingCron(string $cycleId, string $reason = 'emergency'): array
    {
        $this->validateEventId($cycleId);

        $stopId = 'stop-' . $cycleId . '-' . time();
        $cronId = "HOT_TUB_MONITOR:{$stopId}";
        $configFile = $this->generateConfigFilePath('stop', $stopId);

        // Create curl config for stop-heating endpoint
        $curlConfig = $this->buildCurlConfig(
            'stop-heating',
            [
                'cycle_id' => $cycleId,
                'reason' => $reason,
            ]
        );

        $this->writeCurlConfig($configFile, $curlConfig);

        return [
            'config_file' => $configFile,
            'cron_id' => $cronId,
        ];
    }

    /**
     * Calculate estimated time to heat based on temperature differential
     *
     * @param float $currentTemp Current water temperature
     * @param float $targetTemp Target temperature
     * @param float $heatingRate Heating rate in degrees per minute (default 0.5°F/min)
     * @return int Estimated minutes to reach target
     */
    public function calculateHeatingTime(
        float $currentTemp,
        float $targetTemp,
        float $heatingRate = 0.5
    ): int {
        if ($targetTemp <= $currentTemp) {
            return 0;
        }

        $tempDifference = $targetTemp - $currentTemp;
        $estimatedMinutes = (int) ceil($tempDifference / $heatingRate);

        // Add safety buffer (10% or minimum 5 minutes)
        $safetyBuffer = max(5, (int) ceil($estimatedMinutes * 0.1));

        return $estimatedMinutes + $safetyBuffer;
    }

    /**
     * Calculate next monitor check time based on current progress
     *
     * @param float $currentTemp Current water temperature
     * @param float $targetTemp Target temperature
     * @param DateTime $baseTime Base time to calculate from
     * @param bool $precisionMode Whether to use precision timing (near target)
     * @return DateTime Next check time
     */
    public function calculateNextCheckTime(
        float $currentTemp,
        float $targetTemp,
        DateTime $baseTime,
        bool $precisionMode = false
    ): DateTime {
        $tempDifference = abs($targetTemp - $currentTemp);
        $nextCheck = clone $baseTime;

        if ($precisionMode || $tempDifference <= 2.0) {
            // Precision mode: check every 15 seconds when close to target
            $nextCheck->modify('+15 seconds');
        } elseif ($tempDifference <= 5.0) {
            // Medium precision: check every 2 minutes when moderately close
            $nextCheck->modify('+2 minutes');
        } else {
            // Coarse monitoring: check every 5 minutes when far from target
            $estimatedMinutes = $this->calculateHeatingTime($currentTemp, $targetTemp);
            $checkInterval = min(15, max(5, (int)($estimatedMinutes * 0.3)));
            $nextCheck->modify("+{$checkInterval} minutes");
        }

        return $nextCheck;
    }

    /**
     * Validate that API key file exists and is readable
     *
     * @return bool True if API key is available
     * @throws RuntimeException If API key file is missing or unreadable
     */
    public function validateApiKeyAvailable(): bool
    {
        if (!file_exists($this->apiKeyFile)) {
            throw new RuntimeException("Cron API key file not found: {$this->apiKeyFile}");
        }

        if (!is_readable($this->apiKeyFile)) {
            throw new RuntimeException("Cron API key file not readable: {$this->apiKeyFile}");
        }

        $apiKey = trim(file_get_contents($this->apiKeyFile));
        if (empty($apiKey)) {
            throw new RuntimeException("Cron API key file is empty: {$this->apiKeyFile}");
        }

        return true;
    }

    /**
     * Clean up config file
     *
     * @param string $configFile Path to config file to remove
     * @return bool True if cleanup successful
     */
    public function cleanupConfigFile(string $configFile): bool
    {
        if (file_exists($configFile)) {
            return unlink($configFile);
        }

        return true;
    }

    /**
     * Build curl configuration content for an API endpoint
     */
    private function buildCurlConfig(string $endpoint, array $params = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/api/' . ltrim($endpoint, '/');

        $config = [
            '--silent',
            '--show-error',
            '--request POST',
            '--header "Content-Type: application/x-www-form-urlencoded"',
            '--url "' . $url . '"',
            '--data-urlencode "auth@' . $this->apiKeyFile . '"',
            '--max-time 30',
            '--retry 2',
            '--retry-delay 5',
        ];

        // Add additional parameters
        foreach ($params as $key => $value) {
            $config[] = '--data-urlencode "' . $key . '=' . addslashes($value) . '"';
        }

        return implode("\n", $config) . "\n";
    }

    /**
     * Generate config file path
     */
    private function generateConfigFilePath(string $type, string $id): string
    {
        $filename = self::CONFIG_FILE_PREFIX . $type . '-' . $id . '.conf';
        return $this->configDir . '/' . $filename;
    }

    /**
     * Write curl config to file with secure permissions
     */
    private function writeCurlConfig(string $configFile, string $content): void
    {
        if (file_put_contents($configFile, $content) === false) {
            throw new RuntimeException("Failed to write curl config file: {$configFile}");
        }

        // Set restrictive permissions (owner read/write only)
        if (!chmod($configFile, 0600)) {
            throw new RuntimeException("Failed to set permissions on config file: {$configFile}");
        }
    }

    /**
     * Validate event ID format
     */
    private function validateEventId(string $eventId): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $eventId)) {
            throw new InvalidArgumentException("Invalid event ID format: {$eventId}");
        }

        if (strlen($eventId) > 50) {
            throw new InvalidArgumentException("Event ID too long (max 50 chars): {$eventId}");
        }

        if (strlen($eventId) < 3) {
            throw new InvalidArgumentException("Event ID too short (min 3 chars): {$eventId}");
        }
    }

    /**
     * Validate target temperature
     */
    private function validateTargetTemp(float $targetTemp): void
    {
        if ($targetTemp < 50.0 || $targetTemp > 110.0) {
            throw new InvalidArgumentException("Target temperature out of safe range (50-110°F): {$targetTemp}");
        }
    }

    /**
     * Detect base URL for the application
     */
    private function detectBaseUrl(): string
    {
        // Try to detect from environment or server variables
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            return "{$scheme}://{$host}";
        }

        // Fallback to localhost for development
        return 'http://localhost:8080';
    }

    /**
     * Ensure config directory exists with proper permissions
     */
    private function ensureConfigDirectoryExists(): void
    {
        if (!is_dir($this->configDir)) {
            if (!mkdir($this->configDir, 0700, true)) {
                throw new RuntimeException("Failed to create config directory: {$this->configDir}");
            }
        }

        // Ensure restrictive permissions
        chmod($this->configDir, 0700);
    }
}
