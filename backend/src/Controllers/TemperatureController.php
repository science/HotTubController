<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\Esp32CalibratedTemperatureService;

/**
 * Controller for temperature sensor operations.
 *
 * Provides temperature readings from ESP32 sensors.
 */
class TemperatureController
{
    public function __construct(
        private ?Esp32CalibratedTemperatureService $esp32Service = null
    ) {}

    /**
     * Get all temperature sources.
     *
     * Returns ESP32 data when available.
     */
    public function getAll(): array
    {
        $esp32Data = null;

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

        return [
            'status' => 200,
            'body' => [
                'esp32' => $esp32Data,
            ],
        ];
    }

    /**
     * Get current temperature reading.
     *
     * Returns water and ambient temperatures along with device metadata
     * from ESP32 sensors.
     */
    public function get(): array
    {
        // Try ESP32 if service is available
        if ($this->esp32Service !== null) {
            $esp32Data = $this->esp32Service->getTemperatures();

            // Use ESP32 if we have data AND at least one role is assigned
            if ($esp32Data !== null && ($esp32Data['water_temp_c'] !== null || $esp32Data['ambient_temp_c'] !== null)) {
                return $this->formatEsp32Response($esp32Data);
            }
        }

        // No temperature data available
        return [
            'status' => 503,
            'body' => [
                'error' => 'No ESP32 temperature data available. Check sensor configuration.',
                'error_code' => 'SENSOR_NOT_CONFIGURED',
            ],
        ];
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
        ];

        return [
            'status' => 200,
            'body' => $body,
        ];
    }
}
