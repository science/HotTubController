<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the EquipmentStatusService.
 *
 * This service tracks equipment on/off state and persists it to a JSON file.
 * It includes auto-off logic for the pump after 2 hours.
 */
class EquipmentStatusServiceTest extends TestCase
{
    private string $testStorageDir;
    private string $testStatusFile;

    protected function setUp(): void
    {
        // Use a temporary directory for tests
        $this->testStorageDir = sys_get_temp_dir() . '/hot-tub-test-' . uniqid();
        mkdir($this->testStorageDir, 0755, true);
        $this->testStatusFile = $this->testStorageDir . '/equipment-status.json';
    }

    protected function tearDown(): void
    {
        // Clean up test files
        if (file_exists($this->testStatusFile)) {
            unlink($this->testStatusFile);
        }
        if (is_dir($this->testStorageDir)) {
            rmdir($this->testStorageDir);
        }
    }

    // ========== Initial State Tests ==========

    public function testGetStatusReturnsDefaultWhenNoFileExists(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $status = $service->getStatus();

        $this->assertArrayHasKey('heater', $status);
        $this->assertArrayHasKey('pump', $status);
        $this->assertFalse($status['heater']['on']);
        $this->assertFalse($status['pump']['on']);
        $this->assertNull($status['heater']['lastChangedAt']);
        $this->assertNull($status['pump']['lastChangedAt']);
    }

    public function testGetStatusCreatesFileIfNotExists(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $service->getStatus();

        $this->assertFileExists($this->testStatusFile);
    }

    // ========== Heater State Tests ==========

    public function testSetHeaterOnUpdatesState(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $service->setHeaterOn();
        $status = $service->getStatus();

        $this->assertTrue($status['heater']['on']);
        $this->assertNotNull($status['heater']['lastChangedAt']);
    }

    public function testSetHeaterOffUpdatesState(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // First turn on, then off
        $service->setHeaterOn();
        $service->setHeaterOff();
        $status = $service->getStatus();

        $this->assertFalse($status['heater']['on']);
        $this->assertNotNull($status['heater']['lastChangedAt']);
    }

    public function testSetHeaterOnRecordsTimestamp(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $before = (new \DateTime())->getTimestamp();
        $service->setHeaterOn();
        $after = (new \DateTime())->getTimestamp();

        $status = $service->getStatus();
        $timestamp = (new \DateTime($status['heater']['lastChangedAt']))->getTimestamp();

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    public function testHeaterStatePersistsAcrossInstances(): void
    {
        $service1 = new EquipmentStatusService($this->testStatusFile);
        $service1->setHeaterOn();

        // Create new instance reading from same file
        $service2 = new EquipmentStatusService($this->testStatusFile);
        $status = $service2->getStatus();

        $this->assertTrue($status['heater']['on']);
    }

    // ========== Pump State Tests ==========

    public function testSetPumpOnUpdatesState(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $service->setPumpOn();
        $status = $service->getStatus();

        $this->assertTrue($status['pump']['on']);
        $this->assertNotNull($status['pump']['lastChangedAt']);
    }

    public function testSetPumpOnRecordsTimestamp(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $before = (new \DateTime())->getTimestamp();
        $service->setPumpOn();
        $after = (new \DateTime())->getTimestamp();

        $status = $service->getStatus();
        $timestamp = (new \DateTime($status['pump']['lastChangedAt']))->getTimestamp();

        $this->assertGreaterThanOrEqual($before, $timestamp);
        $this->assertLessThanOrEqual($after, $timestamp);
    }

    // ========== Pump Auto-Off Tests ==========

    public function testPumpAutoOffTriggersAfterTwoHours(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // Set pump on with timestamp from 3 hours ago
        $threeHoursAgo = (new \DateTime())->modify('-3 hours');
        $this->writeStatusFile([
            'heater' => ['on' => false, 'lastChangedAt' => null],
            'pump' => ['on' => true, 'lastChangedAt' => $threeHoursAgo->format('c')],
        ]);

        $status = $service->getStatus();

        // Pump should be auto-set to off
        $this->assertFalse($status['pump']['on']);
    }

    public function testPumpAutoOffUpdatesTimestampToOriginalPlusTwoHours(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // Set pump on with timestamp from 3 hours ago
        $threeHoursAgo = (new \DateTime())->modify('-3 hours');
        $expectedOffTime = (clone $threeHoursAgo)->modify('+2 hours');

        $this->writeStatusFile([
            'heater' => ['on' => false, 'lastChangedAt' => null],
            'pump' => ['on' => true, 'lastChangedAt' => $threeHoursAgo->format('c')],
        ]);

        $status = $service->getStatus();
        $actualOffTime = new \DateTime($status['pump']['lastChangedAt']);

        // Timestamp should be original + 2 hours (within 1 second tolerance)
        $diff = abs($expectedOffTime->getTimestamp() - $actualOffTime->getTimestamp());
        $this->assertLessThan(2, $diff, 'lastChangedAt should be original timestamp + 2 hours');
    }

    public function testPumpDoesNotAutoOffWithinTwoHours(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // Set pump on with timestamp from 1 hour ago
        $oneHourAgo = (new \DateTime())->modify('-1 hour');
        $this->writeStatusFile([
            'heater' => ['on' => false, 'lastChangedAt' => null],
            'pump' => ['on' => true, 'lastChangedAt' => $oneHourAgo->format('c')],
        ]);

        $status = $service->getStatus();

        // Pump should still be on
        $this->assertTrue($status['pump']['on']);
    }

    public function testPumpAutoOffOnlyHappensWhenPumpIsOn(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // Pump is already off, but has an old timestamp
        $threeHoursAgo = (new \DateTime())->modify('-3 hours');
        $this->writeStatusFile([
            'heater' => ['on' => false, 'lastChangedAt' => null],
            'pump' => ['on' => false, 'lastChangedAt' => $threeHoursAgo->format('c')],
        ]);

        $status = $service->getStatus();

        // Should still be off, timestamp unchanged
        $this->assertFalse($status['pump']['on']);
        $actualTime = new \DateTime($status['pump']['lastChangedAt']);
        $diff = abs($threeHoursAgo->getTimestamp() - $actualTime->getTimestamp());
        $this->assertLessThan(2, $diff, 'Timestamp should not change when pump is already off');
    }

    public function testPumpAutoOffPersistsToFile(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        // Set pump on with timestamp from 3 hours ago
        $threeHoursAgo = (new \DateTime())->modify('-3 hours');
        $this->writeStatusFile([
            'heater' => ['on' => false, 'lastChangedAt' => null],
            'pump' => ['on' => true, 'lastChangedAt' => $threeHoursAgo->format('c')],
        ]);

        // Trigger auto-off by reading status
        $service->getStatus();

        // Create new instance and verify persistence
        $service2 = new EquipmentStatusService($this->testStatusFile);
        $status = $service2->getStatus();

        $this->assertFalse($status['pump']['on']);
    }

    // ========== Independence Tests ==========

    public function testHeaterAndPumpStatesAreIndependent(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);

        $service->setHeaterOn();
        $service->setPumpOn();

        $status = $service->getStatus();

        $this->assertTrue($status['heater']['on']);
        $this->assertTrue($status['pump']['on']);

        $service->setHeaterOff();
        $status = $service->getStatus();

        $this->assertFalse($status['heater']['on']);
        $this->assertTrue($status['pump']['on']); // Pump still on
    }

