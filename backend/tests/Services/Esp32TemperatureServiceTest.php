<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\EquipmentStatusService;

/**
 * Unit tests for Esp32TemperatureService.
 */
class Esp32TemperatureServiceTest extends TestCase
{
    private string $storageFile;
    private string $equipmentStatusFile;
    private EquipmentStatusService $equipmentStatus;

    protected function setUp(): void
    {
        $this->storageFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->equipmentStatusFile = sys_get_temp_dir() . '/test_equipment_status_' . uniqid() . '.json';
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
}
