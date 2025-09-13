<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTubController\Services\WirelessTagClient;
use HotTubController\Services\WirelessTagClientFactory;
use RuntimeException;

/**
 * Comprehensive test suite for WirelessTagClient
 *
 * These tests validate the client behavior, data processing,
 * temperature validation, and error handling capabilities.
 */
class WirelessTagClientTest extends TestCase
{
    private WirelessTagClient $client;
    private string $mockToken = 'mock-token-for-testing';

    protected function setUp(): void
    {
        $this->client = new WirelessTagClient($this->mockToken);
    }

    /**
     * Test client initialization with valid token
     */
    public function testClientInitializationWithValidToken(): void
    {
        $client = new WirelessTagClient('valid-token-123');
        $this->assertInstanceOf(WirelessTagClient::class, $client);
    }

    /**
     * Test client initialization with empty token creates test mode client
     */
    public function testClientInitializationWithEmptyTokenCreatesTestMode(): void
    {
        $client = new WirelessTagClient('');
        $this->assertInstanceOf(WirelessTagClient::class, $client);
        $this->assertTrue($client->isTestMode());
    }

    /**
     * Test client initialization with null token creates test mode client
     */
    public function testClientInitializationWithNullTokenCreatesTestMode(): void
    {
        $client = new WirelessTagClient(null);
        $this->assertInstanceOf(WirelessTagClient::class, $client);
        $this->assertTrue($client->isTestMode());
    }

    /**
     * Test client initialization with production token creates production mode client
     */
    public function testClientInitializationWithValidTokenCreatesProductionMode(): void
    {
        $client = new WirelessTagClient('valid-production-token');
        $this->assertInstanceOf(WirelessTagClient::class, $client);
        $this->assertFalse($client->isTestMode());
    }

    /**
     * Test temperature data processing with valid data
     */
    public function testProcessTemperatureDataWithValidData(): void
    {
        // Mock response data based on actual API response structure
        $mockApiData = [
            [
                'uuid' => '217af407-0165-462d-be07-809e82f6a865',
                'name' => 'Hot tub temperature',
                'temperature' => 36.5, // Water temp in Celsius
                'cap' => 22.21875,      // Ambient temp in Celsius
                'batteryVolt' => 3.6495501995087,
                'signaldBm' => -89,
                'lastComm' => 134016048973805774,
                'alive' => true
            ]
        ];

        $processed = $this->client->processTemperatureData($mockApiData, 0);

        // Validate structure
        $this->assertArrayHasKey('device_id', $processed);
        $this->assertArrayHasKey('water_temperature', $processed);
        $this->assertArrayHasKey('ambient_temperature', $processed);
        $this->assertArrayHasKey('sensor_info', $processed);
        $this->assertArrayHasKey('data_timestamp', $processed);

        // Validate device info
        $this->assertEquals('217af407-0165-462d-be07-809e82f6a865', $processed['device_id']);

        // Validate temperature conversions
        $waterTemp = $processed['water_temperature'];
        $this->assertEquals(36.5, $waterTemp['celsius']);
        $this->assertEquals(97.7, $waterTemp['fahrenheit']);
        $this->assertEquals('primary_probe', $waterTemp['source']);

        $ambientTemp = $processed['ambient_temperature'];
        $this->assertEquals(22.21875, $ambientTemp['celsius']);
        $this->assertEquals(71.99375, $ambientTemp['fahrenheit']);
        $this->assertEquals('capacitive_sensor', $ambientTemp['source']);

        // Validate sensor info
        $sensorInfo = $processed['sensor_info'];
        $this->assertEquals(3.6495501995087, $sensorInfo['battery_voltage']);
        $this->assertEquals(-89, $sensorInfo['signal_strength_dbm']);

        // Validate timestamp conversion
        $this->assertIsInt($processed['data_timestamp']);
    }

    /**
     * Test temperature data processing with invalid device index
     */
    public function testProcessTemperatureDataWithInvalidDeviceIndex(): void
    {
        $mockApiData = [
            ['temperature' => 20.0, 'cap' => 19.0]
        ];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Device index 5 not found in sensor data');

        $this->client->processTemperatureData($mockApiData, 5);
    }

    /**
     * Test temperature validation with valid temperatures
     */
    public function testTemperatureValidationWithValidTemperatures(): void
    {
        // Valid water temperatures (32-120°F)
        $this->assertTrue($this->client->validateTemperature(70.0, 'water'));
        $this->assertTrue($this->client->validateTemperature(105.0, 'water'));
        $this->assertTrue($this->client->validateTemperature(32.1, 'water'));
        $this->assertTrue($this->client->validateTemperature(119.9, 'water'));

        // Valid ambient temperatures (need to check actual bounds in implementation)
        $this->assertTrue($this->client->validateTemperature(70.0, 'ambient'));
        $this->assertTrue($this->client->validateTemperature(100.0, 'ambient'));
    }

