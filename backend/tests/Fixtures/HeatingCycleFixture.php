<?php

declare(strict_types=1);

namespace HotTub\Tests\Fixtures;

/**
 * Helper class for loading and using heating cycle fixture data in tests.
 *
 * Usage:
 *   $fixture = HeatingCycleFixture::load();
 *
 *   // Get a specific reading by minute
 *   $reading = $fixture->getReading(20);  // 92.0°F at minute 20
 *
 *   // Get readings in a range (for simulating partial heating)
 *   $readings = $fixture->getReadingsInRange(0, 10);
 *
 *   // Get reading at or above a target temperature
 *   $reading = $fixture->getFirstReadingAtOrAbove(100.0);  // minute 36
 *
 *   // Convert to ESP32 API request format (what the controller receives)
 *   $apiRequest = $fixture->toApiRequest($reading);
 */
class HeatingCycleFixture
{
    private array $metadata;
    private array $readings;

    private function __construct(array $data)
    {
        $this->metadata = $data['_metadata'];
        $this->readings = $data['readings'];
    }

    /**
     * Load the default heating cycle fixture (82°F to 103.5°F).
     */
    public static function load(): self
    {
        $path = __DIR__ . '/heating_cycle_82_to_103.5.json';
        $json = file_get_contents($path);
        $data = json_decode($json, true);

        if ($data === null) {
            throw new \RuntimeException("Failed to parse fixture: $path");
        }

        return new self($data);
    }

    /**
     * Get fixture metadata.
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Get all readings.
     */
    public function getAllReadings(): array
    {
        return $this->readings;
    }

    /**
     * Get a specific reading by minute offset.
     */
    public function getReading(int $minute): ?array
    {
        return $this->readings[$minute] ?? null;
    }

    /**
     * Get readings in a range (inclusive).
     */
    public function getReadingsInRange(int $startMinute, int $endMinute): array
    {
        return array_filter(
            $this->readings,
            fn($r) => $r['minute'] >= $startMinute && $r['minute'] <= $endMinute
        );
    }

    /**
     * Get the first reading at or above a target temperature (Fahrenheit).
     */
    public function getFirstReadingAtOrAbove(float $targetTempF): ?array
    {
        foreach ($this->readings as $reading) {
            $waterTemp = $this->getWaterTempF($reading);
            if ($waterTemp >= $targetTempF) {
                return $reading;
            }
        }
        return null;
    }

    /**
     * Get the last reading before reaching a target temperature (Fahrenheit).
     */
    public function getLastReadingBelow(float $targetTempF): ?array
    {
        $lastBelow = null;
        foreach ($this->readings as $reading) {
            $waterTemp = $this->getWaterTempF($reading);
            if ($waterTemp < $targetTempF) {
                $lastBelow = $reading;
            } else {
                break;
            }
        }
        return $lastBelow;
    }

    /**
     * Get water temperature (Fahrenheit) from a reading.
     */
    public function getWaterTempF(array $reading): float
    {
        foreach ($reading['sensors'] as $sensor) {
            if ($sensor['role'] === 'water') {
                return $sensor['temp_f'];
            }
        }
        throw new \RuntimeException('No water sensor found in reading');
    }

    /**
     * Get water temperature (Celsius) from a reading.
     */
    public function getWaterTempC(array $reading): float
    {
        foreach ($reading['sensors'] as $sensor) {
            if ($sensor['role'] === 'water') {
                return $sensor['temp_c'];
            }
        }
        throw new \RuntimeException('No water sensor found in reading');
    }

    /**
     * Convert a reading to the format expected by Esp32TemperatureController.
     * This is what would be POSTed to /api/esp32/temperature.
     */
    public function toApiRequest(array $reading): array
    {
        // Strip out the 'role' field and 'minute' as those aren't part of the API
        $sensors = array_map(
            fn($s) => [
                'address' => $s['address'],
                'temp_c' => $s['temp_c'],
            ],
            $reading['sensors']
        );

        return [
            'device_id' => $reading['device_id'],
            'sensors' => $sensors,
            'uptime_seconds' => $reading['uptime_seconds'],
        ];
    }

    /**
     * Get the minute when target temperature is reached.
     * Returns null if target is never reached in the fixture.
     */
    public function getMinuteAtTemperature(float $targetTempF): ?int
    {
        $reading = $this->getFirstReadingAtOrAbove($targetTempF);
        return $reading ? $reading['minute'] : null;
    }

    /**
     * Calculate heating rate from readings (for verification).
     */
    public function calculateHeatingRate(): float
    {
        $first = $this->readings[0];
        $last = end($this->readings);

        $tempDiff = $this->getWaterTempF($last) - $this->getWaterTempF($first);
        $timeDiff = $last['minute'] - $first['minute'];

        return $tempDiff / $timeDiff;
    }
}
