<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\WirelessTagHttpClientInterface;
use RuntimeException;

/**
 * WirelessTag API Client for temperature sensor data.
 *
 * Uses Strategy pattern - business logic is shared between stub and live modes.
 * The only difference is the injected HTTP client:
 * - StubWirelessTagHttpClient: Simulated responses (safe for testing)
 * - CurlWirelessTagHttpClient: Real API calls (live hardware)
 */
class WirelessTagClient
{
    private string $mode;

    public function __construct(
        private WirelessTagHttpClientInterface $httpClient
    ) {
        // Determine mode from the HTTP client type
        $this->mode = $httpClient instanceof StubWirelessTagHttpClient ? 'stub' : 'live';
    }

    /**
     * Get cached temperature data from WirelessTag cloud.
     *
     * This is battery-friendly as it retrieves cached readings
     * without triggering the sensor hardware.
     *
     * @param string $deviceId WirelessTag device ID
     * @return array Temperature data including water and ambient temps
     * @throws RuntimeException on API failure
     */
    public function getTemperature(string $deviceId): array
    {
        $response = $this->httpClient->post('/GetTagList', ['id' => $deviceId]);

        if (!isset($response['d']) || !is_array($response['d']) || empty($response['d'])) {
            throw new RuntimeException('Invalid response from WirelessTag API: missing device data');
        }

        $device = $response['d'][0];

        // Extract temperatures (API returns Celsius)
        $waterTempC = $device['temperature'] ?? null;
        $ambientTempC = $device['cap'] ?? null;

        // Extract timestamp from lastComm (.NET ticks since 0001-01-01)
        $timestamp = time(); // fallback
        if (isset($device['lastComm'])) {
            $timestamp = $this->netTicksToUnixTimestamp($device['lastComm']);
        }

        return [
            'water_temp_c' => $waterTempC,
            'water_temp_f' => $waterTempC !== null ? $this->celsiusToFahrenheit($waterTempC) : null,
            'ambient_temp_c' => $ambientTempC,
            'ambient_temp_f' => $ambientTempC !== null ? $this->celsiusToFahrenheit($ambientTempC) : null,
            'battery_voltage' => $device['batteryVolt'] ?? null,
            'signal_dbm' => $device['signaldBm'] ?? null,
            'device_uuid' => $device['uuid'] ?? null,
            'device_name' => $device['name'] ?? null,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Test API connectivity.
     *
     * @return array Connectivity test results
     */
    public function testConnectivity(): array
    {
        $startTime = microtime(true);

        try {
            $this->httpClient->post('/GetTagList', ['id' => '']);
            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'connected' => true,
                'authenticated' => true,
                'response_time_ms' => $responseTimeMs,
                'error' => null,
            ];
        } catch (RuntimeException $e) {
            $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

            return [
                'connected' => false,
                'authenticated' => false,
                'response_time_ms' => $responseTimeMs,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the current mode of this client.
     *
     * @return string 'stub' or 'live'
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Request a fresh temperature reading from the hardware sensor.
     *
     * This triggers the WirelessTag sensor to take a new measurement
     * via the RequestImmediatePostback endpoint. The sensor will update
     * its cached reading which can then be retrieved via getTemperature().
     *
     * Note: This call may be slow (several seconds) and can fail on flaky
     * networks. The actual temperature update happens asynchronously on
     * the sensor hardware.
     *
     * @param string $deviceId WirelessTag device ID
     * @return bool True on success, false on failure
     */
    public function requestRefresh(string $deviceId): bool
    {
        try {
            $this->httpClient->post('/RequestImmediatePostback', ['id' => $deviceId]);
            return true;
        } catch (\RuntimeException $e) {
            // Log the error but don't throw - return false to indicate failure
            return false;
        }
    }

    /**
     * Convert Celsius to Fahrenheit.
     */
    private function celsiusToFahrenheit(float $celsius): float
    {
        return round(($celsius * 1.8) + 32, 1);
    }

    /**
     * Convert .NET ticks to Unix timestamp.
     *
     * .NET uses ticks since 0001-01-01 00:00:00 UTC (1 tick = 100 nanoseconds).
     * Unix epoch is 1970-01-01 00:00:00 UTC.
     * Offset between epochs: 621355968000000000 ticks.
     */
    private function netTicksToUnixTimestamp(int $netTicks): int
    {
        $dotNetEpochOffset = 621355968000000000;
        return (int) (($netTicks - $dotNetEpochOffset) / 10000000);
    }
}