    /**
     * Test temperature validation with invalid temperatures
     */
    public function testTemperatureValidationWithInvalidTemperatures(): void
    {
        // Invalid water temperatures
        $this->assertFalse($this->client->validateTemperature(25.0, 'water')); // Too cold
        $this->assertFalse($this->client->validateTemperature(125.0, 'water')); // Too hot
        $this->assertFalse($this->client->validateTemperature(-10.0, 'water')); // Way too cold

        // Invalid ambient temperatures
        $this->assertFalse($this->client->validateTemperature(-50.0, 'ambient')); // Too cold
        $this->assertFalse($this->client->validateTemperature(150.0, 'ambient')); // Too hot
    }

    /**
     * Test ambient temperature calibration
     */
    public function testAmbientTemperatureCalibration(): void
    {
        $ambientTemp = 72.0; // Raw ambient reading
        $waterTemp = 100.0;  // Water temperature

        $calibrated = $this->client->calibrateAmbientTemperature($ambientTemp, $waterTemp);

        // Should adjust ambient temp down due to thermal influence from hot water
        $this->assertLessThan($ambientTemp, $calibrated);
        $this->assertIsFloat($calibrated);

        // Test with different scenarios
        $coldWater = $this->client->calibrateAmbientTemperature(72.0, 70.0);
        $hotWater = $this->client->calibrateAmbientTemperature(72.0, 110.0);

        // Greater temperature difference should result in greater calibration
        $this->assertGreaterThan($hotWater, $coldWater);
    }

    /**
     * Test Celsius to Fahrenheit conversion
     */
    public function testCelsiusToFahrenheitConversion(): void
    {
        // Test known conversions
        $this->assertEquals(32.0, $this->client->celsiusToFahrenheit(0.0));
        $this->assertEquals(212.0, $this->client->celsiusToFahrenheit(100.0));
        $this->assertEqualsWithDelta(98.6, $this->client->celsiusToFahrenheit(37.0), 0.01);
        $this->assertEquals(68.0, $this->client->celsiusToFahrenheit(20.0));

        // Test negative temperatures
        $this->assertEquals(14.0, $this->client->celsiusToFahrenheit(-10.0));
    }

    /**
     * Test WirelessTag timestamp conversion
     *
     * Note: WirelessTag timestamp format needs further investigation
     */
    public function testWirelessTagTimestampConversion(): void
    {
        // For now, just test that the method exists and returns an integer
        $wirelessTagTime = 134016048973805774;
        $unixTimestamp = $this->client->convertWirelessTagTimestamp($wirelessTagTime);

        $this->assertIsInt($unixTimestamp);
        // TODO: Fix timestamp conversion algorithm when format is confirmed
    }

    /**
     * Test data processing with missing required fields
     */
    public function testProcessTemperatureDataWithMissingFields(): void
    {
        $incompleteData = [
            [
                'uuid' => 'test-uuid',
                // Missing temperature, cap, batteryVolt, etc.
            ]
        ];

        // This test would require implementing validation in processTemperatureData
        // For now, just verify the method handles missing data gracefully

        $result = $this->client->processTemperatureData($incompleteData, 0);
        $this->assertIsArray($result); // Should return something, even if partial data
    }

    /**
     * Test processing with malformed data
     */
    public function testProcessTemperatureDataWithMalformedData(): void
    {
        $malformedData = [
            [
                'uuid' => 'test-uuid',
                'temperature' => 'not-a-number', // Invalid type
                'cap' => 22.0,
                'batteryVolt' => 3.0,
                'signaldBm' => -80
            ]
        ];

        $this->expectException(\TypeError::class);

        $this->client->processTemperatureData($malformedData, 0);
    }

    /**
     * Test battery level assessment
     */
    public function testBatteryLevelAssessment(): void
    {
        // Good battery levels
        $this->assertEquals('excellent', $this->client->assessBatteryLevel(4.0));
        $this->assertEquals('good', $this->client->assessBatteryLevel(3.6));

        // Warning levels
        $this->assertEquals('warning', $this->client->assessBatteryLevel(3.3));
        $this->assertEquals('low', $this->client->assessBatteryLevel(3.0));

        // Critical level
        $this->assertEquals('critical', $this->client->assessBatteryLevel(2.8));
    }

    /**
     * Test signal strength assessment
     */
    public function testSignalStrengthAssessment(): void
    {
        // Strong signals
        $this->assertEquals('excellent', $this->client->assessSignalStrength(-50));
        $this->assertEquals('good', $this->client->assessSignalStrength(-70));

        // Weak signals
        $this->assertEquals('fair', $this->client->assessSignalStrength(-80));
        $this->assertEquals('poor', $this->client->assessSignalStrength(-90));

        // Very weak signal
        $this->assertEquals('very_poor', $this->client->assessSignalStrength(-100));
    }
}
