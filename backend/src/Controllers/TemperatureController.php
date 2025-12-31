<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\WirelessTagClient;
use HotTub\Services\WirelessTagClientFactory;
use HotTub\Services\TemperatureStateService;
use HotTub\Services\Esp32CalibratedTemperatureService;

/**
 * Controller for temperature sensor operations.
 *
 * Provides temperature readings from ESP32 sensors (if configured) with
 * fallback to WirelessTag sensors. Includes async refresh state tracking
 * for polling-based updates.
 */
class TemperatureController
{
    public function __construct(
        private WirelessTagClient $wirelessTagClient,
        private ?WirelessTagClientFactory $factory = null,
        private ?TemperatureStateService $stateService = null,
        private ?Esp32CalibratedTemperatureService $esp32Service = null
    ) {}

    /**
     * Get all temperature sources.
     *
     * Returns both ESP32 and WirelessTag data when available.
     * Each source is returned separately so the frontend can display both.
     */
    public function getAll(): array
    {
        $esp32Data = null;
        $wirelessTagData = null;

        // Try to get ESP32 data
        if ($this->esp32Service !== null) {
            $esp32Raw = $this->esp32Service->getTemperatures();
            if ($esp32Raw !== null && ($esp32Raw['water_temp_c'] !== null || $esp32Raw['ambient_temp_c'] !== null)) {
                $esp32Data = [
                    'water_temp_f' => $esp32Raw['water_temp_f'],
                    'water_temp_c' => $esp32Raw['water_temp_c'],
                    'ambient_temp_f' => $esp32Raw['ambient_temp_f'],
                    'ambient_temp_c' => $esp32Raw['ambient_temp_c'],
                    'device_id' => $esp32Raw['device_id'],
                    'device_name' => 'ESP32 Temperature Sensor',
                    'uptime_seconds' => $esp32Raw['uptime_seconds'],
                    'timestamp' => $esp32Raw['timestamp'],
                    'sensors' => $esp32Raw['sensors'],
                    'source' => 'esp32',
                ];
            }
        }

        // Try to get WirelessTag data
        if ($this->factory !== null && !$this->factory->isConfigured()) {
            $wirelessTagData = [
                'error' => $this->factory->getConfigurationError(),
                'error_code' => 'SENSOR_NOT_CONFIGURED',
            ];
        } else {
            try {
                $temp = $this->wirelessTagClient->getTemperature('0');
                $timestamp = $temp['timestamp'];
                if (is_int($timestamp)) {
                    $timestamp = date('c', $timestamp);
                }

                $wirelessTagData = [
                    'water_temp_f' => $temp['water_temp_f'],
                    'water_temp_c' => $temp['water_temp_c'],
                    'ambient_temp_f' => $temp['ambient_temp_f'],
                    'ambient_temp_c' => $temp['ambient_temp_c'],
                    'battery_voltage' => $temp['battery_voltage'],
                    'signal_dbm' => $temp['signal_dbm'],
                    'device_name' => $temp['device_name'],
                    'timestamp' => $timestamp,
                    'source' => 'wirelesstag',
                ];
            } catch (\Exception $e) {
                $wirelessTagData = [
                    'error' => 'Failed to read temperature sensor: ' . $e->getMessage(),
                ];
            }
        }

        return [
            'status' => 200,
            'body' => [
                'esp32' => $esp32Data,
                'wirelesstag' => $wirelessTagData,
            ],
        ];
    }

    /**
     * Get current temperature reading.
     *
     * Returns water and ambient temperatures along with device metadata.
     * Uses ESP32 data when available and properly configured, otherwise
     * falls back to WirelessTag.
     */
    public function get(): array
    {
        // Try ESP32 first if service is available
        if ($this->esp32Service !== null) {
            $esp32Data = $this->esp32Service->getTemperatures();

            // Use ESP32 if we have data AND at least one role is assigned
            if ($esp32Data !== null && ($esp32Data['water_temp_c'] !== null || $esp32Data['ambient_temp_c'] !== null)) {
                return $this->formatEsp32Response($esp32Data);
            }
        }

        // Fall back to WirelessTag
        return $this->getFromWirelessTag();
    }

