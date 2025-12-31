<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for retrieving calibrated ESP32 temperature readings.
 *
 * Combines raw ESP32 sensor data with sensor configuration
 * (role assignments and calibration offsets) to provide
 * properly labeled water/ambient temperatures.
 */
class Esp32CalibratedTemperatureService
{
    public function __construct(
        private Esp32TemperatureService $temperatureService,
        private Esp32SensorConfigService $configService
    ) {}

    /**
     * Get calibrated temperatures with role assignments.
     *
     * @return array|null Temperature data with water_temp_c/f, ambient_temp_c/f, or null if no data
     */
    public function getTemperatures(): ?array
    {
        $latest = $this->temperatureService->getLatest();
        if ($latest === null) {
            return null;
        }

        // Find sensors assigned to each role
        $waterAddress = $this->configService->getSensorByRole('water');
        $ambientAddress = $this->configService->getSensorByRole('ambient');

        // Build sensor index by address
        $sensorsByAddress = [];
        foreach ($latest['sensors'] as $sensor) {
            $sensorsByAddress[$sensor['address']] = $sensor;
        }

        // Get calibrated water temperature
        $waterTempC = null;
        $waterTempF = null;
        if ($waterAddress !== null && isset($sensorsByAddress[$waterAddress])) {
            $rawC = (float) $sensorsByAddress[$waterAddress]['temp_c'];
            $waterTempC = $this->configService->getCalibratedTemperature($waterAddress, $rawC);
            $waterTempF = $this->celsiusToFahrenheit($waterTempC);
        }

        // Get calibrated ambient temperature
        $ambientTempC = null;
        $ambientTempF = null;
        if ($ambientAddress !== null && isset($sensorsByAddress[$ambientAddress])) {
            $rawC = (float) $sensorsByAddress[$ambientAddress]['temp_c'];
            $ambientTempC = $this->configService->getCalibratedTemperature($ambientAddress, $rawC);
            $ambientTempF = $this->celsiusToFahrenheit($ambientTempC);
        }

        // Enrich raw sensors with config info
        $enrichedSensors = [];
        foreach ($latest['sensors'] as $sensor) {
            $address = $sensor['address'];
            $config = $this->configService->getSensorConfig($address);

            $rawC = (float) $sensor['temp_c'];
            $calibratedC = $this->configService->getCalibratedTemperature($address, $rawC);

            $enrichedSensors[] = [
                'address' => $address,
                'temp_c' => $rawC,
                'temp_f' => $sensor['temp_f'] ?? $this->celsiusToFahrenheit($rawC),
                'calibrated_temp_c' => $calibratedC,
                'calibrated_temp_f' => $this->celsiusToFahrenheit($calibratedC),
                'role' => $config['role'] ?? 'unassigned',
                'calibration_offset' => $config['calibration_offset'] ?? 0.0,
                'name' => $config['name'] ?? '',
            ];
        }

        return [
            'device_id' => $latest['device_id'],
            'water_temp_c' => $waterTempC,
            'water_temp_f' => $waterTempF,
            'ambient_temp_c' => $ambientTempC,
            'ambient_temp_f' => $ambientTempF,
            'sensors' => $enrichedSensors,
            'uptime_seconds' => $latest['uptime_seconds'] ?? 0,
            'timestamp' => $latest['timestamp'],
            'received_at' => $latest['received_at'] ?? null,
        ];
    }

    /**
     * Check if the ESP32 data is fresh (received within threshold).
     *
     * @param int $maxAgeSeconds Maximum age in seconds for data to be considered fresh
     */
    public function isDataFresh(int $maxAgeSeconds): bool
    {
        $latest = $this->temperatureService->getLatest();
        if ($latest === null) {
            return false;
        }

        $receivedAt = $latest['received_at'] ?? null;
        if ($receivedAt === null) {
            return false;
        }

        return (time() - $receivedAt) <= $maxAgeSeconds;
    }

    private function celsiusToFahrenheit(float $celsius): float
    {
        return $celsius * 9.0 / 5.0 + 32.0;
    }
}
