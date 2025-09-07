<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * WirelessTag Test Data Provider
 * 
 * Provides realistic test data for WirelessTagClient when operating in test mode.
 * Simulates temperature sensor responses without requiring external API calls.
 */
class WirelessTagTestDataProvider
{
    private static array $temperatureSequences = [];
    private static array $currentSequenceIndex = [];
    private static string $defaultDeviceId = '217af407-0165-462d-be07-809e82f6a865';
    
    /**
     * Get simulated temperature data for a device
     * 
     * @param string $deviceId Device UUID
     * @return array|null Simulated temperature data or null to simulate API failure
     */
    public static function getTemperatureData(string $deviceId): ?array
    {
        // For testing, always return data (remove random failures that cause test flakiness)
        // Real failures should be tested separately in dedicated failure tests
        
        $temperature = self::getNextTemperatureForDevice($deviceId);
        $ambientTemp = $temperature - 15.0; // Ambient is typically 15°F cooler
        
        return [
            [
                'uuid' => $deviceId,
                'name' => 'Hot tub temperature',
                'temperature' => ($temperature - 32) * 5 / 9, // Convert to Celsius
                'cap' => ($ambientTemp - 32) * 5 / 9,          // Ambient temp in Celsius
                'lastComm' => self::generateNetTicks(),
                'batteryVolt' => self::generateBatteryVoltage(),
                'signaldBm' => self::generateSignalStrength(),
                'alive' => true
            ]
        ];
    }
    
    /**
     * Set temperature sequence for a device
     * 
     * @param string $deviceId Device UUID
     * @param array $temperatures Array of temperatures in Fahrenheit
     */
    public static function setTemperatureSequence(string $deviceId, array $temperatures): void
    {
        self::$temperatureSequences[$deviceId] = $temperatures;
        self::$currentSequenceIndex[$deviceId] = 0;
    }
    
    /**
     * Get next temperature for device from sequence, or generate realistic temperature
     */
    private static function getNextTemperatureForDevice(string $deviceId): float
    {
        // If we have a predefined sequence, use it
        if (isset(self::$temperatureSequences[$deviceId])) {
            $sequence = self::$temperatureSequences[$deviceId];
            $currentIndex = self::$currentSequenceIndex[$deviceId] ?? 0;
            
            if ($currentIndex < count($sequence)) {
                $temperature = $sequence[$currentIndex];
                self::$currentSequenceIndex[$deviceId] = $currentIndex + 1;
                return $temperature;
            }
            
            // If sequence is exhausted, return last temperature
            return end($sequence);
        }
        
        // Generate realistic hot tub temperature (between 85°F and 105°F)
        return 85.0 + (rand(0, 2000) / 100.0); // 85.00 to 105.00°F
    }
    
    /**
     * Generate realistic .NET ticks timestamp
     */
    private static function generateNetTicks(): int
    {
        $dotNetEpochOffset = 621355968000000000;
        return $dotNetEpochOffset + (time() * 10000000);
    }
    
    /**
     * Generate realistic battery voltage (3.2V to 3.8V)
     */
    private static function generateBatteryVoltage(): float
    {
        return 3.2 + (rand(0, 600) / 1000.0); // 3.200 to 3.800V
    }
    
    /**
     * Generate realistic signal strength (-60dBm to -95dBm)
     */
    private static function generateSignalStrength(): int
    {
        return -60 - rand(0, 35); // -60 to -95 dBm
    }
    
    /**
     * Create a heating progression sequence from start to target temperature
     * 
     * @param float $startTempF Starting temperature in Fahrenheit
     * @param float $targetTempF Target temperature in Fahrenheit
     * @param int $intervalMinutes Minutes between readings
     * @param float $heatingRate Heating rate in °F per minute (default 0.5)
     * @return array Array of temperatures for the sequence
     */
    public static function createHeatingSequence(
        float $startTempF,
        float $targetTempF,
        int $intervalMinutes = 5,
        float $heatingRate = 0.5
    ): array {
        $sequence = [];
        $currentTemp = $startTempF;
        $tempIncrement = $heatingRate * $intervalMinutes;
        
        while ($currentTemp < $targetTempF) {
            $sequence[] = $currentTemp;
            $currentTemp += $tempIncrement;
            
            // Add small random variation (±0.2°F) for realism
            $currentTemp += (rand(-20, 20) / 100.0);
        }
        
        // Always end at target temperature
        $sequence[] = $targetTempF;
        
        return $sequence;
    }
    
    /**
     * Create precision monitoring sequence (when within 1°F of target)
     * 
     * @param float $currentTempF Current temperature
     * @param float $targetTempF Target temperature
     * @param int $readings Number of readings to generate
     * @return array Array of temperatures gradually approaching target
     */
    public static function createPrecisionSequence(
        float $currentTempF,
        float $targetTempF,
        int $readings = 8
    ): array {
        $sequence = [];
        $tempDiff = $targetTempF - $currentTempF;
        $increment = $tempDiff / ($readings - 1); // -1 so we end at target
        
        for ($i = 0; $i < $readings; $i++) {
            $temp = $currentTempF + ($increment * $i);
            
            // Only add small random variation for readings that aren't the target
            if ($i < $readings - 1) {
                $temp += (rand(-3, 3) / 100.0); // ±0.03°F for intermediate readings
            } else {
                // Ensure the last reading reaches the target exactly
                $temp = $targetTempF;
            }
            
            $sequence[] = $temp;
        }
        
        return $sequence;
    }
    
    /**
     * Reset all sequences and indices
     */
    public static function reset(): void
    {
        self::$temperatureSequences = [];
        self::$currentSequenceIndex = [];
    }
    
    /**
     * Test connectivity data (always successful in test mode)
     */
    public static function getConnectivityTestData(): array
    {
        return [
            'available' => true,
            'authenticated' => true,
            'tested_at' => date('Y-m-d H:i:s'),
            'response_time_ms' => rand(50, 200), // Simulate realistic response time
            'error' => null
        ];
    }
}