<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\HeaterControlService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HeaterControlServiceTest extends TestCase
{
    private MockObject&IftttClientInterface $mockIfttt;
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private string $statusFile;
    private EquipmentStatusService $statusService;

    protected function setUp(): void
    {
        $this->mockIfttt = $this->createMock(IftttClientInterface::class);
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->statusFile = sys_get_temp_dir() . '/heater-control-test-' . uniqid() . '.json';
        $this->statusService = new EquipmentStatusService($this->statusFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->statusFile)) {
            unlink($this->statusFile);
        }
    }

    private function createService(): HeaterControlService
    {
        return new HeaterControlService($this->mockIfttt, $this->statusService);
    }

    private function createServiceWithWatchdogCleanup(?string $jobsDir = null): HeaterControlService
    {
        return new HeaterControlService(
            $this->mockIfttt,
            $this->statusService,
            $this->mockCrontab,
            $jobsDir
        );
    }

    public function testHeaterOnTriggersIftttAndUpdatesStatus(): void
    {
        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-on')
            ->willReturn(true);

        $service = $this->createService();
        $result = $service->heaterOn();

        $this->assertTrue($result);
        $status = $this->statusService->getStatus();
        $this->assertTrue($status['heater']['on']);
    }

    public function testHeaterOnDoesNotUpdateStatusOnFailure(): void
    {
        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-on')
            ->willReturn(false);

        $service = $this->createService();
        $result = $service->heaterOn();

        $this->assertFalse($result);
        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on']);
    }

    public function testHeaterOffTriggersIftttAndUpdatesStatus(): void
    {
        // Start with heater on
        $this->statusService->setHeaterOn();
        $this->statusService->setPumpOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $service = $this->createService();
        $result = $service->heaterOff();

        $this->assertTrue($result);
        $status = $this->statusService->getStatus();
        $this->assertFalse($status['heater']['on']);
        $this->assertFalse($status['pump']['on'], 'Pump should also be turned off with heater');
    }

    public function testHeaterOffDoesNotUpdateStatusOnFailure(): void
    {
        // Start with heater on
        $this->statusService->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(false);

        $service = $this->createService();
        $result = $service->heaterOff();

        $this->assertFalse($result);
        $status = $this->statusService->getStatus();
        $this->assertTrue($status['heater']['on'], 'Heater should remain on after IFTTT failure');
    }

    public function testPumpRunTriggersIftttAndUpdatesStatus(): void
    {
        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('cycle_hot_tub_ionizer')
            ->willReturn(true);

        $service = $this->createService();
        $result = $service->pumpRun();

        $this->assertTrue($result);
        $status = $this->statusService->getStatus();
        $this->assertTrue($status['pump']['on']);
    }

    // ========== Watchdog cleanup tests ==========

    public function testHeaterOnCleansUpWatchdogCrons(): void
    {
        $this->mockIfttt->method('trigger')->willReturn(true);

        $this->mockCrontab->expects($this->once())
            ->method('removeByPattern')
            ->with('HOTTUB:watchdog');

        $service = $this->createServiceWithWatchdogCleanup();
        $service->heaterOn();
    }

    public function testHeaterOnCleansUpWatchdogJobFiles(): void
    {
        $this->mockIfttt->method('trigger')->willReturn(true);
        $this->mockCrontab->method('removeByPattern');

        // Create a temp jobs dir with watchdog files
        $jobsDir = sys_get_temp_dir() . '/watchdog-cleanup-test-' . uniqid();
        mkdir($jobsDir, 0755, true);

        $watchdogFile = $jobsDir . '/watchdog-abc12345.json';
        file_put_contents($watchdogFile, json_encode(['jobId' => 'watchdog-abc12345']));

        // Non-watchdog file should survive
        $otherFile = $jobsDir . '/heat-target-xyz.json';
        file_put_contents($otherFile, json_encode(['jobId' => 'heat-target-xyz']));

        $service = $this->createServiceWithWatchdogCleanup($jobsDir);
        $service->heaterOn();

        $this->assertFileDoesNotExist($watchdogFile, 'Watchdog job file should be cleaned on heaterOn()');
        $this->assertFileExists($otherFile, 'Non-watchdog job file should survive');

        // Cleanup
        unlink($otherFile);
        rmdir($jobsDir);
    }

    public function testHeaterOffDoesNotCleanUpWatchdogCrons(): void
    {
        $this->mockIfttt->method('trigger')->willReturn(true);

        // removeByPattern should NOT be called for watchdog on heaterOff
        $this->mockCrontab->expects($this->never())
            ->method('removeByPattern');

        $service = $this->createServiceWithWatchdogCleanup();
        $service->heaterOff();
    }

    public function testHeaterOnFailureStillCleansUpWatchdog(): void
    {
        // Even if IFTTT fails, watchdog should still be cleaned
        // (the heater-on intent was expressed, just failed to execute)
        $this->mockIfttt->method('trigger')->willReturn(false);

        $this->mockCrontab->expects($this->once())
            ->method('removeByPattern')
            ->with('HOTTUB:watchdog');

        $service = $this->createServiceWithWatchdogCleanup();
        $service->heaterOn();
    }
}
