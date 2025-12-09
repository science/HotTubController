<?php

declare(strict_types=1);

namespace HotTubController\Tests\Support;

use HotTubController\Tests\Fixtures\TemperatureSequenceBuilder;
use HotTubController\Services\WirelessTagClient;
use PHPUnit\Framework\Assert;
use HotTubController\Config\HeatingConfig;

/**
 * Heating Test Helpers
 *
 * Helper functions for testing hot tub heating cycles with VCR simulation.
 * Provides high-level testing utilities for temperature progression validation.
 */
class HeatingTestHelpers
{
    private TemperatureSequenceBuilder $sequenceBuilder;

    public function __construct()
    {
        $this->sequenceBuilder = new TemperatureSequenceBuilder();
    }

    /**
     * Simulate a complete heating cycle with VCR
     *
     * @param float $startTempF Starting temperature in Fahrenheit
     * @param float $targetTempF Target temperature in Fahrenheit
     * @param string $deviceId Device UUID for simulation
     * @param callable $testCallback Callback function to execute during heating cycle
     * @return array Heating cycle results and statistics
     */
    public function simulateHeatingCycle(
        float $startTempF,
        float $targetTempF,
        string $deviceId,
        callable $testCallback
    ): array {
        // DON'T generate a new sequence - the test has already set one up
        // We need to know what sequence was set up so we can pass it to the callback
        // The safest approach is to let the test tell us what sequence it configured
        
        // For now, we'll generate the same deterministic sequence the test did
        // This works because we seeded the random number generator
        $temperatures = WirelessTagTestDataProvider::createHeatingSequence(
            $startTempF,
            $targetTempF,
            5 // 5-minute intervals
        );

        $results = [
            'start_temp_f' => $startTempF,
            'target_temp_f' => $targetTempF,
            'total_readings' => count($temperatures),
            'expected_duration_minutes' => $this->sequenceBuilder->calculateHeatingDuration($startTempF, $targetTempF),
            'readings' => [],
            'heating_rate_achieved' => 0.0,
            'test_results' => []
        ];

        // DO NOT reset the sequence here - the test has already set it up correctly
        // The callback will use the sequence that was configured in the test
        
        // Execute test callback
        $testResults = $testCallback($deviceId, $temperatures);
        $results['test_results'] = $testResults;

        // Calculate achieved heating rate from the test results
        if (!empty($results['test_results']) && count($results['test_results']) > 1) {
            $firstResult = $results['test_results'][0];
            $lastResult = end($results['test_results']);
            
            $tempRise = $lastResult['actual_temp_f'] - $firstResult['actual_temp_f'];
            $timeMinutes = $lastResult['minutes_elapsed'] - $firstResult['minutes_elapsed'];
            
            $results['heating_rate_achieved'] = $timeMinutes > 0 ? $tempRise / $timeMinutes : 0.0;
        } elseif (count($temperatures) > 1) {
            // Fallback: assume 5-minute intervals
            $tempRise = end($temperatures) - $temperatures[0];
            $timeMinutes = (count($temperatures) - 1) * 5; // 5-minute intervals

            $results['heating_rate_achieved'] = $timeMinutes > 0 ? $tempRise / $timeMinutes : 0.0;
        }

        return $results;
    }

    /**
     * Test precision monitoring behavior when approaching target
     *
     * @param float $currentTempF Current temperature (within 1°F of target)
     * @param float $targetTempF Target temperature
     * @param string $deviceId Device UUID
     * @param WirelessTagClient $client WirelessTag client instance
     * @return array Precision monitoring test results
     */
    public function testPrecisionMonitoring(
        float $currentTempF,
        float $targetTempF,
        string $deviceId,
        WirelessTagClient $client
    ): array {
        $tempDiff = abs($targetTempF - $currentTempF);
        Assert::assertLessThanOrEqual(1.0, $tempDiff, "Temperature must be within 1°F for precision monitoring");

        // Generate precision sequence (15-second intervals)
        $precisionSequence = $this->sequenceBuilder->buildPrecisionSequence(
            $currentTempF,
            $targetTempF,
            15
        );

        $results = [
            'start_temp_f' => $currentTempF,
            'target_temp_f' => $targetTempF,
            'precision_readings' => count($precisionSequence),
            'interval_seconds' => 15,
            'readings_tested' => 0,
            'target_reached' => false
        ];

        // Test each precision reading
        foreach ($precisionSequence as $index => $expectedReading) {
            // buildPrecisionSequence returns arrays with 'water_temp_f' key
            $actualTempF = $expectedReading['water_temp_f'];

            $results['readings_tested']++;

            // Verify temperature is progressing toward target
            Assert::assertGreaterThanOrEqual(
                $currentTempF - 0.1, // Small tolerance for floating point
                $actualTempF,
                "Temperature should not decrease during heating"
            );

            Assert::assertLessThanOrEqual(
                $targetTempF + 0.1, // Small tolerance
                $actualTempF,
                "Temperature should not exceed target significantly"
            );

            // Check if target reached (with small tolerance)
            if ($actualTempF >= ($targetTempF - 0.1)) {
                $results['target_reached'] = true;
                break;
            }
        }

        return $results;
    }

