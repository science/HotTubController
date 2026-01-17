<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\TemperatureController;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\Esp32CalibratedTemperatureService;

/**
 * Unit tests for TemperatureController.
 *
 * Tests ESP32-based temperature readings.
 */
class TemperatureControllerTest extends TestCase
{
    private string $esp32TempFile;
    private string $esp32ConfigFile;
    private Esp32TemperatureService $esp32TempService;
    private Esp32SensorConfigService $esp32ConfigService;
    private Esp32CalibratedTemperatureService $esp32CalibratedService;

    protected function setUp(): void
    {
        $this->esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $this->esp32TempService = new Esp32TemperatureService($this->esp32TempFile);
        $this->esp32ConfigService = new Esp32SensorConfigService($this->esp32ConfigFile);
        $this->esp32CalibratedService = new Esp32CalibratedTemperatureService(
            $this->esp32TempService,
            $this->esp32ConfigService
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->esp32TempFile);
        @unlink($this->esp32ConfigFile);
    }

    /**
     * @test
     */
    public function getReturnsTemperatureDataWithCorrectStructure(): void
    {
        // Configure ESP32 sensors
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $ambientAddress = '28:D5:AA:87:00:23:16:34';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');
        $this->esp32ConfigService->setSensorRole($ambientAddress, 'ambient');

        // Store ESP32 temperature data
        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
                ['address' => $ambientAddress, 'temp_c' => 20.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();

        $this->assertEquals(200, $response['status']);

        $body = $response['body'];
        $this->assertArrayHasKey('water_temp_f', $body);
        $this->assertArrayHasKey('water_temp_c', $body);
        $this->assertArrayHasKey('ambient_temp_f', $body);
        $this->assertArrayHasKey('ambient_temp_c', $body);
        $this->assertArrayHasKey('device_name', $body);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayHasKey('source', $body);
        $this->assertEquals('esp32', $body['source']);
    }

    /**
     * @test
     */
    public function getReturnsNumericTemperatureValues(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();
        $body = $response['body'];

        $this->assertIsFloat($body['water_temp_f']);
        $this->assertIsFloat($body['water_temp_c']);
    }

    /**
     * @test
     */
    public function getReturnsReasonableTemperatureValues(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $ambientAddress = '28:D5:AA:87:00:23:16:34';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');
        $this->esp32ConfigService->setSensorRole($ambientAddress, 'ambient');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],  // 100.4°F
                ['address' => $ambientAddress, 'temp_c' => 20.0],  // 68°F
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();
        $body = $response['body'];

        // Water temp should be around 100.4°F
        $this->assertEqualsWithDelta(100.4, $body['water_temp_f'], 0.5);

        // Ambient temp should be around 68°F
        $this->assertEqualsWithDelta(68.0, $body['ambient_temp_f'], 0.5);
    }

    /**
     * @test
     */
    public function getIncludesTimestamp(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();
        $body = $response['body'];

        $this->assertNotEmpty($body['timestamp']);
        // Timestamp should be a valid ISO 8601 format
        $this->assertNotFalse(strtotime($body['timestamp']));
    }

    /**
     * @test
     */
    public function getReturnsDeviceName(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();
        $body = $response['body'];

        $this->assertIsString($body['device_name']);
        $this->assertEquals('ESP32 Temperature Sensor', $body['device_name']);
    }

    /**
     * @test
     */
    public function getReturns503WhenNoEsp32DataAvailable(): void
    {
        // No data stored in ESP32 service
        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();

        $this->assertEquals(503, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertArrayHasKey('error_code', $response['body']);
        $this->assertEquals('SENSOR_NOT_CONFIGURED', $response['body']['error_code']);
    }

    /**
     * @test
     */
    public function getReturns503WhenEsp32RolesNotAssigned(): void
    {
        // Store ESP32 data but DON'T assign roles
        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();

        $this->assertEquals(503, $response['status']);
        $this->assertEquals('SENSOR_NOT_CONFIGURED', $response['body']['error_code']);
    }

    // ==================== ESP32 Calibration Tests ====================

    /**
     * @test
     * ESP32 calibration offsets should be applied to returned temperatures.
     */
    public function getAppliesEsp32CalibrationOffsets(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');
        $this->esp32ConfigService->setCalibrationOffset($waterAddress, 0.5);  // +0.5°C calibration

        // Store raw ESP32 temperature
        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],  // Raw: 38°C
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        // Should return calibrated value: 38.0 + 0.5 = 38.5°C
        $this->assertEqualsWithDelta(38.5, $response['body']['water_temp_c'], 0.01);
    }

    /**
     * @test
     * ESP32 response should include device metadata (uptime, device_id).
     */
    public function getIncludesEsp32Metadata(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 7200,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $response['body']['device_id']);
        $this->assertEquals(7200, $response['body']['uptime_seconds']);
    }

    // ==================== getAll() Tests ====================

    /**
     * @test
     * getAll should return ESP32 data when available.
     */
    public function getAllReturnsEsp32Data(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('esp32', $response['body']);
        $this->assertNotNull($response['body']['esp32']);
        $this->assertEqualsWithDelta(39.0, $response['body']['esp32']['water_temp_c'], 0.01);
    }

    /**
     * @test
     * getAll should return null esp32 when not configured.
     */
    public function getAllReturnsNullEsp32WhenNotConfigured(): void
    {
        // Create controller with ESP32 service but don't configure sensors
        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('esp32', $response['body']);
        $this->assertNull($response['body']['esp32']);
    }

    /**
     * @test
     * ESP32 response in getAll should include timestamp in ISO 8601 format.
     */
    public function getAllIncludesEsp32TimestampInIso8601Format(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $this->esp32ConfigService->setSensorRole($waterAddress, 'water');

        $this->esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController($this->esp32CalibratedService);
        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertNotNull($response['body']['esp32']);
        $this->assertArrayHasKey('timestamp', $response['body']['esp32']);

        // Verify it's a valid ISO 8601 format (parseable by DateTime)
        $timestamp = $response['body']['esp32']['timestamp'];
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        $this->assertNotFalse($parsed, "ESP32 timestamp '$timestamp' should be valid ISO 8601");
    }
}
