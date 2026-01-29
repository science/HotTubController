<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32SensorConfigService;

/**
 * Unit tests for Esp32TemperatureService.
 */
class Esp32TemperatureServiceTest extends TestCase
{
    private string $storageFile;
    private string $equipmentStatusFile;
    private string $sensorConfigFile;
    private string $temperatureLogDir;
    private EquipmentStatusService $equipmentStatus;

    protected function setUp(): void
    {
        $this->storageFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->equipmentStatusFile = sys_get_temp_dir() . '/test_equipment_status_' . uniqid() . '.json';
        $this->sensorConfigFile = sys_get_temp_dir() . '/test_sensor_config_' . uniqid() . '.json';
        $this->temperatureLogDir = sys_get_temp_dir() . '/test_temp_logs_' . uniqid();
        mkdir($this->temperatureLogDir, 0755, true);
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storageFile)) {
            unlink($this->storageFile);
        }
        if (file_exists($this->equipmentStatusFile)) {
            unlink($this->equipmentStatusFile);
        }
        if (file_exists($this->sensorConfigFile)) {
            unlink($this->sensorConfigFile);
        }
        // Clean up temperature log files
        if (is_dir($this->temperatureLogDir)) {
            foreach (glob($this->temperatureLogDir . '/*') as $file) {
                unlink($file);
            }
            rmdir($this->temperatureLogDir);
        }
    }

    // ==================== Dynamic Interval Tests ====================

    /**
     * @test
     * When heater is ON, ESP32 should poll every 60 seconds for faster temperature updates.
     */
    public function getIntervalReturns60WhenHeaterIsOn(): void
    {
        $this->equipmentStatus->setHeaterOn();

        $service = new Esp32TemperatureService($this->storageFile, $this->equipmentStatus);

        $this->assertEquals(60, $service->getInterval());
    }

    /**
     * @test
     * When heater is OFF, ESP32 should poll at the default 5-minute interval.
     */
    public function getIntervalReturns300WhenHeaterIsOff(): void
    {
        // Heater starts off by default, but let's be explicit
        $this->equipmentStatus->setHeaterOff();

        $service = new Esp32TemperatureService($this->storageFile, $this->equipmentStatus);

        $this->assertEquals(300, $service->getInterval());
    }

    /**
     * @test
     * When no EquipmentStatusService is provided, fallback to default interval.
     * This ensures backward compatibility.
     */
    public function getIntervalReturnsDefaultWhenNoEquipmentStatusService(): void
    {
        $service = new Esp32TemperatureService($this->storageFile);

        $this->assertEquals(300, $service->getInterval());
    }

    // ==================== Temperature History Logging Tests ====================

    private function createSensorConfig(array $sensors): void
    {
        $config = ['sensors' => $sensors, 'updated_at' => date('c')];
        file_put_contents($this->sensorConfigFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    private function createServiceWithLogging(): Esp32TemperatureService
    {
        $sensorConfigService = new Esp32SensorConfigService($this->sensorConfigFile);
        return new Esp32TemperatureService(
            $this->storageFile,
            $this->equipmentStatus,
            $sensorConfigService,
            $this->temperatureLogDir
        );
    }

    private function getSampleSensorData(): array
    {
        return [
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => 38.5],
                ['address' => '28:11:22:33:44:55:66:77', 'temp_c' => 21.0],
            ],
            'uptime_seconds' => 3600,
        ];
    }

    /**
     * @test
     * Storing temperature data should append a JSONL line to a daily log file.
     */
    public function storeAppendsToTemperatureLog(): void
    {
        $this->createSensorConfig([
            '28:AA:BB:CC:DD:EE:FF:00' => ['role' => 'water', 'calibration_offset' => 0, 'name' => 'Water'],
            '28:11:22:33:44:55:66:77' => ['role' => 'ambient', 'calibration_offset' => 0, 'name' => 'Ambient'],
        ]);

        $service = $this->createServiceWithLogging();
        $service->store($this->getSampleSensorData());

        $logFile = $this->temperatureLogDir . '/temperature-' . date('Y-m-d') . '.log';
        $this->assertFileExists($logFile);

        $lines = array_filter(explode("\n", file_get_contents($logFile)));
        $this->assertCount(1, $lines);

        $entry = json_decode($lines[0], true);
        $this->assertNotNull($entry);
        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertEqualsWithDelta(101.3, $entry['water_temp_f'], 0.1);
        $this->assertEqualsWithDelta(38.5, $entry['water_temp_c'], 0.1);
        $this->assertEqualsWithDelta(69.8, $entry['ambient_temp_f'], 0.1);
        $this->assertEqualsWithDelta(21.0, $entry['ambient_temp_c'], 0.1);
        $this->assertFalse($entry['heater_on']);
    }

    /**
     * @test
     * Temperature log should record heater state as true when heater is on.
     */
    public function storeLogsHeaterOnState(): void
    {
        $this->createSensorConfig([
            '28:AA:BB:CC:DD:EE:FF:00' => ['role' => 'water', 'calibration_offset' => 0, 'name' => 'Water'],
            '28:11:22:33:44:55:66:77' => ['role' => 'ambient', 'calibration_offset' => 0, 'name' => 'Ambient'],
        ]);

        $this->equipmentStatus->setHeaterOn();
        $service = $this->createServiceWithLogging();
        $service->store($this->getSampleSensorData());

        $logFile = $this->temperatureLogDir . '/temperature-' . date('Y-m-d') . '.log';
        $lines = array_filter(explode("\n", file_get_contents($logFile)));
        $entry = json_decode($lines[0], true);

        $this->assertTrue($entry['heater_on']);
    }

    /**
     * @test
     * Multiple store calls should append multiple lines to the same daily log file.
     */
    public function storeAppendsMultipleEntries(): void
    {
        $this->createSensorConfig([
            '28:AA:BB:CC:DD:EE:FF:00' => ['role' => 'water', 'calibration_offset' => 0, 'name' => 'Water'],
            '28:11:22:33:44:55:66:77' => ['role' => 'ambient', 'calibration_offset' => 0, 'name' => 'Ambient'],
        ]);

        $service = $this->createServiceWithLogging();
        $service->store($this->getSampleSensorData());
        $service->store($this->getSampleSensorData());

        $logFile = $this->temperatureLogDir . '/temperature-' . date('Y-m-d') . '.log';
        $lines = array_filter(explode("\n", file_get_contents($logFile)));
        $this->assertCount(2, $lines);
    }

    /**
     * @test
     * When no sensor config or log dir is provided, store still works (backward compat).
     */
    public function storeWorksWithoutLogging(): void
    {
        $service = new Esp32TemperatureService($this->storageFile, $this->equipmentStatus);
        $service->store($this->getSampleSensorData());

        $latest = $service->getLatest();
        $this->assertNotNull($latest);
        $this->assertEquals('TEST:AA:BB:CC:DD:EE', $latest['device_id']);
    }

    /**
     * @test
     * When sensor roles are not configured, log entry should have null temperatures.
     */
    public function storeLogsNullTempsWhenNoSensorRolesConfigured(): void
    {
        $this->createSensorConfig([]);

        $service = $this->createServiceWithLogging();
        $service->store($this->getSampleSensorData());

        $logFile = $this->temperatureLogDir . '/temperature-' . date('Y-m-d') . '.log';
        $lines = array_filter(explode("\n", file_get_contents($logFile)));
        $entry = json_decode($lines[0], true);

        $this->assertNull($entry['water_temp_f']);
        $this->assertNull($entry['water_temp_c']);
        $this->assertNull($entry['ambient_temp_f']);
        $this->assertNull($entry['ambient_temp_c']);
    }
}
