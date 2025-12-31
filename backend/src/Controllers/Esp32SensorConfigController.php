<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\Esp32TemperatureService;

/**
 * Controller for managing ESP32 sensor configuration.
 *
 * Provides endpoints to list detected sensors and configure their
 * roles (water/ambient) and calibration offsets.
 */
class Esp32SensorConfigController
{
    public function __construct(
        private Esp32SensorConfigService $configService,
        private Esp32TemperatureService $temperatureService
    ) {}

    /**
     * List all detected sensors with their configuration.
     *
     * Returns sensors from the latest temperature reading, enriched
     * with configuration (role, calibration offset, name).
     */
    public function list(): array
    {
        $latest = $this->temperatureService->getLatest();

        if ($latest === null || empty($latest['sensors'])) {
            return [
                'status' => 200,
                'body' => ['sensors' => []],
            ];
        }

        $sensors = [];
        foreach ($latest['sensors'] as $sensor) {
            $address = $sensor['address'];
            $config = $this->configService->getSensorConfig($address);

            $sensors[] = [
                'address' => $address,
                'temp_c' => $sensor['temp_c'],
                'temp_f' => $sensor['temp_f'] ?? null,
                'role' => $config['role'] ?? 'unassigned',
                'calibration_offset' => $config['calibration_offset'] ?? 0.0,
                'name' => $config['name'] ?? '',
            ];
        }

        return [
            'status' => 200,
            'body' => ['sensors' => $sensors],
        ];
    }

    /**
     * Update configuration for a specific sensor.
     *
     * Accepts: role, calibration_offset, name
     */
    public function update(string $address, array $data): array
    {
        if (empty($data)) {
            return [
                'status' => 400,
                'body' => ['error' => 'No configuration data provided'],
            ];
        }

        // Update role if provided
        if (isset($data['role'])) {
            try {
                $this->configService->setSensorRole($address, $data['role']);
            } catch (\InvalidArgumentException $e) {
                return [
                    'status' => 400,
                    'body' => ['error' => $e->getMessage()],
                ];
            }
        }

        // Update calibration offset if provided
        if (isset($data['calibration_offset'])) {
            $this->configService->setCalibrationOffset($address, (float) $data['calibration_offset']);
        }

        // Update name if provided
        if (isset($data['name'])) {
            $this->configService->setSensorName($address, $data['name']);
        }

        // Return updated configuration
        $config = $this->configService->getSensorConfig($address);

        return [
            'status' => 200,
            'body' => [
                'sensor' => [
                    'address' => $address,
                    'role' => $config['role'],
                    'calibration_offset' => $config['calibration_offset'],
                    'name' => $config['name'],
                ],
            ],
        ];
    }
}
