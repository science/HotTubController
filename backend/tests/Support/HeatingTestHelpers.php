<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\Fixtures\VCRCassetteGenerator;
use Tests\Fixtures\TemperatureSequenceBuilder;
use HotTubController\Services\WirelessTagClient;
use VCR\VCR;
use PHPUnit\Framework\Assert;

/**
 * Heating Test Helpers
 *
 * Helper functions for testing hot tub heating cycles with VCR simulation.
 * Provides high-level testing utilities for temperature progression validation.
 */
class HeatingTestHelpers
{
    private VCRCassetteGenerator $cassetteGenerator;
    private TemperatureSequenceBuilder $sequenceBuilder;
    private VCRResponseInterceptor $interceptor;

    public function __construct()
    {
        $this->cassetteGenerator = new VCRCassetteGenerator();
        $this->sequenceBuilder = new TemperatureSequenceBuilder();
        $this->interceptor = new VCRResponseInterceptor();
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
        // Generate temperature sequence
        $temperatureSequence = $this->sequenceBuilder->buildHeatingSequence(
            $startTempF,
            $targetTempF,
            5 // 5-minute intervals
        );

        // Generate VCR cassette
        $cassetteFile = $this->cassetteGenerator->generateHeatingCycle(
            $startTempF,
            $targetTempF,
            $deviceId
        );

        // Configure VCR
        $this->setupVCR(basename($cassetteFile));

        // Enable response interception
        $this->interceptor->enable($temperatureSequence);

        $results = [
            'start_temp_f' => $startTempF,
            'target_temp_f' => $targetTempF,
            'total_readings' => count($temperatureSequence),
            'expected_duration_minutes' => $this->sequenceBuilder->calculateHeatingDuration($startTempF, $targetTempF),
            'readings' => [],
            'heating_rate_achieved' => 0.0,
            'test_results' => []
        ];

        try {
            // Execute test callback
            $testResults = $testCallback($deviceId, $temperatureSequence);
            $results['test_results'] = $testResults;

            // Calculate achieved heating rate
            if (count($temperatureSequence) > 1) {
                $firstReading = $temperatureSequence[0];
                $lastReading = end($temperatureSequence);
                $tempRise = $lastReading['water_temp_f'] - $firstReading['water_temp_f'];
                $timeMinutes = $lastReading['minutes_elapsed'] - $firstReading['minutes_elapsed'];

                $results['heating_rate_achieved'] = $timeMinutes > 0 ? $tempRise / $timeMinutes : 0.0;
            }
        } finally {
            // Cleanup VCR
            $this->interceptor->disable();
            VCR::eject();
            VCR::turnOff();
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

        // Generate cassette for precision monitoring
        $cassetteFile = $this->cassetteGenerator->generatePrecisionMonitoring(
            $currentTempF,
            $targetTempF,
            $deviceId
        );

        $this->setupVCR(basename($cassetteFile));
        $this->interceptor->enable($precisionSequence);

        $results = [
            'start_temp_f' => $currentTempF,
            'target_temp_f' => $targetTempF,
            'precision_readings' => count($precisionSequence),
            'interval_seconds' => 15,
            'readings_tested' => 0,
            'target_reached' => false
        ];

        try {
            // Test each precision reading
            foreach ($precisionSequence as $index => $expectedReading) {
                $tempData = $client->getCachedTemperatureData($deviceId);
                Assert::assertNotNull($tempData, "Should receive temperature data");

                $processed = $client->processTemperatureData($tempData, 0);
                $actualTempF = $processed['water_temperature']['fahrenheit'];

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

                // Check if target reached
                if ($actualTempF >= $targetTempF) {
                    $results['target_reached'] = true;
                    break;
                }
            }
        } finally {
            $this->interceptor->disable();
            VCR::eject();
            VCR::turnOff();
        }

        return $results;
    }

    /**
     * Inject specific temperature reading into next VCR response
     *
     * @param float $waterTempF Water temperature in Fahrenheit
     * @param float $ambientTempF Ambient temperature in Fahrenheit (optional)
     * @param int $timestamp Unix timestamp for the reading (optional)
     */
    public function injectTemperatureReading(
        float $waterTempF,
        ?float $ambientTempF = null,
        ?int $timestamp = null
    ): void {
        $timestamp = $timestamp ?: time();
        $ambientTempF = $ambientTempF ?: ($waterTempF - 25.0); // Default offset

        $reading = [
            'water_temp_f' => $waterTempF,
            'water_temp_c' => ($waterTempF - 32) * 5 / 9,
            'ambient_temp_f' => $ambientTempF,
            'ambient_temp_c' => ($ambientTempF - 32) * 5 / 9,
            'battery_voltage' => 3.65,
            'signal_strength' => -85,
            'unix_timestamp' => $timestamp,
            'timestamp_ticks' => $this->unixToNetTicks($timestamp),
            'minutes_elapsed' => 0,
            'is_failure' => false
        ];

        $this->interceptor->enable([$reading]);
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
     * @param float $expectedHeatingRate Expected heating rate in °F/min
     * @param float $tolerance Tolerance for heating rate validation
     */
    public function assertHeatingBehavior(
        array $temperatureReadings,
        float $expectedHeatingRate = 0.5,
        float $tolerance = 0.1
    ): void {
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
     * Setup VCR configuration
     */
    private function setupVCR(string $cassetteName): void
    {
        $cassettesDir = __DIR__ . '/../cassettes/generated';

        VCR::configure()
            ->setCassettePath($cassettesDir)
            ->setMode('once') // Replay mode
            ->enableRequestMatchers(['method', 'url', 'body'])
            ->enableLibraryHooks(['curl']);

        VCR::turnOn();
        VCR::insertCassette($cassetteName);
    }

    /**
     * Convert Unix timestamp to .NET ticks
     */
    private function unixToNetTicks(int $unixTimestamp): int
    {
        $dotNetEpochOffset = 621355968000000000;
        return $dotNetEpochOffset + ($unixTimestamp * 10000000);
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
