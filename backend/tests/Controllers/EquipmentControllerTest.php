<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\EquipmentController;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\TargetTemperatureService;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Contracts\CrontabAdapterInterface;

/**
 * Unit tests for EquipmentController.
 *
 * Tests equipment actions and status tracking integration.
 */
class EquipmentControllerTest extends TestCase
{
    private string $logFile;
    private string $statusFile;
    private IftttClientInterface $mockIftttClient;
    private EquipmentStatusService $statusService;

    protected function setUp(): void
    {
        $this->logFile = sys_get_temp_dir() . '/test-events-' . uniqid() . '.log';
        $this->statusFile = sys_get_temp_dir() . '/test-equipment-status-' . uniqid() . '.json';

        $this->mockIftttClient = $this->createMock(IftttClientInterface::class);
        $this->statusService = new EquipmentStatusService($this->statusFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logFile)) {
            unlink($this->logFile);
        }
        if (file_exists($this->statusFile)) {
            unlink($this->statusFile);
        }
    }

    // ========== Health Endpoint Tests ==========

    public function testHealthReturnsEquipmentStatus(): void
    {
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $response = $controller->health();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('equipmentStatus', $response['body']);
        $this->assertArrayHasKey('heater', $response['body']['equipmentStatus']);
        $this->assertArrayHasKey('pump', $response['body']['equipmentStatus']);
    }

    public function testHealthReturnsCorrectHeaterStatus(): void
    {
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Set heater on
        $this->statusService->setHeaterOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $response = $controller->health();

        $this->assertTrue($response['body']['equipmentStatus']['heater']['on']);
    }

    public function testHealthReturnsCorrectPumpStatus(): void
    {
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Set pump on
        $this->statusService->setPumpOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $response = $controller->health();

        $this->assertTrue($response['body']['equipmentStatus']['pump']['on']);
    }

    // ========== Heater On Tests ==========

    public function testHeaterOnUpdatesStatusOnSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOn();

        $status = $this->statusService->getStatus();
        $this->assertTrue($status['heater']['on']);
    }

    public function testHeaterOnDoesNotUpdateStatusOnFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOn();

        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on']);
    }

    // ========== Heater Off Tests ==========

    public function testHeaterOffUpdatesStatusOnSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // First turn heater on
        $this->statusService->setHeaterOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOff();

        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on']);
    }

    public function testHeaterOffDoesNotUpdateStatusOnFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // First turn heater on
        $this->statusService->setHeaterOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOff();

        $status = $this->statusService->getStatus();
        $this->assertTrue($status['heater']['on']); // Should still be on
    }

    /**
     * Business rule: When heater is turned off, the pump is also turned off.
     * This reflects hardware behavior where the heat-off command stops both.
     */
    public function testHeaterOffAlsoTurnsPumpOffOnSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Set both heater and pump on
        $this->statusService->setHeaterOn();
        $this->statusService->setPumpOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOff();

        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on'], 'Heater should be off');
        $this->assertFalse($status['pump']['on'], 'Pump should also be off when heater is turned off');
    }

    public function testHeaterOffDoesNotTurnPumpOffOnFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Set both heater and pump on
        $this->statusService->setHeaterOn();
        $this->statusService->setPumpOn();

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->heaterOff();

        $status = $this->statusService->getStatus();
        $this->assertTrue($status['heater']['on'], 'Heater should still be on after failed command');
        $this->assertTrue($status['pump']['on'], 'Pump should still be on after failed command');
    }

    // ========== Heat-to-Target Cancellation Tests ==========

    /**
     * CRITICAL UX: Manual heater off MUST cancel heat-to-target.
     *
     * Without this, the user turns off heater and it turns back on 60 seconds
     * later because heat-to-target cron is still running. This is confusing
     * and violates the principle that manual actions override automation.
     */
    public function testHeaterOffCancelsHeatToTargetWhenActive(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Set up heat-to-target state file
        $tempDir = sys_get_temp_dir() . '/heat-target-cancel-test-' . uniqid();
        $stateDir = $tempDir . '/state';
        mkdir($stateDir, 0755, true);
        $targetTempStateFile = $stateDir . '/target-temperature.json';

        // Create TargetTemperatureService with active state
        $targetTempService = new TargetTemperatureService($targetTempStateFile);
        $targetTempService->start(102.0);

        // Verify heat-to-target is active
        $this->assertTrue($targetTempService->getState()['active'], 'Heat-to-target should be active');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService,
            $targetTempService
        );

        $controller->heaterOff();

        // Heat-to-target should now be inactive
        $state = $targetTempService->getState();
        $this->assertFalse($state['active'], 'Heat-to-target must be canceled when heater is turned off manually');

        // Cleanup
        if (file_exists($targetTempStateFile)) {
            unlink($targetTempStateFile);
        }
        rmdir($stateDir);
        rmdir($tempDir);
    }

    /**
     * Verify heaterOff cleans up heat-to-target cron entries.
     */
    public function testHeaterOffRemovesHeatToTargetCronEntries(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        // Create mock crontab adapter that expects removeByPattern to be called
        $mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $mockCrontab->expects($this->atLeastOnce())
            ->method('removeByPattern')
            ->with($this->stringContains('heat-target'));

        // Set up target temp service with crontab
        $tempDir = sys_get_temp_dir() . '/heat-target-cron-test-' . uniqid();
        $stateDir = $tempDir . '/state';
        mkdir($stateDir, 0755, true);
        $targetTempStateFile = $stateDir . '/target-temperature.json';

        $targetTempService = new TargetTemperatureService(
            $targetTempStateFile,
            null, // ifttt
            null, // equipmentStatus
            null, // esp32Temp
            $mockCrontab
        );
        $targetTempService->start(102.0);

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService,
            $targetTempService
        );

        $controller->heaterOff();

        // Cleanup
        if (file_exists($targetTempStateFile)) {
            unlink($targetTempStateFile);
        }
        rmdir($stateDir);
        rmdir($tempDir);
    }

    /**
     * Verify heaterOff works without TargetTemperatureService (backwards compatible).
     */
    public function testHeaterOffWorksWithoutTargetTempService(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $this->statusService->setHeaterOn();

        // Create controller WITHOUT TargetTemperatureService
        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
            // No targetTempService - should still work
        );

        $controller->heaterOff();

        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on'], 'Heater should be off even without TargetTemperatureService');
    }

    // ========== Pump Run Tests ==========

    public function testPumpRunUpdatesStatusOnSuccess(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(true);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->pumpRun();

        $status = $this->statusService->getStatus();
        $this->assertTrue($status['pump']['on']);
    }

    public function testPumpRunDoesNotUpdateStatusOnFailure(): void
    {
        $this->mockIftttClient->method('trigger')->willReturn(false);
        $this->mockIftttClient->method('getMode')->willReturn('stub');

        $controller = new EquipmentController(
            $this->logFile,
            $this->mockIftttClient,
            $this->statusService
        );

        $controller->pumpRun();

        $status = $this->statusService->getStatus();
        $this->assertFalse($status['pump']['on']);
    }
}
