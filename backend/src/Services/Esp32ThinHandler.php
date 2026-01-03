<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Ultra-lightweight handler for ESP32 temperature data.
 *
 * This class is designed to be used by a thin entry point that bypasses
 * the full framework. It has minimal dependencies:
 * - No autoloader required (can be included directly)
 * - No external dependencies
 * - Just reads .env, validates, and writes to file
 *
 * This reduces server load for the frequent (every 5 min) ESP32 pings.
 */
class Esp32ThinHandler
{
    private const DEFAULT_INTERVAL = 300;  // 5 minutes

    private string $storageFile;
    private string $envFile;

    public function __construct(string $storageFile, string $envFile)
    {
        $this->storageFile = $storageFile;
        $this->envFile = $envFile;
    }

    /**
     * Handle an ESP32 temperature POST request.
     *
     * @param array $postData Decoded JSON body
     * @param string|null $apiKey Value from X-ESP32-API-KEY header
     * @param string $method HTTP method
     * @return array{status: int, body: array}
     */
    public function handle(array $postData, ?string $apiKey, string $method = 'POST'): array
    {
        // Validate method
        if ($method !== 'POST') {
            return ['status' => 405, 'body' => ['error' => 'Method not allowed']];
        }

        // Validate API key
        $expectedApiKey = $this->loadApiKey();
        if ($expectedApiKey === null) {
            return ['status' => 500, 'body' => ['error' => 'Server configuration error']];
        }

        if ($apiKey === null || $apiKey !== $expectedApiKey) {
            return ['status' => 401, 'body' => ['error' => 'Invalid or missing API key']];
        }

        // Validate required fields
        if (!isset($postData['device_id'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: device_id']];
        }

        if (!isset($postData['sensors']) || !is_array($postData['sensors'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: sensors']];
        }

        // Validate sensors array
        foreach ($postData['sensors'] as $sensor) {
            if (!isset($sensor['address']) || !isset($sensor['temp_c'])) {
                return ['status' => 400, 'body' => ['error' => 'Each sensor must have address and temp_c']];
            }
        }

        // Process sensors - calculate temp_f if not provided
        $sensors = [];
        foreach ($postData['sensors'] as $sensor) {
            $tempC = (float) $sensor['temp_c'];
            $tempF = isset($sensor['temp_f'])
                ? (float) $sensor['temp_f']
                : $tempC * 9.0 / 5.0 + 32.0;

            $sensors[] = [
                'address' => $sensor['address'],
                'temp_c' => $tempC,
                'temp_f' => $tempF,
            ];
        }

        // Build record
        $record = [
            'device_id' => $postData['device_id'],
            'sensors' => $sensors,
            'uptime_seconds' => (int) ($postData['uptime_seconds'] ?? 0),
            'timestamp' => date('c'),
            'received_at' => time(),
        ];

        // Legacy compatibility - include first sensor as top-level fields
        if (!empty($sensors)) {
            $record['temp_c'] = $sensors[0]['temp_c'];
            $record['temp_f'] = $sensors[0]['temp_f'];
        }

        // Ensure storage directory exists
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Write to storage file
        file_put_contents($this->storageFile, json_encode($record, JSON_PRETTY_PRINT));

        return [
            'status' => 200,
            'body' => [
                'status' => 'ok',
                'interval_seconds' => self::DEFAULT_INTERVAL,
            ],
        ];
    }

    /**
     * Load the ESP32 API key from .env file.
     */
    private function loadApiKey(): ?string
    {
        if (!file_exists($this->envFile)) {
            return null;
        }

        $content = file_get_contents($this->envFile);
        if (preg_match('/^ESP32_API_KEY=(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }
}
