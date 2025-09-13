<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use InvalidArgumentException;
use HotTubController\Config\HeatingConfig;

/**
 * Temperature Sequence Builder
 *
 * Builds realistic temperature sequences for hot tub heating cycle simulation.
 * Implements accurate heating physics with configurable heating rate.
 */
class TemperatureSequenceBuilder
{
    private float $heatingRate;
    private const PRECISION_THRESHOLD_F = 1.0; // Within 1°F triggers precision monitoring

    // Base values for sensor readings
    private const BASE_BATTERY_VOLTAGE = 3.65;
    private const BASE_SIGNAL_STRENGTH = -85;
    private const AMBIENT_TEMP_OFFSET_F = 25.0; // Ambient is typically ~25°F cooler than water

    public function __construct(?HeatingConfig $heatingConfig = null)
    {
        $heatingConfig = $heatingConfig ?? new HeatingConfig();
        $this->heatingRate = $heatingConfig->getHeatingRate();
    }

    /**
     * Build heating sequence from start to target temperature
     *
     * @param float $startTempF Starting water temperature in Fahrenheit
     * @param float $targetTempF Target water temperature in Fahrenheit
     * @param int $intervalMinutes Minutes between temperature readings
     * @return array Array of temperature readings with timestamps
     */
    public function buildHeatingSequence(
        float $startTempF,
        float $targetTempF,
        int $intervalMinutes = 5
    ): array {
        if ($startTempF >= $targetTempF) {
            throw new InvalidArgumentException("Start temperature must be less than target");
        }

        if ($intervalMinutes < 1) {
            throw new InvalidArgumentException("Interval must be at least 1 minute");
        }

        $sequence = [];
        $currentTempF = $startTempF;
        $currentTime = time();

        $temperatureRise = $targetTempF - $startTempF;
        $totalMinutes = ceil($temperatureRise / $this->heatingRate);

        // Generate regular interval readings until within precision threshold
        $minutesElapsed = 0;

        while ($currentTempF < ($targetTempF - self::PRECISION_THRESHOLD_F) && $minutesElapsed < $totalMinutes) {
            $reading = $this->createTemperatureReading(
                $currentTempF,
                $currentTime,
                $minutesElapsed
            );
            $sequence[] = $reading;

            // Advance time and temperature
            $minutesElapsed += $intervalMinutes;
            $currentTime += ($intervalMinutes * 60);
            $currentTempF = min(
                $startTempF + ($minutesElapsed * $this->heatingRate),
                $targetTempF
            );
        }

        // Add precision monitoring sequence (15-second intervals when within 1°F)
        $precisionSequence = $this->buildPrecisionSequence(
            $currentTempF,
            $targetTempF,
            15, // 15-second intervals
            $currentTime,
            $minutesElapsed
        );

        return array_merge($sequence, $precisionSequence);
    }

    /**
     * Build precision monitoring sequence for when approaching target temperature
     *
     * @param float $currentTempF Current temperature (within 1°F of target)
     * @param float $targetTempF Target temperature
     * @param int $intervalSeconds Seconds between readings (typically 15)
     * @param int $startTime Starting timestamp (optional)
     * @param int $elapsedMinutes Minutes elapsed so far (optional)
     * @return array Array of precision temperature readings
     */
    public function buildPrecisionSequence(
        float $currentTempF,
        float $targetTempF,
        int $intervalSeconds = 15,
        ?int $startTime = null,
        int $elapsedMinutes = 0
    ): array {
        $startTime = $startTime ?: time();
        $sequence = [];

        $tempDiff = $targetTempF - $currentTempF;
        if ($tempDiff <= 0) {
            // Already at or above target
            return [$this->createTemperatureReading($currentTempF, $startTime, $elapsedMinutes)];
        }

        // Calculate how many readings needed to reach target
        $heatingRatePerSecond = $this->heatingRate / 60;
        $secondsToTarget = $tempDiff / $heatingRatePerSecond;
        $readingsNeeded = ceil($secondsToTarget / $intervalSeconds);

        $currentTime = $startTime;
        $currentTemp = $currentTempF;

        for ($i = 0; $i <= $readingsNeeded; $i++) {
            $reading = $this->createTemperatureReading(
                $currentTemp,
                $currentTime,
                (int) ($elapsedMinutes + ($i * $intervalSeconds / 60))
            );
            $sequence[] = $reading;

            // Advance time and temperature
            $currentTime += $intervalSeconds;
            $currentTemp = min(
                $currentTemp + ($heatingRatePerSecond * $intervalSeconds),
                $targetTempF
            );

            // Stop once we've reached target
            if ($currentTemp >= $targetTempF) {
                break;
            }
        }

        return $sequence;
    }

