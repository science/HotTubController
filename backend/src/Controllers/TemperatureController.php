<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\WirelessTagClient;
use HotTub\Services\WirelessTagClientFactory;
use HotTub\Services\TemperatureStateService;

/**
 * Controller for temperature sensor operations.
 *
 * Provides temperature readings from WirelessTag sensors with
 * async refresh state tracking for polling-based updates.
 */
class TemperatureController
{
    public function __construct(
        private WirelessTagClient $wirelessTagClient,
        private ?WirelessTagClientFactory $factory = null,
        private ?TemperatureStateService $stateService = null
    ) {}

    /**
     * Get current temperature reading.
     *
     * Returns water and ambient temperatures along with device metadata.
     * Also includes refresh_in_progress to indicate if a refresh is pending.
     */
    public function get(): array
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
