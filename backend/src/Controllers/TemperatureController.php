<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\WirelessTagClient;

/**
 * Controller for temperature sensor operations.
 *
 * Provides temperature readings from WirelessTag sensors.
 */
class TemperatureController
{
    public function __construct(
        private WirelessTagClient $wirelessTagClient
    ) {}

    /**
     * Get current temperature reading.
     *
     * Returns water and ambient temperatures along with
     * device metadata from the WirelessTag sensor.
     */
    public function get(): array
    {
        try {
            $temp = $this->wirelessTagClient->getTemperature('0');

            // Format timestamp as ISO 8601 if it's a Unix timestamp
            $timestamp = $temp['timestamp'];
            if (is_int($timestamp)) {
                $timestamp = date('c', $timestamp);
            }

            return [
                'status' => 200,
                'body' => [
                    'water_temp_f' => $temp['water_temp_f'],
                    'water_temp_c' => $temp['water_temp_c'],
                    'ambient_temp_f' => $temp['ambient_temp_f'],
                    'ambient_temp_c' => $temp['ambient_temp_c'],
                    'battery_voltage' => $temp['battery_voltage'],
                    'signal_dbm' => $temp['signal_dbm'],
                    'device_name' => $temp['device_name'],
                    'timestamp' => $timestamp,
                ],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => [
                    'error' => 'Failed to read temperature sensor: ' . $e->getMessage(),
                ],
            ];
        }
    }
}
