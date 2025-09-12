<?php

declare(strict_types=1);

namespace HotTubController\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuration manager for hot tub heating system settings
 *
 * Manages heating rate and related configuration with validation
 * and persistence to ensure safe operation parameters.
 */
class HeatingConfig
{
    private const DEFAULT_HEATING_RATE = 0.5; // degrees Fahrenheit per minute
    private const MIN_HEATING_RATE = 0.1;
    private const MAX_HEATING_RATE = 2.0;
    private const SUPPORTED_UNITS = ['fahrenheit_per_minute'];
    private const DEFAULT_UNIT = 'fahrenheit_per_minute';
    private const CONFIG_FILE = __DIR__ . '/../../storage/heating-config.json';

    private float $heatingRate;
    private string $unit;
    private array $persistedConfig = [];

    public function __construct()
    {
        $this->loadConfiguration();
    }

    /**
     * Get current heating rate in degrees per minute
     */
    public function getHeatingRate(): float
    {
        return $this->heatingRate;
    }

    /**
     * Get current heating rate unit
     */
    public function getHeatingRateUnit(): string
    {
        return $this->unit;
    }

    /**
     * Get minimum allowed heating rate
     */
    public function getMinHeatingRate(): float
    {
        return self::MIN_HEATING_RATE;
    }

    /**
     * Get maximum allowed heating rate
     */
    public function getMaxHeatingRate(): float
    {
        return self::MAX_HEATING_RATE;
    }

    /**
     * Get supported units
     */
    public function getSupportedUnits(): array
    {
        return self::SUPPORTED_UNITS;
    }

    /**
     * Set heating rate with validation
     *
     * @param float $rate Heating rate in degrees per minute
     * @param string $unit Unit of measurement
     * @throws InvalidArgumentException if rate or unit is invalid
     */
    public function setHeatingRate(float $rate, string $unit): void
    {
        $this->validateHeatingRate($rate);
        $this->validateUnit($unit);

        $this->heatingRate = $rate;
        $this->unit = $unit;
    }

    /**
     * Validate heating rate is within safe bounds
     *
     * @throws InvalidArgumentException if rate is out of bounds
     */
    public function validateHeatingRate(float $rate): bool
    {
        if ($rate < self::MIN_HEATING_RATE || $rate > self::MAX_HEATING_RATE) {
            throw new InvalidArgumentException(
                sprintf(
                    'Heating rate must be between %.1f and %.1f degrees per minute, got %.2f',
                    self::MIN_HEATING_RATE,
                    self::MAX_HEATING_RATE,
                    $rate
                )
            );
        }

        return true;
    }

    /**
     * Validate unit is supported
     *
     * @throws InvalidArgumentException if unit is not supported
     */
    public function validateUnit(string $unit): bool
    {
        if (!in_array($unit, self::SUPPORTED_UNITS, true)) {
            throw new InvalidArgumentException(
                sprintf(
                    'Unit "%s" is not supported. Supported units: %s',
                    $unit,
                    implode(', ', self::SUPPORTED_UNITS)
                )
            );
        }

        return true;
    }

    /**
     * Get configuration as array for API responses
     */
    public function toArray(): array
    {
        return [
            'heating_rate' => $this->heatingRate,
            'unit' => $this->unit,
            'min_allowed' => self::MIN_HEATING_RATE,
            'max_allowed' => self::MAX_HEATING_RATE,
            'supported_units' => self::SUPPORTED_UNITS
        ];
    }

    /**
     * Update configuration from array (for API updates)
     *
     * @param array $config Configuration array with 'heating_rate' and 'unit' keys
     * @throws InvalidArgumentException if required keys are missing or invalid
     */
    public function updateFromArray(array $config): void
    {
        if (!isset($config['heating_rate'])) {
            throw new InvalidArgumentException('Missing required field: heating_rate');
        }

        if (!isset($config['unit'])) {
            throw new InvalidArgumentException('Missing required field: unit');
        }

        $this->setHeatingRate((float) $config['heating_rate'], (string) $config['unit']);
    }

    /**
     * Persist current configuration to storage
     *
     * @throws RuntimeException if unable to write configuration file
     */
    public function persistToStorage(): void
    {
        $configData = [
            'heating_rate' => $this->heatingRate,
            'unit' => $this->unit,
            'updated_at' => date('c')
        ];

        $configPath = self::CONFIG_FILE;
        $configDir = dirname($configPath);

        // Ensure storage directory exists
        if (!is_dir($configDir)) {
            if (!mkdir($configDir, 0755, true)) {
                throw new RuntimeException("Failed to create storage directory: {$configDir}");
            }
        }

        // Write configuration with file locking
        $jsonData = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($jsonData === false) {
            throw new RuntimeException('Failed to encode configuration as JSON');
        }

        $tempFile = $configPath . '.tmp';
        if (file_put_contents($tempFile, $jsonData, LOCK_EX) === false) {
            throw new RuntimeException("Failed to write configuration to temporary file: {$tempFile}");
        }

        if (!rename($tempFile, $configPath)) {
            unlink($tempFile);
            throw new RuntimeException("Failed to move configuration file to final location: {$configPath}");
        }

        chmod($configPath, 0644);
    }

    /**
     * Load configuration from environment and persisted storage
     */
    private function loadConfiguration(): void
    {
        // Start with environment default
        $envRate = $_ENV['HOT_TUB_HEATING_RATE'] ?? getenv('HOT_TUB_HEATING_RATE');
        $defaultRate = $envRate !== false ? (float) $envRate : self::DEFAULT_HEATING_RATE;

        // Load persisted configuration if available
        $this->loadPersistedConfiguration();

        // Use persisted values if available, otherwise defaults
        $this->heatingRate = $this->persistedConfig['heating_rate'] ?? $defaultRate;
        $this->unit = $this->persistedConfig['unit'] ?? self::DEFAULT_UNIT;

        // Validate loaded configuration
        try {
            $this->validateHeatingRate($this->heatingRate);
            $this->validateUnit($this->unit);
        } catch (InvalidArgumentException $e) {
            // Fall back to safe defaults if persisted config is invalid
            error_log("Invalid heating configuration detected, falling back to defaults: " . $e->getMessage());
            $this->heatingRate = $defaultRate;
            $this->unit = self::DEFAULT_UNIT;
        }
    }

    /**
     * Load persisted configuration from file
     */
    private function loadPersistedConfiguration(): void
    {
        $configPath = self::CONFIG_FILE;

        if (!file_exists($configPath)) {
            return;
        }

        $configJson = file_get_contents($configPath);
        if ($configJson === false) {
            error_log("Failed to read heating configuration file: {$configPath}");
            return;
        }

        $configData = json_decode($configJson, true);
        if ($configData === null) {
            error_log("Failed to parse heating configuration JSON: {$configPath}");
            return;
        }

        $this->persistedConfig = $configData;
    }

    /**
     * Create configuration from custom values (useful for testing)
     */
    public static function fromArray(array $customConfig): self
    {
        $instance = new self();

        if (isset($customConfig['heating_rate'], $customConfig['unit'])) {
            $instance->setHeatingRate(
                (float) $customConfig['heating_rate'],
                (string) $customConfig['unit']
            );
        }

        return $instance;
    }
}