    /**
     * Build temperature sequence with sensor failure simulation
     *
     * @param float $startTempF Starting temperature
     * @param float $failureAtTempF Temperature at which sensor fails
     * @param string $failureType Type of failure ('timeout', 'invalid_reading', 'battery_low')
     * @return array Temperature sequence with failure
     */
    public function buildSensorFailureSequence(
        float $startTempF,
        float $failureAtTempF,
        string $failureType = 'timeout'
    ): array {
        $sequence = [];
        $currentTempF = $startTempF;
        $currentTime = time();
        $minutesElapsed = 0;

        // Build sequence until failure point
        while ($currentTempF < $failureAtTempF) {
            $reading = $this->createTemperatureReading(
                $currentTempF,
                $currentTime,
                $minutesElapsed
            );
            $sequence[] = $reading;

            // Advance
            $minutesElapsed += 5;
            $currentTime += 300; // 5 minutes
            $currentTempF += (5 * $this->heatingRate);
        }

        // Add failure reading
        $failureReading = $this->createFailureReading(
            $failureType,
            $currentTime,
            $minutesElapsed
        );
        $sequence[] = $failureReading;

        return $sequence;
    }

    /**
     * Create a temperature reading with realistic sensor data
     *
     * @param float $waterTempF Water temperature in Fahrenheit
     * @param int $unixTimestamp Unix timestamp for the reading
     * @param int $minutesElapsed Minutes elapsed since heating started
     * @return array Temperature reading data
     */
    private function createTemperatureReading(
        float $waterTempF,
        int $unixTimestamp,
        int $minutesElapsed
    ): array {
        // Convert to Celsius for API compatibility
        $waterTempC = ($waterTempF - 32) * 5 / 9;

        // Calculate ambient temperature (cooler than water, with some variation)
        $ambientTempF = $waterTempF - self::AMBIENT_TEMP_OFFSET_F + mt_rand(-3, 3);
        $ambientTempC = ($ambientTempF - 32) * 5 / 9;

        // Simulate battery degradation over time
        $batteryVoltage = self::BASE_BATTERY_VOLTAGE - ($minutesElapsed * 0.001);

        // Simulate signal strength variation
        $signalStrength = self::BASE_SIGNAL_STRENGTH + mt_rand(-10, 5);

        // Convert Unix timestamp to .NET ticks for WirelessTag compatibility
        $dotNetTicks = $this->unixTimestampToNetTicks($unixTimestamp);

        return [
            'water_temp_f' => round($waterTempF, 2),
            'water_temp_c' => round($waterTempC, 4),
            'ambient_temp_f' => round($ambientTempF, 2),
            'ambient_temp_c' => round($ambientTempC, 4),
            'battery_voltage' => round($batteryVoltage, 4),
            'signal_strength' => $signalStrength,
            'unix_timestamp' => $unixTimestamp,
            'timestamp_ticks' => $dotNetTicks,
            'minutes_elapsed' => $minutesElapsed,
            'is_failure' => false
        ];
    }

    /**
     * Create a failure reading for sensor malfunction testing
     */
    private function createFailureReading(
        string $failureType,
        int $unixTimestamp,
        int $minutesElapsed
    ): array {
        $baseReading = [
            'unix_timestamp' => $unixTimestamp,
            'timestamp_ticks' => $this->unixTimestampToNetTicks($unixTimestamp),
            'minutes_elapsed' => $minutesElapsed,
            'is_failure' => true,
            'failure_type' => $failureType
        ];

        switch ($failureType) {
            case 'timeout':
                // No response data - simulates communication timeout
                return array_merge($baseReading, [
                    'water_temp_c' => null,
                    'ambient_temp_c' => null,
                    'battery_voltage' => null,
                    'signal_strength' => null
                ]);

            case 'invalid_reading':
                // Invalid temperature values
                return array_merge($baseReading, [
                    'water_temp_c' => -999.0,
                    'ambient_temp_c' => -999.0,
                    'battery_voltage' => 3.2,
                    'signal_strength' => -95
                ]);

            case 'battery_low':
                // Very low battery affecting sensor accuracy
                return array_merge($baseReading, [
                    'water_temp_c' => 25.0 + mt_rand(-50, 50), // Erratic readings
                    'ambient_temp_c' => 20.0 + mt_rand(-30, 30),
                    'battery_voltage' => 2.1, // Critical low
                    'signal_strength' => -98
                ]);

            default:
                throw new InvalidArgumentException("Unknown failure type: {$failureType}");
        }
    }

    /**
     * Convert Unix timestamp to .NET ticks
     *
     * .NET ticks are 100-nanosecond intervals since January 1, 0001
     */
    private function unixTimestampToNetTicks(int $unixTimestamp): int
    {
        // .NET epoch offset (ticks between year 0001 and Unix epoch 1970)
        $dotNetEpochOffset = 621355968000000000;

        // Convert seconds to 100-nanosecond ticks
        $unixTicks = $unixTimestamp * 10000000;

        return $dotNetEpochOffset + $unixTicks;
    }

    /**
     * Get the heating rate in °F per minute
     */
    public function getHeatingRate(): float
    {
        return $this->heatingRate;
    }

    /**
     * Get the precision monitoring threshold in °F
     */
    public function getPrecisionThreshold(): float
    {
        return self::PRECISION_THRESHOLD_F;
    }

    /**
     * Calculate expected heating duration in minutes
     */
    public function calculateHeatingDuration(float $startTempF, float $targetTempF): int
    {
        $tempRise = $targetTempF - $startTempF;
        return (int) ceil($tempRise / $this->heatingRate);
    }
}
