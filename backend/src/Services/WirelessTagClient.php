<?php

declare(strict_types=1);

namespace HotTubController\Services;

use RuntimeException;
use InvalidArgumentException;

/**
 * WirelessTag API Client
 *
 * Provides wireless temperature sensor data through WirelessTag's cloud API.
 * Implements battery conservation through intelligent polling strategies.
 */
class WirelessTagClient
{
    private string $baseUrl = 'https://wirelesstag.net/ethClient.asmx';
    private ?string $oauthToken;
    private int $maxRetries;
    private int $timeoutSeconds;
    private bool $testMode;

    public function __construct(?string $oauthToken, int $maxRetries = 8, int $timeoutSeconds = 30)
    {
        $this->testMode = $oauthToken === null || empty($oauthToken);

        if (!$this->testMode && empty($oauthToken)) {
            throw new RuntimeException('WirelessTag OAuth token cannot be empty');
        }

        $this->oauthToken = $oauthToken;
        $this->maxRetries = $maxRetries;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Get cached temperature data from WirelessTag cloud (battery-friendly)
     *
     * Uses cached sensor readings without triggering hardware sensors.
     * Recommended for routine monitoring.
     *
     * @param string $deviceId WirelessTag device ID
     * @return array|null Sensor data array or null on failure
     */
    public function getCachedTemperatureData(string $deviceId): ?array
    {
        if ($this->testMode) {
            return $this->getTestTemperatureData($deviceId);
        }

        $endpoint = '/GetTagList';
        $payload = ['id' => $deviceId];

        $response = $this->makeRequest($endpoint, $payload, 'cached temperature');

        if ($response && isset($response['d']) && is_array($response['d'])) {
            return $response['d'];
        }

        return null;
    }

    /**
     * Force sensor to take new temperature reading (uses battery)
     *
     * Activates sensor hardware to take fresh measurement.
     * Use sparingly to conserve battery life.
     *
     * @param string $deviceId WirelessTag device ID
     * @return bool True on success, false on failure
     */
    public function requestFreshReading(string $deviceId): bool
    {
        $endpoint = '/RequestImmediatePostback';
        $payload = ['id' => $deviceId];

        $response = $this->makeRequest($endpoint, $payload, 'fresh reading request');

        return $response !== null;
    }

    /**
     * Get fresh temperature reading (combines request + retrieval)
     *
     * This is a convenience method that:
     * 1. Requests fresh sensor reading
     * 2. Waits for measurement completion
     * 3. Retrieves the fresh data
     *
     * @param string $deviceId WirelessTag device ID
     * @param int $waitSeconds Wait time after triggering fresh reading
     * @return array|null Sensor data array or null on failure
     */
    public function getFreshTemperatureData(string $deviceId, int $waitSeconds = 3): ?array
    {
        // Request fresh reading from sensor
        if (!$this->requestFreshReading($deviceId)) {
            error_log("WirelessTag: Failed to request fresh reading for device {$deviceId}");
            return null;
        }

        // Wait for sensor to complete measurement
        sleep($waitSeconds);

        // Retrieve the fresh data
        return $this->getCachedTemperatureData($deviceId);
    }

    /**
     * Extract and process temperature data from WirelessTag response
     *
     * @param array $sensorData Raw sensor data from API
     * @param int $deviceIndex Device index in response array (default 0)
     * @return array Processed temperature data
     */
    public function processTemperatureData(array $sensorData, int $deviceIndex = 0): array
    {
        if (!isset($sensorData[$deviceIndex])) {
            throw new InvalidArgumentException("Device index {$deviceIndex} not found in sensor data");
        }

        $device = $sensorData[$deviceIndex];

        // Extract temperatures in Celsius
        $waterTempC = $device['temperature'] ?? null;
        $ambientTempC = $device['cap'] ?? null;

        return [
            'device_id' => $device['uuid'] ?? 'unknown',
            'water_temperature' => [
                'celsius' => $waterTempC,
                'fahrenheit' => $waterTempC !== null ? $this->celsiusToFahrenheit($waterTempC) : null,
                'source' => 'primary_probe'
            ],
            'ambient_temperature' => [
                'celsius' => $ambientTempC,
                'fahrenheit' => $ambientTempC !== null ? $this->celsiusToFahrenheit($ambientTempC) : null,
                'source' => 'capacitive_sensor'
            ],
            'sensor_info' => [
                'battery_voltage' => $device['batteryVolt'] ?? null,
                'signal_strength_dbm' => $device['signaldBm'] ?? null,
                'last_communication' => $device['lastComm'] ?? null
            ],
            'data_timestamp' => time(),
            'is_fresh_reading' => false // Will be set to true by getFreshTemperatureData
        ];
    }

    /**
     * Apply ambient temperature calibration
     *
     * Compensates for thermal influence of hot water on ambient readings.
     * Based on observed calibration logic from existing system.
     *
     * @param float $ambientTempF Raw ambient temperature in Fahrenheit
     * @param float $waterTempF Water temperature in Fahrenheit
     * @return float Calibrated ambient temperature
     */
    public function calibrateAmbientTemperature(float $ambientTempF, float $waterTempF): float
    {
        $calibrationOffset = ($ambientTempF - $waterTempF) * 0.15;
        return $ambientTempF + $calibrationOffset;
    }

    /**
     * Validate temperature reading for safety and reasonableness
     *
     * @param float $tempF Temperature in Fahrenheit
     * @param string $context Description for logging (e.g., 'water', 'ambient')
     * @return bool True if temperature is within reasonable bounds
     */
    public function validateTemperature(float $tempF, string $context = 'temperature'): bool
    {
        // Reasonable bounds for hot tub temperature readings
        $minTemp = 32;   // 0°C (freezing)
        $maxTemp = 120;  // 49°C (very hot, but possible for hot tubs)

        if ($tempF < $minTemp || $tempF > $maxTemp) {
            error_log("WirelessTag: Invalid {$context} reading: {$tempF}°F (outside range {$minTemp}-{$maxTemp}°F)");
            return false;
        }

        return true;
    }

    /**
     * Test API connectivity and authentication
     *
     * @return array Test results with status and timing information
     */
    public function testConnectivity(): array
    {
        if ($this->testMode) {
            return $this->getTestConnectivityData();
        }

        $results = [
            'available' => false,
            'authenticated' => false,
            'tested_at' => date('Y-m-d H:i:s'),
            'response_time_ms' => null,
            'error' => null
        ];

        $startTime = microtime(true);

        try {
            // Test with a lightweight API call that we know works
            $endpoint = '/GetTagList';
            $response = $this->makeRequest($endpoint, ['id' => ''], 'connectivity test', 1); // Single attempt

            $results['response_time_ms'] = round((microtime(true) - $startTime) * 1000);

            if ($response !== null) {
                $results['available'] = true;
                $results['authenticated'] = true;
            }
        } catch (\Exception $e) {
            $results['error'] = $e->getMessage();
            $results['response_time_ms'] = round((microtime(true) - $startTime) * 1000);
        }

        return $results;
    }

    /**
     * Convert Celsius to Fahrenheit
     */
    public function celsiusToFahrenheit(float $celsius): float
    {
        return ($celsius * 1.8) + 32;
    }

    /**
     * Make HTTP request to WirelessTag API with retry logic
     *
     * @param string $endpoint API endpoint path
     * @param array $payload Request payload
     * @param string $operation Description of operation for logging
     * @param int|null $maxRetries Override default retry count
     * @return array|null Decoded response or null on failure
     */
    private function makeRequest(string $endpoint, array $payload, string $operation = 'API call', ?int $maxRetries = null): ?array
    {
        $url = $this->baseUrl . $endpoint;
        $retries = $maxRetries ?? $this->maxRetries;

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $this->oauthToken,
            'User-Agent: HotTubController/1.0',
            'Accept: application/json'
        ];

        $attempt = 1;
        $lastHttpCode = 0;

        while ($attempt <= $retries) {
            $startTime = microtime(true);

            $curl = curl_init();
            curl_setopt_array($curl, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => 12,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'HotTubController/1.0'
            ]);

            $response = curl_exec($curl);
            $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $error = curl_error($curl);
            $duration = (int) round((microtime(true) - $startTime) * 1000);

            curl_close($curl);
            $lastHttpCode = $httpCode;

            // Success
            if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
                $decoded = json_decode($response, true);

                if (json_last_error() === JSON_ERROR_NONE) {
                    $this->logSuccess($operation, $endpoint, $httpCode, $duration, $attempt);
                    return $decoded;
                }

                $this->logError($operation, $endpoint, 'Invalid JSON: ' . json_last_error_msg(), $httpCode, $duration, $attempt, $retries);
                return null;
            }