    /**
     * Format ESP32 data into standard response.
     */
    private function formatEsp32Response(array $esp32Data): array
    {
        $body = [
            'water_temp_f' => $esp32Data['water_temp_f'],
            'water_temp_c' => $esp32Data['water_temp_c'],
            'ambient_temp_f' => $esp32Data['ambient_temp_f'],
            'ambient_temp_c' => $esp32Data['ambient_temp_c'],
            'device_id' => $esp32Data['device_id'],
            'device_name' => 'ESP32 Temperature Sensor',
            'uptime_seconds' => $esp32Data['uptime_seconds'],
            'timestamp' => $esp32Data['timestamp'],
            'sensors' => $esp32Data['sensors'],
            'source' => 'esp32',
            'refresh_in_progress' => false,  // ESP32 doesn't need refresh tracking
        ];

        return [
            'status' => 200,
            'body' => $body,
        ];
    }

    /**
     * Get temperature from WirelessTag sensor.
     */
    private function getFromWirelessTag(): array
    {
        // Check if sensor is configured before attempting to read
        if ($this->factory !== null && !$this->factory->isConfigured()) {
            return [
                'status' => 503,
                'body' => [
                    'error' => $this->factory->getConfigurationError(),
                    'error_code' => 'SENSOR_NOT_CONFIGURED',
                    'refresh_in_progress' => false,
                ],
            ];
        }

        try {
            $temp = $this->wirelessTagClient->getTemperature('0');

            // Format timestamp as ISO 8601 if it's a Unix timestamp
            $timestamp = $temp['timestamp'];
            if (is_int($timestamp)) {
                $timestamp = date('c', $timestamp);
            }

            // Check refresh state if state service is available
            $refreshInProgress = false;
            $refreshRequestedAt = null;

            if ($this->stateService !== null) {
                $sensorTimestamp = new \DateTimeImmutable($timestamp);
                $refreshInProgress = $this->stateService->isRefreshInProgress($sensorTimestamp);

                if ($refreshInProgress) {
                    $refreshRequestedAt = $this->stateService->getRefreshRequestedAt();
                } else {
                    // Refresh completed or timed out - clear state
                    $this->stateService->clearRefreshState();
                }
            }

            $body = [
                'water_temp_f' => $temp['water_temp_f'],
                'water_temp_c' => $temp['water_temp_c'],
                'ambient_temp_f' => $temp['ambient_temp_f'],
                'ambient_temp_c' => $temp['ambient_temp_c'],
                'battery_voltage' => $temp['battery_voltage'],
                'signal_dbm' => $temp['signal_dbm'],
                'device_name' => $temp['device_name'],
                'timestamp' => $timestamp,
                'source' => 'wirelesstag',
                'refresh_in_progress' => $refreshInProgress,
            ];

            // Include refresh_requested_at when refresh is in progress
            if ($refreshRequestedAt !== null) {
                $body['refresh_requested_at'] = $refreshRequestedAt->format('c');
            }

            return [
                'status' => 200,
                'body' => $body,
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => [
                    'error' => 'Failed to read temperature sensor: ' . $e->getMessage(),
                    'refresh_in_progress' => false,
                ],
            ];
        }
    }

    /**
     * Request a fresh temperature reading from the sensor hardware.
     *
     * This triggers the WirelessTag sensor to take a new measurement.
     * The call returns immediately - the sensor will update its cached
     * reading asynchronously. Poll get() to check for the fresh data.
     */
    public function refresh(): array
    {
        // Check if sensor is configured before attempting refresh
        if ($this->factory !== null && !$this->factory->isConfigured()) {
            return [
                'status' => 503,
                'body' => [
                    'success' => false,
                    'error' => $this->factory->getConfigurationError(),
                    'error_code' => 'SENSOR_NOT_CONFIGURED',
                ],
            ];
        }

        // Record refresh request timestamp
        $requestedAt = new \DateTimeImmutable();
        if ($this->stateService !== null) {
            $this->stateService->markRefreshRequested($requestedAt);
        }

        $success = $this->wirelessTagClient->requestRefresh('0');

        if ($success) {
            return [
                'status' => 200,
                'body' => [
                    'success' => true,
                    'message' => 'Temperature refresh requested. New reading will be available shortly.',
                    'requested_at' => $requestedAt->format('c'),
                ],
            ];
        }

        // Clear state on failure
        if ($this->stateService !== null) {
            $this->stateService->clearRefreshState();
        }

        return [
            'status' => 503,
            'body' => [
                'success' => false,
                'error' => 'Failed to request temperature refresh from sensor',
            ],
        ];
    }
}