    // ========== Equipment Event Logging Tests ==========

    /**
     * @test
     * setHeaterOn should append an event log entry with current water temperature.
     */
    public function setHeaterOnAppendsEventLog(): void
    {
        $eventLogFile = $this->testStorageDir . '/equipment-events.log';
        $tempFile = $this->testStorageDir . '/esp32-temperature.json';

        // Write a temperature reading
        file_put_contents($tempFile, json_encode([
            'device_id' => 'TEST',
            'sensors' => [
                ['address' => '28:AA', 'temp_c' => 38.5, 'temp_f' => 101.3],
            ],
            'timestamp' => date('c'),
            'received_at' => time(),
            'temp_f' => 101.3,
        ]));
        $tempService = new Esp32TemperatureService($tempFile);

        $service = new EquipmentStatusService($this->testStatusFile, $eventLogFile, $tempService);
        $service->setHeaterOn();

        $this->assertFileExists($eventLogFile);
        $lines = array_filter(explode("\n", file_get_contents($eventLogFile)));
        $this->assertCount(1, $lines);

        $entry = json_decode($lines[0], true);
        $this->assertEquals('heater', $entry['equipment']);
        $this->assertEquals('on', $entry['action']);
        $this->assertEqualsWithDelta(101.3, $entry['water_temp_f'], 0.1);
        $this->assertArrayHasKey('timestamp', $entry);
    }

    /**
     * @test
     * setHeaterOff should append an event log entry.
     */
    public function setHeaterOffAppendsEventLog(): void
    {
        $eventLogFile = $this->testStorageDir . '/equipment-events.log';
        $tempFile = $this->testStorageDir . '/esp32-temperature.json';

        file_put_contents($tempFile, json_encode([
            'device_id' => 'TEST',
            'sensors' => [
                ['address' => '28:AA', 'temp_c' => 40.0, 'temp_f' => 104.0],
            ],
            'timestamp' => date('c'),
            'received_at' => time(),
            'temp_f' => 104.0,
        ]));
        $tempService = new Esp32TemperatureService($tempFile);

        $service = new EquipmentStatusService($this->testStatusFile, $eventLogFile, $tempService);
        $service->setHeaterOn();
        $service->setHeaterOff();

        $lines = array_filter(explode("\n", file_get_contents($eventLogFile)));
        $this->assertCount(2, $lines);

        $entry = json_decode($lines[1], true);
        $this->assertEquals('heater', $entry['equipment']);
        $this->assertEquals('off', $entry['action']);
        $this->assertEqualsWithDelta(104.0, $entry['water_temp_f'], 0.1);
    }

    /**
     * @test
     * Event logging should work with null water temp when no temperature data available.
     */
    public function eventLogWorksWithNoTemperatureData(): void
    {
        $eventLogFile = $this->testStorageDir . '/equipment-events.log';
        $tempFile = $this->testStorageDir . '/esp32-temperature-missing.json';
        $tempService = new Esp32TemperatureService($tempFile);

        $service = new EquipmentStatusService($this->testStatusFile, $eventLogFile, $tempService);
        $service->setHeaterOn();

        $lines = array_filter(explode("\n", file_get_contents($eventLogFile)));
        $entry = json_decode($lines[0], true);
        $this->assertNull($entry['water_temp_f']);
    }

    /**
     * @test
     * Equipment status service works without event logging (backward compat).
     */
    public function worksWithoutEventLogging(): void
    {
        $service = new EquipmentStatusService($this->testStatusFile);
        $service->setHeaterOn();
        $status = $service->getStatus();
        $this->assertTrue($status['heater']['on']);
    }

    // ========== Helper Methods ==========

    private function writeStatusFile(array $data): void
    {
        file_put_contents($this->testStatusFile, json_encode($data, JSON_PRETTY_PRINT));
    }
}
