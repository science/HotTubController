<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for storing and retrieving ESP32 temperature sensor data.
 */
class Esp32TemperatureService
{
    private string $storageFile;
    private ?EquipmentStatusService $equipmentStatus;

    // Interval bounds (seconds)
    public const MIN_INTERVAL = 10;
    public const MAX_INTERVAL = 1800;  // 30 minutes
    public const DEFAULT_INTERVAL = 300;  // 5 minutes
    public const HEATING_INTERVAL = 60;   // 1 minute when heater is on

    public function __construct(string $storageFile, ?EquipmentStatusService $equipmentStatus = null)
    {
        $this->storageFile = $storageFile;
        $this->equipmentStatus = $equipmentStatus;
    }

    /**
     * Store a temperature reading from ESP32.
     *
     * Expects data with 'sensors' array from controller.
     */
    public function store(array $data): void
    {
        // Process sensors array - calculate temp_f if not provided
        $sensors = [];
        foreach ($data['sensors'] as $sensor) {
            $tempC = (float) $sensor['temp_c'];
            $tempF = isset($sensor['temp_f'])
                ? (float) $sensor['temp_f']
                : $tempC * 9.0 / 5.0 + 32.0;

            $sensors[] = [
                'address' => $sensor['address'],
                'temp_c' => $tempC,
                'temp_f' => $tempF,
            ];
        }

        $record = [
            'device_id' => $data['device_id'],
            'sensors' => $sensors,
            'uptime_seconds' => (int) ($data['uptime_seconds'] ?? 0),
            'timestamp' => date('c'),
            'received_at' => time(),
        ];

        // Keep legacy fields for backward compatibility (use first sensor)
        if (!empty($sensors)) {
            $record['temp_c'] = $sensors[0]['temp_c'];
            $record['temp_f'] = $sensors[0]['temp_f'];
        }

        $this->ensureDirectory();
        file_put_contents($this->storageFile, json_encode($record, JSON_PRETTY_PRINT));
    }

    /**
     * Get the latest temperature reading.
     */
    public function getLatest(): ?array
    {
        if (!file_exists($this->storageFile)) {
            return null;
        }

        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the interval to return to ESP32.
     * Returns shorter interval when heater is on for faster temperature updates.
     */
    public function getInterval(): int
    {
        if ($this->equipmentStatus !== null) {
            $status = $this->equipmentStatus->getStatus();
            if ($status['heater']['on']) {
                return self::HEATING_INTERVAL;
            }
        }

        return self::DEFAULT_INTERVAL;
    }

    /**
     * Clamp an interval value to valid bounds.
     */
    public static function clampInterval(int $interval): int
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
