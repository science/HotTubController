<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for storing and retrieving ESP32 temperature sensor data.
 */
class Esp32TemperatureService
{
    private string $storageFile;

    // Interval bounds (seconds)
    public const MIN_INTERVAL = 10;
    public const MAX_INTERVAL = 1800;  // 30 minutes
    public const DEFAULT_INTERVAL = 300;  // 5 minutes

    public function __construct(string $storageFile)
    {
        $this->storageFile = $storageFile;
    }

    /**
     * Store a temperature reading from ESP32.
     */
    public function store(array $data): void
    {
        $record = [
            'device_id' => $data['device_id'],
            'temp_c' => (float) $data['temp_c'],
            'temp_f' => (float) $data['temp_f'],
            'uptime_seconds' => (int) ($data['uptime_seconds'] ?? 0),
            'timestamp' => date('c'),
            'received_at' => time(),
        ];

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
     * For now returns default, but can be extended to support dynamic control.
     */
    public function getInterval(): int
    {
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
