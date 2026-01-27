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
    private const HEATING_INTERVAL = 60;   // 1 minute when heater is on

    private string $storageFile;
    private string $envFile;
    private ?string $equipmentStatusFile;
    private ?string $firmwareDir;
    private ?string $firmwareConfigFile;
    private ?string $apiBaseUrl;

    public function __construct(
        string $storageFile,
        string $envFile,
        ?string $equipmentStatusFile = null,
        ?string $firmwareDir = null,
        ?string $firmwareConfigFile = null,
        ?string $apiBaseUrl = null
    ) {
        $this->storageFile = $storageFile;
        $this->envFile = $envFile;
        $this->equipmentStatusFile = $equipmentStatusFile;
        $this->firmwareDir = $firmwareDir;
        $this->firmwareConfigFile = $firmwareConfigFile;
        $this->apiBaseUrl = $apiBaseUrl;
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

        // Build response
        $response = [
            'status' => 'ok',
            'interval_seconds' => $this->getInterval(),
        ];

        // Check for firmware updates if device reported its version
        if (isset($postData['firmware_version']) && $this->firmwareConfigFile !== null) {
            $firmwareInfo = $this->getFirmwareInfo($postData['firmware_version']);
            if (!empty($firmwareInfo)) {
                $response = array_merge($response, $firmwareInfo);
            }
        }

        return [
            'status' => 200,
            'body' => $response,
        ];
    }

    /**
     * Get the interval to return to ESP32.
     * Returns shorter interval when heater is on for faster temperature updates.
     */
    private function getInterval(): int
    {
        if ($this->equipmentStatusFile === null || !file_exists($this->equipmentStatusFile)) {
            return self::DEFAULT_INTERVAL;
        }

        $content = file_get_contents($this->equipmentStatusFile);
        if ($content === false) {
            return self::DEFAULT_INTERVAL;
        }

        $status = json_decode($content, true);
        if (!is_array($status)) {
            return self::DEFAULT_INTERVAL;
        }

        // Check if heater is on
        if (isset($status['heater']['on']) && $status['heater']['on'] === true) {
            return self::HEATING_INTERVAL;
        }

        return self::DEFAULT_INTERVAL;
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

    /**
     * Get firmware update info if an update is available.
     *
     * @param string $deviceVersion Current firmware version on the device
     * @return array Firmware info (firmware_version, firmware_url) or empty array
     */
    private function getFirmwareInfo(string $deviceVersion): array
    {
        if ($this->firmwareConfigFile === null || !file_exists($this->firmwareConfigFile)) {
            return [];
        }

        $content = file_get_contents($this->firmwareConfigFile);
        if ($content === false) {
            return [];
        }

        $config = json_decode($content, true);
        if (!is_array($config) || !isset($config['version']) || !isset($config['filename'])) {
            return [];
        }

        // Check if update/sync is needed (device version differs from server)
        // Using != enables both upgrades AND rollbacks
        if (version_compare($deviceVersion, $config['version'], '==')) {
            return [];
        }

        // Check if firmware file exists
        $firmwarePath = $this->firmwareDir . '/' . $config['filename'];
        if (!file_exists($firmwarePath)) {
            return [];
        }

        // Build download URL (trailing slash required for directory index)
        $downloadUrl = rtrim($this->apiBaseUrl ?? '', '/') . '/esp32/firmware/download/';

        return [
            'firmware_version' => $config['version'],
            'firmware_url' => $downloadUrl,
        ];
    }
}