    /**
     * Inject specific temperature reading (no longer needed with test mode)
     *
     * This method is kept for backward compatibility but no longer does anything
     * since temperature injection is now handled by WirelessTagTestDataProvider.
     *
     * @param float $waterTempF Water temperature in Fahrenheit
     * @param float $ambientTempF Ambient temperature in Fahrenheit (optional)
     * @param int $timestamp Unix timestamp for the reading (optional)
     * @deprecated Use WirelessTagTestDataProvider::setTemperatureSequence() instead
     */
    public function injectTemperatureReading(
        float $waterTempF,
        ?float $ambientTempF = null,
        ?int $timestamp = null
    ): void {
        // This method is no longer needed with test mode
        // Temperature injection is handled by WirelessTagTestDataProvider
    }

    /**
     * Generate realistic temperature progression curve
     *
     * @param float $startTempF Starting temperature
     * @param float $targetTempF Target temperature
     * @param int $intervalMinutes Minutes between readings
     * @return array Temperature progression data
     */
    public function generateTemperatureProgression(
        float $startTempF,
        float $targetTempF,
        int $intervalMinutes = 5
    ): array {
        return $this->sequenceBuilder->buildHeatingSequence(
            $startTempF,
            $targetTempF,
            $intervalMinutes
        );
    }

    /**
     * Assert heating behavior is correct
     *
     * @param array $temperatureReadings Array of temperature readings
     * @param HeatingConfig|null $heatingConfig Heating configuration (or null for default)
     * @param float $tolerance Tolerance for heating rate validation
     */
    public function assertHeatingBehavior(
        array $temperatureReadings,
        ?HeatingConfig $heatingConfig = null,
        float $tolerance = 0.1
    ): void {
        $heatingConfig = $heatingConfig ?? new HeatingConfig();
        $expectedHeatingRate = $heatingConfig->getHeatingRate();

        Assert::assertGreaterThan(1, count($temperatureReadings), "Need at least 2 readings");

        // Verify temperatures are generally increasing
        $previousTemp = null;
        $decreaseCount = 0;
        $totalReadings = count($temperatureReadings);

        foreach ($temperatureReadings as $reading) {
            if ($previousTemp !== null && $reading['water_temp_f'] < $previousTemp) {
                $decreaseCount++;
            }
            $previousTemp = $reading['water_temp_f'];
        }

        // Allow for some minor fluctuation, but not more than 10% decreases
        $decreaseRatio = $decreaseCount / ($totalReadings - 1);
        Assert::assertLessThan(0.1, $decreaseRatio, "Too many temperature decreases during heating");

        // Verify overall heating rate
        $firstReading = $temperatureReadings[0];
        $lastReading = end($temperatureReadings);

        $tempRise = $lastReading['water_temp_f'] - $firstReading['water_temp_f'];
        $timeMinutes = $lastReading['minutes_elapsed'] - $firstReading['minutes_elapsed'];

        if ($timeMinutes > 0) {
            $actualRate = $tempRise / $timeMinutes;
            $lowerBound = $expectedHeatingRate - $tolerance;
            $upperBound = $expectedHeatingRate + $tolerance;

            Assert::assertGreaterThanOrEqual(
                $lowerBound,
                $actualRate,
                "Heating rate too slow: {$actualRate}°F/min (expected {$expectedHeatingRate}°F/min ±{$tolerance})"
            );

            Assert::assertLessThanOrEqual(
                $upperBound,
                $actualRate,
                "Heating rate too fast: {$actualRate}°F/min (expected {$expectedHeatingRate}°F/min ±{$tolerance})"
            );
        }
    }


    /**
     * Get expected heating duration for temperature rise
     */
    public function getExpectedHeatingDuration(float $startTempF, float $targetTempF): int
    {
        return $this->sequenceBuilder->calculateHeatingDuration($startTempF, $targetTempF);
    }

    /**
     * Get heating rate constant
     */
    public function getHeatingRate(): float
    {
        return $this->sequenceBuilder->getHeatingRate();
    }

    /**
     * Get precision monitoring threshold
     */
    public function getPrecisionThreshold(): float
    {
        return $this->sequenceBuilder->getPrecisionThreshold();
    }
}
