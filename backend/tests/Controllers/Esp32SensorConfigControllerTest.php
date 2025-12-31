<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\Esp32SensorConfigController;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\Esp32TemperatureService;

/**
 * Unit tests for Esp32SensorConfigController.
 *
 * Tests the API endpoints for managing ESP32 sensor configuration.
 */
class Esp32SensorConfigControllerTest extends TestCase
{
    private string $configFile;
    private string $tempFile;
    private Esp32SensorConfigService $configService;
    private Esp32TemperatureService $tempService;
    private Esp32SensorConfigController $controller;

    protected function setUp(): void
    {
        $this->configFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';
        $this->tempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->configService = new Esp32SensorConfigService($this->configFile);
        $this->tempService = new Esp32TemperatureService($this->tempFile);
        $this->controller = new Esp32SensorConfigController($this->configService, $this->tempService);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
        if (file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
    }

    // ==================== List Sensors Tests ====================

    /**
     * @test
     */
    public function listReturnsEmptyArrayWhenNoSensors(): void
    {
        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('sensors', $response['body']);
        $this->assertEmpty($response['body']['sensors']);
    }

    /**
     * @test
     */
    public function listReturnsSensorsFromLatestTemperatureReading(): void
    {
        // Store temperature data with sensors
        $this->tempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 38.5],
                ['address' => '28:D5:AA:87:00:23:16:34', 'temp_c' => 22.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertCount(2, $response['body']['sensors']);
    }

    /**
     * @test
     */
    public function listIncludesConfigurationForEachSensor(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        // Configure a sensor
        $this->configService->setSensorRole($address, 'water');
        $this->configService->setCalibrationOffset($address, 0.5);
        $this->configService->setSensorName($address, 'Hot Tub Water');

        // Store temperature data
        $this->tempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $address, 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        $response = $this->controller->list();

        $sensor = $response['body']['sensors'][0];
        $this->assertEquals($address, $sensor['address']);
        $this->assertEquals('water', $sensor['role']);
        $this->assertEquals(0.5, $sensor['calibration_offset']);
        $this->assertEquals('Hot Tub Water', $sensor['name']);
        $this->assertEquals(38.5, $sensor['temp_c']);
    }

    // ==================== Update Sensor Tests ====================

    /**
     * @test
     */
    public function updateSetsRole(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, ['role' => 'water']);

        $this->assertEquals(200, $response['status']);

        $config = $this->configService->getSensorConfig($address);
        $this->assertEquals('water', $config['role']);
    }

    /**
     * @test
     */
    public function updateSetsCalibrationOffset(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, ['calibration_offset' => -0.5]);

        $this->assertEquals(200, $response['status']);

        $config = $this->configService->getSensorConfig($address);
        $this->assertEquals(-0.5, $config['calibration_offset']);
    }

    /**
     * @test
     */
    public function updateSetsName(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, ['name' => 'Pool Water']);

        $this->assertEquals(200, $response['status']);

        $config = $this->configService->getSensorConfig($address);
        $this->assertEquals('Pool Water', $config['name']);
    }

    /**
     * @test
     */
    public function updateSetsMultipleFieldsAtOnce(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, [
            'role' => 'ambient',
            'calibration_offset' => 1.2,
            'name' => 'Outside Air',
        ]);

        $this->assertEquals(200, $response['status']);

        $config = $this->configService->getSensorConfig($address);
        $this->assertEquals('ambient', $config['role']);
        $this->assertEquals(1.2, $config['calibration_offset']);
        $this->assertEquals('Outside Air', $config['name']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidRole(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, ['role' => 'invalid']);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400ForEmptyData(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, []);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturnsUpdatedConfig(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $response = $this->controller->update($address, [
            'role' => 'water',
            'calibration_offset' => 0.3,
            'name' => 'Main Sensor',
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('sensor', $response['body']);
        $this->assertEquals($address, $response['body']['sensor']['address']);
        $this->assertEquals('water', $response['body']['sensor']['role']);
        $this->assertEquals(0.3, $response['body']['sensor']['calibration_offset']);
        $this->assertEquals('Main Sensor', $response['body']['sensor']['name']);
    }
}
