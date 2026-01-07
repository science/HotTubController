<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for managing ESP32 sensor configuration.
 *
 * Handles sensor role assignments (water/ambient) and calibration offsets.
 */
class Esp32SensorConfigService
{
    private string $configFile;
    private array $config;

    public const VALID_ROLES = ['water', 'ambient', 'unassigned'];

    public function __construct(string $configFile)
    {
        $this->configFile = $configFile;
        $this->config = $this->loadConfig();
    }

    /**
     * Get configuration for a specific sensor.
     */
    public function getSensorConfig(string $address): ?array
    {
        return $this->config['sensors'][$address] ?? null;
    }

    /**
     * Set the role for a sensor.
     *
     * When assigning a non-'unassigned' role, clears that role from any
     * other sensor that previously had it (roles are exclusive).
     *
     * @throws \InvalidArgumentException if role is invalid
     */
    public function setSensorRole(string $address, string $role): void
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new \InvalidArgumentException(
                "Invalid role '$role'. Valid roles: " . implode(', ', self::VALID_ROLES)
            );
        }

        // Clear this role from any other sensor (roles are exclusive)
        if ($role !== 'unassigned') {
            foreach ($this->config['sensors'] as $existingAddress => $config) {
                if ($existingAddress !== $address && ($config['role'] ?? null) === $role) {
                    $this->config['sensors'][$existingAddress]['role'] = 'unassigned';
                }
            }
        }

        $this->ensureSensorExists($address);
        $this->config['sensors'][$address]['role'] = $role;
        $this->saveConfig();
    }

    /**
     * Set the calibration offset for a sensor.
     *
     * @param float $offset Offset in degrees Celsius (can be negative)
     */
    public function setCalibrationOffset(string $address, float $offset): void
    {
        $this->ensureSensorExists($address);
        $this->config['sensors'][$address]['calibration_offset'] = $offset;
        $this->saveConfig();
    }

    /**
     * Set a friendly name for a sensor.
     */
    public function setSensorName(string $address, string $name): void
    {
        $this->ensureSensorExists($address);
        $this->config['sensors'][$address]['name'] = $name;
        $this->saveConfig();
    }

    /**
     * Get calibrated temperature for a sensor.
     *
     * @param string $address Sensor address
     * @param float $rawTempC Raw temperature in Celsius
     * @return float Calibrated temperature
     */
    public function getCalibratedTemperature(string $address, float $rawTempC): float
    {
        $config = $this->getSensorConfig($address);
        $offset = $config['calibration_offset'] ?? 0.0;
        return $rawTempC + $offset;
    }

    /**
     * Get all configured sensors.
     *
     * @return array<string, array> Map of address => config
     */
    public function getAllSensors(): array
    {
        return $this->config['sensors'] ?? [];
    }

    /**
     * Get the sensor address assigned to a specific role.
     *
     * @param string $role 'water' or 'ambient'
     * @return string|null Sensor address or null if not assigned
     */
    public function getSensorByRole(string $role): ?string
    {
        foreach ($this->config['sensors'] as $address => $config) {
            if (($config['role'] ?? null) === $role) {
                return $address;
            }
        }
        return null;
    }

    /**
     * Ensure a sensor entry exists in config.
     */
    private function ensureSensorExists(string $address): void
    {
        if (!isset($this->config['sensors'][$address])) {
            $this->config['sensors'][$address] = [
                'role' => 'unassigned',
                'calibration_offset' => 0.0,
                'name' => '',
            ];
        }
    }

    /**
     * Load config from file.
     */
    private function loadConfig(): array
    {
        if (!file_exists($this->configFile)) {
            return ['sensors' => [], 'updated_at' => null];
        }

        $content = file_get_contents($this->configFile);
        if ($content === false) {
            return ['sensors' => [], 'updated_at' => null];
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : ['sensors' => [], 'updated_at' => null];
    }

    /**
     * Save config to file.
     */
    private function saveConfig(): void
    {
        $this->config['updated_at'] = date('c');
        $this->ensureDirectory();
        file_put_contents($this->configFile, json_encode($this->config, JSON_PRETTY_PRINT));
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
