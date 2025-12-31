<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\Esp32SensorConfigService;

/**
 * Unit tests for Esp32SensorConfigService.
 */
class Esp32SensorConfigServiceTest extends TestCase
{
    private string $configFile;
    private Esp32SensorConfigService $service;

    protected function setUp(): void
    {
        $this->configFile = sys_get_temp_dir() . '/test_esp32_sensor_config_' . uniqid() . '.json';
        $this->service = new Esp32SensorConfigService($this->configFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    // ==================== Get/Set Role Tests ====================

    /**
     * @test
     */
    public function getSensorConfigReturnsNullForUnknownSensor(): void
    {
        $config = $this->service->getSensorConfig('28:AA:BB:CC:DD:EE:FF:00');
        $this->assertNull($config);
    }

    /**
     * @test
     */
    public function setSensorRoleStoresRole(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setSensorRole($address, 'water');

        $config = $this->service->getSensorConfig($address);
        $this->assertNotNull($config);
        $this->assertEquals('water', $config['role']);
    }

    /**
     * @test
     */
    public function setSensorRoleAcceptsValidRoles(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $this->service->setSensorRole($address, 'water');
        $this->assertEquals('water', $this->service->getSensorConfig($address)['role']);

        $this->service->setSensorRole($address, 'ambient');
        $this->assertEquals('ambient', $this->service->getSensorConfig($address)['role']);

        $this->service->setSensorRole($address, 'unassigned');
        $this->assertEquals('unassigned', $this->service->getSensorConfig($address)['role']);
    }

    /**
     * @test
     */
    public function setSensorRoleRejectsInvalidRole(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->setSensorRole('28:AA:BB:CC:DD:EE:FF:00', 'invalid_role');
    }

    // ==================== Calibration Tests ====================

    /**
     * @test
     */
    public function setCalibrationOffsetStoresOffset(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setCalibrationOffset($address, 0.5);

        $config = $this->service->getSensorConfig($address);
        $this->assertEquals(0.5, $config['calibration_offset']);
    }

    /**
     * @test
     */
    public function setCalibrationOffsetAcceptsNegativeValues(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setCalibrationOffset($address, -1.5);

        $config = $this->service->getSensorConfig($address);
        $this->assertEquals(-1.5, $config['calibration_offset']);
    }

    /**
     * @test
     */
    public function getCalibratedTemperatureAppliesOffset(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setCalibrationOffset($address, 0.5);

        $calibrated = $this->service->getCalibratedTemperature($address, 20.0);
        $this->assertEquals(20.5, $calibrated);
    }

    /**
     * @test
     */
    public function getCalibratedTemperatureReturnsRawWhenNoOffset(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';

        $calibrated = $this->service->getCalibratedTemperature($address, 20.0);
        $this->assertEquals(20.0, $calibrated);
    }

    /**
     * @test
     */
    public function getCalibratedTemperatureAppliesNegativeOffset(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setCalibrationOffset($address, -0.3);

        $calibrated = $this->service->getCalibratedTemperature($address, 20.0);
        $this->assertEqualsWithDelta(19.7, $calibrated, 0.001);
    }

    // ==================== Sensor Name Tests ====================

    /**
     * @test
     */
    public function setSensorNameStoresName(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setSensorName($address, 'Hot Tub Water');

        $config = $this->service->getSensorConfig($address);
        $this->assertEquals('Hot Tub Water', $config['name']);
    }

    // ==================== Get All Sensors Tests ====================

    /**
     * @test
     */
    public function getAllSensorsReturnsEmptyArrayWhenNone(): void
    {
        $sensors = $this->service->getAllSensors();
        $this->assertIsArray($sensors);
        $this->assertEmpty($sensors);
    }

    /**
     * @test
     */
    public function getAllSensorsReturnsAllConfiguredSensors(): void
    {
        $this->service->setSensorRole('28:AA:AA:AA:AA:AA:AA:AA', 'water');
        $this->service->setSensorRole('28:BB:BB:BB:BB:BB:BB:BB', 'ambient');

        $sensors = $this->service->getAllSensors();
        $this->assertCount(2, $sensors);
        $this->assertArrayHasKey('28:AA:AA:AA:AA:AA:AA:AA', $sensors);
        $this->assertArrayHasKey('28:BB:BB:BB:BB:BB:BB:BB', $sensors);
    }

    // ==================== Get Sensor By Role Tests ====================

    /**
     * @test
     */
    public function getSensorByRoleReturnsCorrectSensor(): void
    {
        $waterAddress = '28:AA:AA:AA:AA:AA:AA:AA';
        $ambientAddress = '28:BB:BB:BB:BB:BB:BB:BB';

        $this->service->setSensorRole($waterAddress, 'water');
        $this->service->setSensorRole($ambientAddress, 'ambient');

        $water = $this->service->getSensorByRole('water');
        $this->assertEquals($waterAddress, $water);

        $ambient = $this->service->getSensorByRole('ambient');
        $this->assertEquals($ambientAddress, $ambient);
    }

    /**
     * @test
     */
    public function getSensorByRoleReturnsNullWhenNoMatch(): void
    {
        $this->service->setSensorRole('28:AA:AA:AA:AA:AA:AA:AA', 'water');

        $ambient = $this->service->getSensorByRole('ambient');
        $this->assertNull($ambient);
    }

    // ==================== Persistence Tests ====================

    /**
     * @test
     */
    public function configPersistsAcrossInstances(): void
    {
        $address = '28:F6:DD:87:00:88:1E:E8';
        $this->service->setSensorRole($address, 'water');
        $this->service->setCalibrationOffset($address, 0.5);
        $this->service->setSensorName($address, 'Test Sensor');

        // Create new instance with same file
        $newService = new Esp32SensorConfigService($this->configFile);
        $config = $newService->getSensorConfig($address);

        $this->assertEquals('water', $config['role']);
        $this->assertEquals(0.5, $config['calibration_offset']);
        $this->assertEquals('Test Sensor', $config['name']);
    }
}
