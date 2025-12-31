<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\Esp32CalibratedTemperatureService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;

/**
 * Unit tests for Esp32CalibratedTemperatureService.
 *
 * Tests the integration of ESP32 sensor readings with sensor configuration
 * (role assignment and calibration offsets).
 */
class Esp32CalibratedTemperatureServiceTest extends TestCase
{
    private string $temperatureFile;
    private string $configFile;
    private Esp32TemperatureService $temperatureService;
    private Esp32SensorConfigService $configService;
    private Esp32CalibratedTemperatureService $service;

    protected function setUp(): void
    {
        $this->temperatureFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->configFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';
        $this->temperatureService = new Esp32TemperatureService($this->temperatureFile);
        $this->configService = new Esp32SensorConfigService($this->configFile);
        $this->service = new Esp32CalibratedTemperatureService(
            $this->temperatureService,
            $this->configService
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->temperatureFile)) {
            unlink($this->temperatureFile);
        }
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    // ==================== No Data Tests ====================

    /**
     * @test
     */
    public function getTemperaturesReturnsNullWhenNoDataExists(): void
    {
        $result = $this->service->getTemperatures();
        $this->assertNull($result);
    }

    // ==================== Basic Temperature Tests ====================

    /**
     * @test
     */
    public function getTemperaturesReturnsDataWhenAvailable(): void
    {
        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertNotNull($result);
        $this->assertArrayHasKey('sensors', $result);
        $this->assertArrayHasKey('device_id', $result);
        $this->assertArrayHasKey('timestamp', $result);
    }

    // ==================== Role Assignment Tests ====================

    /**
     * @test
     */
    public function getTemperaturesReturnsWaterTempWhenRoleAssigned(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Configure sensor role
        $this->configService->setSensorRole($waterAddress, 'water');

        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertArrayHasKey('water_temp_c', $result);
        $this->assertEquals(38.5, $result['water_temp_c']);
        $this->assertArrayHasKey('water_temp_f', $result);
        $this->assertEqualsWithDelta(101.3, $result['water_temp_f'], 0.1);
    }

    /**
     * @test
     */
    public function getTemperaturesReturnsAmbientTempWhenRoleAssigned(): void
    {
        $ambientAddress = '28:D5:AA:87:00:23:16:34';

        // Configure sensor role
        $this->configService->setSensorRole($ambientAddress, 'ambient');

        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $ambientAddress, 'temp_c' => 22.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertArrayHasKey('ambient_temp_c', $result);
        $this->assertEquals(22.0, $result['ambient_temp_c']);
        $this->assertArrayHasKey('ambient_temp_f', $result);
        $this->assertEqualsWithDelta(71.6, $result['ambient_temp_f'], 0.1);
    }

    /**
     * @test
     */
    public function getTemperaturesReturnsBothTempsWhenBothRolesAssigned(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $ambientAddress = '28:D5:AA:87:00:23:16:34';

        // Configure sensor roles
        $this->configService->setSensorRole($waterAddress, 'water');
        $this->configService->setSensorRole($ambientAddress, 'ambient');

        // Store temperature data with both sensors
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.5],
                ['address' => $ambientAddress, 'temp_c' => 22.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertEquals(38.5, $result['water_temp_c']);
        $this->assertEquals(22.0, $result['ambient_temp_c']);
    }

    /**
     * @test
     */
    public function getTemperaturesReturnsNullForUnassignedRoles(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Only assign water role
        $this->configService->setSensorRole($waterAddress, 'water');

        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertEquals(38.5, $result['water_temp_c']);
        $this->assertNull($result['ambient_temp_c']);
        $this->assertNull($result['ambient_temp_f']);
    }

    // ==================== Calibration Tests ====================

    /**
     * @test
     */
    public function getTemperaturesAppliesPositiveCalibrationOffset(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Configure sensor with calibration offset
        $this->configService->setSensorRole($waterAddress, 'water');
        $this->configService->setCalibrationOffset($waterAddress, 0.5);

        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        // Raw temp is 38.0, with +0.5 offset should be 38.5
        $this->assertEquals(38.5, $result['water_temp_c']);
    }

    /**
     * @test
     */
    public function getTemperaturesAppliesNegativeCalibrationOffset(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Configure sensor with negative calibration offset
        $this->configService->setSensorRole($waterAddress, 'water');
        $this->configService->setCalibrationOffset($waterAddress, -1.0);

        // Store temperature data
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 40.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        // Raw temp is 40.0, with -1.0 offset should be 39.0
        $this->assertEquals(39.0, $result['water_temp_c']);
    }

    /**
     * @test
     */
    public function getTemperaturesCalculatesFahrenheitFromCalibratedCelsius(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Configure sensor with calibration offset
        $this->configService->setSensorRole($waterAddress, 'water');
        $this->configService->setCalibrationOffset($waterAddress, 0.0);

        // Store temperature data (20.0 C = 68.0 F exactly)
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 20.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertEquals(20.0, $result['water_temp_c']);
        $this->assertEqualsWithDelta(68.0, $result['water_temp_f'], 0.01);
    }

    // ==================== Raw Sensors Data Tests ====================

    /**
     * @test
     */
    public function getTemperaturesIncludesRawSensorsArray(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertArrayHasKey('sensors', $result);
        $this->assertCount(1, $result['sensors']);
        $this->assertEquals($waterAddress, $result['sensors'][0]['address']);
    }

    /**
     * @test
     */
    public function getTemperaturesIncludesSensorConfigInRawData(): void
    {
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';

        // Configure sensor
        $this->configService->setSensorRole($waterAddress, 'water');
        $this->configService->setCalibrationOffset($waterAddress, 0.5);
        $this->configService->setSensorName($waterAddress, 'Hot Tub Water');

        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $result = $this->service->getTemperatures();

        // Check that sensor includes config info
        $sensor = $result['sensors'][0];
        $this->assertArrayHasKey('role', $sensor);
        $this->assertEquals('water', $sensor['role']);
        $this->assertArrayHasKey('calibration_offset', $sensor);
        $this->assertEquals(0.5, $sensor['calibration_offset']);
        $this->assertArrayHasKey('name', $sensor);
        $this->assertEquals('Hot Tub Water', $sensor['name']);
        // Calibrated temp
        $this->assertArrayHasKey('calibrated_temp_c', $sensor);
        $this->assertEquals(38.5, $sensor['calibrated_temp_c']);
    }

    // ==================== Metadata Tests ====================

    /**
     * @test
     */
    public function getTemperaturesIncludesDeviceMetadata(): void
    {
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 7200,
        ]);

        $result = $this->service->getTemperatures();

        $this->assertEquals('AA:BB:CC:DD:EE:FF', $result['device_id']);
        $this->assertEquals(7200, $result['uptime_seconds']);
    }

    // ==================== Data Freshness Tests ====================

    /**
     * @test
     */
    public function isDataFreshReturnsFalseWhenNoData(): void
    {
        $this->assertFalse($this->service->isDataFresh(300));
    }

    /**
     * @test
     */
    public function isDataFreshReturnsTrueForRecentData(): void
    {
        $this->temperatureService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 38.5],
            ],
            'uptime_seconds' => 3600,
        ]);

        // 5 minute freshness window
        $this->assertTrue($this->service->isDataFresh(300));
    }
}
