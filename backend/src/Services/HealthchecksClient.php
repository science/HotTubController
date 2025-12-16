<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HealthchecksClientInterface;

/**
 * Real Healthchecks.io client that makes API calls.
 *
 * Error Handling Philosophy:
 * - API errors are logged but DO NOT throw exceptions
 * - The application continues functioning even if monitoring fails
 * - Invalid API keys result in logged warnings, not crashes
 *
 * This is intentional: monitoring failure should not prevent
 * the hot tub controller from functioning.
 */
class HealthchecksClient implements HealthchecksClientInterface
{
    private const API_BASE_URL = 'https://healthchecks.io/api/v3';

    private string $apiKey;
    private ?string $defaultChannel;
    private ?string $logFile;

    public function __construct(
        string $apiKey,
        ?string $defaultChannel = null,
        ?string $logFile = null
    ) {
        $this->apiKey = $apiKey;
        $this->defaultChannel = $defaultChannel;
        $this->logFile = $logFile;
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array {
        $payload = [
            'name' => $name,
            'schedule' => $schedule,
            'tz' => $timezone,
            'grace' => $grace,
        ];

        // Add channels if specified or use default
        $channelId = $channels ?? $this->defaultChannel;
        if ($channelId !== null) {
            $payload['channels'] = $channelId;
        }

        $response = $this->apiRequest('POST', '/checks/', $payload);

        if ($response === null) {
            return null;
        }

        // Return the essential fields
        return [
            'uuid' => $response['uuid'] ?? null,
            'ping_url' => $response['ping_url'] ?? null,
            'status' => $response['status'] ?? null,
        ];
    }

    public function ping(string $pingUrl): bool
    {
        // Ping URLs go directly to hc-ping.com, not the API
        $ch = curl_init($pingUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            $this->log('warning', 'Healthchecks ping failed', [
                'ping_url' => $pingUrl,
                'http_code' => $httpCode,
                'error' => $error,
            ]);
            return false;
        }

        return true;
    }

    public function delete(string $uuid): bool
    {
        $response = $this->apiRequest('DELETE', '/checks/' . $uuid);

        // DELETE returns the check data on success, null on failure
        return $response !== null;
    }

    public function getCheck(string $uuid): ?array
    {
        $response = $this->apiRequest('GET', '/checks/' . $uuid);

        if ($response === null) {
            return null;
        }

        return [
            'uuid' => $response['uuid'] ?? null,
            'status' => $response['status'] ?? null,
            'n_pings' => $response['n_pings'] ?? 0,
            'last_ping' => $response['last_ping'] ?? null,
        ];
    }

    /**
     * Make an API request to Healthchecks.io.
     *
     * @param string $method HTTP method (GET, POST, DELETE)
     * @param string $endpoint API endpoint path
     * @param array|null $payload Request body for POST requests
     * @return array|null Decoded response or null on failure
     */
    private function apiRequest(string $method, string $endpoint, ?array $payload = null): ?array
    {
        $url = self::API_BASE_URL . $endpoint;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
                'Content-Type: application/json',
            ],
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        // Check for curl errors
        if ($response === false) {
            $this->log('error', 'Healthchecks API curl error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'error' => $error,
            ]);
            return null;
        }

        // Check for HTTP errors
        if ($httpCode < 200 || $httpCode >= 300) {
            $level = $httpCode === 401 ? 'warning' : 'error';
            $this->log($level, 'Healthchecks API error', [
                'method' => $method,
                'endpoint' => $endpoint,
                'http_code' => $httpCode,
                'response' => $response,
            ]);
            return null;
        }

        // Decode JSON response
        $data = json_decode($response, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            $this->log('error', 'Healthchecks API invalid JSON', [
                'method' => $method,
                'endpoint' => $endpoint,
                'response' => substr($response, 0, 200),
            ]);
            return null;
        }

        return $data;
    }

    /**
     * Log a message to the configured log file.
     *
     * @param string $level Log level (error, warning, info)
     * @param string $message Log message
     * @param array $context Additional context data
     */
    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logFile === null) {
            return;
        }

        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $entry = [
            'timestamp' => date('c'),
            'level' => strtoupper($level),
            'message' => $message,
            'context' => $context,
        ];

        @file_put_contents(
            $this->logFile,
            json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