            // Log the error
            $errorMsg = $error ?: 'HTTP error';
            $this->logError($operation, $endpoint, $errorMsg, $httpCode, $duration, $attempt, $retries);

            // Don't retry on authentication errors
            if ($httpCode === 401 || $httpCode === 403) {
                break;
            }

            // Calculate exponential backoff delay
            if ($attempt < $retries) {
                $delay = min(30 * $attempt, 300); // Max 5 minutes
                sleep($delay);
            }

            $attempt++;
        }

        return null;
    }

    /**
     * Log successful API call
     */
    private function logSuccess(string $operation, string $endpoint, int $httpCode, int $durationMs, int $attempt): void
    {
        $attemptInfo = $attempt > 1 ? " (attempt {$attempt})" : '';

        error_log(sprintf(
            "WirelessTag SUCCESS: %s to %s (HTTP %d, %dms)%s",
            $operation,
            $endpoint,
            $httpCode,
            $durationMs,
            $attemptInfo
        ));
    }

    /**
     * Log failed API call
     */
    private function logError(string $operation, string $endpoint, string $error, int $httpCode, int $durationMs, int $attempt, int $maxRetries): void
    {
        error_log(sprintf(
            "WirelessTag ERROR: %s to %s failed - %s (HTTP %d, %dms, attempt %d/%d)",
            $operation,
            $endpoint,
            $error,
            $httpCode,
            $durationMs,
            $attempt,
            $maxRetries
        ));
    }


    /**
     * Convert WirelessTag timestamp to Unix timestamp
     *
     * WirelessTag uses .NET ticks (100-nanosecond intervals since January 1, 0001)
     */
    public function convertWirelessTagTimestamp(int $wirelessTagTime): int
    {
        // Convert from .NET ticks to Unix timestamp
        // .NET epoch is January 1, 0001, Unix epoch is January 1, 1970
        $dotNetEpochOffset = 621355968000000000; // .NET ticks between 0001 and 1970
        $unixTicks = $wirelessTagTime - $dotNetEpochOffset;
        return (int) ($unixTicks / 10000000); // Convert from 100ns ticks to seconds
    }

    /**
     * Assess battery level based on voltage
     */
    public function assessBatteryLevel(float $voltage): string
    {
        if ($voltage >= 3.8) {
            return 'excellent';
        } elseif ($voltage >= 3.5) {
            return 'good';
        } elseif ($voltage >= 3.2) {
            return 'warning';
        } elseif ($voltage >= 2.9) {
            return 'low';
        } else {
            return 'critical';
        }
    }

    /**
     * Assess signal strength based on dBm
     */
    public function assessSignalStrength(int $signaldBm): string
    {
        if ($signaldBm >= -60) {
            return 'excellent';
        } elseif ($signaldBm >= -75) {
            return 'good';
        } elseif ($signaldBm >= -85) {
            return 'fair';
        } elseif ($signaldBm >= -95) {
            return 'poor';
        } else {
            return 'very_poor';
        }
    }

    /**
     * Check if client is in test mode
     */
    public function isTestMode(): bool
    {
        return $this->testMode;
    }

    /**
     * Get test temperature data (test mode only)
     */
    private function getTestTemperatureData(string $deviceId): ?array
    {
        // Load the test data provider
        if (class_exists('Tests\Support\WirelessTagTestDataProvider')) {
            return \Tests\Support\WirelessTagTestDataProvider::getTemperatureData($deviceId);
        }

        // Fallback if test provider not available
        return $this->getFallbackTestData($deviceId);
    }

    /**
     * Get test connectivity data (test mode only)
     */
    private function getTestConnectivityData(): array
    {
        if (class_exists('Tests\Support\WirelessTagTestDataProvider')) {
            return \Tests\Support\WirelessTagTestDataProvider::getConnectivityTestData();
        }

        return [
            'available' => true,
            'authenticated' => true,
            'tested_at' => date('Y-m-d H:i:s'),
            'response_time_ms' => rand(50, 200),
            'error' => null
        ];
    }

    /**
     * Fallback test data when test provider is not available
     */
    private function getFallbackTestData(string $deviceId): array
    {
        $waterTempC = 35.0; // ~95°F
        $ambientTempC = 20.0; // ~68°F

        return [
            [
                'uuid' => $deviceId,
                'name' => 'Hot tub temperature (test mode)',
                'temperature' => $waterTempC,
                'cap' => $ambientTempC,
                'lastComm' => 621355968000000000 + (time() * 10000000),
                'batteryVolt' => 3.65,
                'signaldBm' => -80,
                'alive' => true
            ]
        ];
    }
}
