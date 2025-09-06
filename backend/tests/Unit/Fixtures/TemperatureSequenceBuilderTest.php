<?php

declare(strict_types=1);

namespace Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use Tests\Fixtures\TemperatureSequenceBuilder;
use InvalidArgumentException;

/**
 * Test suite for Temperature Sequence Builder
 * 
 * Validates heating sequence generation, precision monitoring,
 * and temperature progression logic.
 */
class TemperatureSequenceBuilderTest extends TestCase
{
    private TemperatureSequenceBuilder $builder;
    
    protected function setUp(): void
    {
        $this->builder = new TemperatureSequenceBuilder();
    }
    
    /**
     * Test basic heating sequence generation
     */
    public function testBuildHeatingSequence(): void
    {
        $startTempF = 88.0;
        $targetTempF = 102.0;
        $intervalMinutes = 5;
        
        $sequence = $this->builder->buildHeatingSequence($startTempF, $targetTempF, $intervalMinutes);
        
        $this->assertNotEmpty($sequence);
        $this->assertIsArray($sequence);
        
        // Validate first reading
        $firstReading = $sequence[0];
        $this->assertEqualsWithDelta($startTempF, $firstReading['water_temp_f'], 0.1);
        $this->assertEquals(0, $firstReading['minutes_elapsed']);
        $this->assertFalse($firstReading['is_failure']);
        
        // Validate temperature progression
        $previousTemp = $startTempF;
        foreach ($sequence as $reading) {
            $currentTemp = $reading['water_temp_f'];
            $this->assertGreaterThanOrEqual($previousTemp - 0.1, $currentTemp);
            $this->assertLessThanOrEqual($targetTempF + 0.1, $currentTemp);
            $previousTemp = $currentTemp;
        }
    }
    
    /**
     * Test heating rate calculation
     */
    public function testHeatingRateCalculation(): void
    {
        $heatingRate = $this->builder->getHeatingRate();
        $this->assertEquals(0.5, $heatingRate);
        
        // Test specific duration calculations
        $duration88to102 = $this->builder->calculateHeatingDuration(88.0, 102.0);
        $this->assertEquals(28, $duration88to102); // 14°F ÷ 0.5°F/min = 28 minutes
        
        $duration90to100 = $this->builder->calculateHeatingDuration(90.0, 100.0);
        $this->assertEquals(20, $duration90to100); // 10°F ÷ 0.5°F/min = 20 minutes
    }
    
    /**
     * Test precision monitoring sequence
     */
    public function testBuildPrecisionSequence(): void
    {
        $currentTempF = 101.0;
        $targetTempF = 102.0;
        $intervalSeconds = 15;
        
        $sequence = $this->builder->buildPrecisionSequence($currentTempF, $targetTempF, $intervalSeconds);
        
        $this->assertNotEmpty($sequence);
        
        // Should reach target within reasonable number of readings
        $this->assertLessThan(20, count($sequence)); // 2 minutes max at 15-second intervals
        
        // Validate progression
        $previousTemp = $currentTempF;
        foreach ($sequence as $reading) {
            $currentTemp = $reading['water_temp_f'];
            $this->assertGreaterThanOrEqual($previousTemp - 0.1, $currentTemp);
            $this->assertLessThanOrEqual($targetTempF + 0.1, $currentTemp);
            $previousTemp = $currentTemp;
        }
        
        // Last reading should reach or be very close to target
        $lastReading = end($sequence);
        $this->assertGreaterThanOrEqual($targetTempF - 0.2, $lastReading['water_temp_f']);
    }
    
    /**
     * Test precision monitoring threshold
     */
    public function testPrecisionThreshold(): void
    {
        $threshold = $this->builder->getPrecisionThreshold();
        $this->assertEquals(1.0, $threshold);
    }
    
    /**
     * Test sensor failure sequence
     */
    public function testBuildSensorFailureSequence(): void
    {
        $startTempF = 90.0;
        $failureAtTempF = 95.0;
        $failureType = 'timeout';
        
        $sequence = $this->builder->buildSensorFailureSequence($startTempF, $failureAtTempF, $failureType);
        
        $this->assertNotEmpty($sequence);
        
        // Should have failure reading at the end
        $lastReading = end($sequence);
        $this->assertTrue($lastReading['is_failure']);
        $this->assertEquals($failureType, $lastReading['failure_type']);
        
        // All readings before failure should be normal
        $normalReadings = array_slice($sequence, 0, -1);
        foreach ($normalReadings as $reading) {
            $this->assertFalse($reading['is_failure']);
            $this->assertNotNull($reading['water_temp_c']);
        }
    }
    
    /**
     * Test invalid parameters throw exceptions
     */
    public function testInvalidParametersThrowExceptions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->buildHeatingSequence(100.0, 90.0, 5); // Start temp higher than target
        
        $this->expectException(InvalidArgumentException::class);
        $this->builder->buildHeatingSequence(90.0, 100.0, 0); // Invalid interval
    }
    
    /**
     * Test temperature unit conversions
     */
    public function testTemperatureConversions(): void
    {
        $sequence = $this->builder->buildHeatingSequence(88.0, 90.0, 5);
        $reading = $sequence[0];
        
        // Validate Fahrenheit to Celsius conversion
        $expectedCelsius = ($reading['water_temp_f'] - 32) * 5/9;
        $this->assertEqualsWithDelta($expectedCelsius, $reading['water_temp_c'], 0.01);
        
        // Validate ambient temperature offset
        $this->assertLessThan($reading['water_temp_f'], $reading['ambient_temp_f']);
    }
    
    /**
     * Test timestamp generation
     */
    public function testTimestampGeneration(): void
    {
        $sequence = $this->builder->buildHeatingSequence(88.0, 90.0, 5);
        
        foreach ($sequence as $reading) {
            $this->assertIsInt($reading['unix_timestamp']);
            $this->assertIsInt($reading['timestamp_ticks']);
            $this->assertGreaterThan(0, $reading['timestamp_ticks']);
            
            // .NET ticks should be much larger than Unix timestamp
            $this->assertGreaterThan($reading['unix_timestamp'], $reading['timestamp_ticks']);
        }
    }
    
    /**
     * Test battery and signal data generation
     */
    public function testSensorDataGeneration(): void
    {
        $sequence = $this->builder->buildHeatingSequence(88.0, 92.0, 5);
        
        foreach ($sequence as $reading) {
            $this->assertIsFloat($reading['battery_voltage']);
            $this->assertGreaterThan(0, $reading['battery_voltage']);
            $this->assertLessThan(5.0, $reading['battery_voltage']); // Reasonable battery voltage
            
            $this->assertIsInt($reading['signal_strength']);
            $this->assertLessThan(0, $reading['signal_strength']); // dBm values are negative
            $this->assertGreaterThan(-120, $reading['signal_strength']); // Reasonable signal range
        }
    }
}