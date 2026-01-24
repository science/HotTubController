<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\TargetTemperatureService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class TargetTemperatureServiceTest extends TestCase
{
    private string $stateFile;
    private string $equipmentStatusFile;
    private string $esp32TempFile;
    private string $esp32ConfigFile;
    private MockObject&IftttClientInterface $mockIfttt;
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private EquipmentStatusService $equipmentStatus;
    private Esp32TemperatureService $esp32Temp;
    private Esp32SensorConfigService $esp32Config;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/target-temp-test-' . uniqid() . '.json';
        $this->equipmentStatusFile = sys_get_temp_dir() . '/equip-status-test-' . uniqid() . '.json';
        $this->esp32TempFile = sys_get_temp_dir() . '/esp32-temp-test-' . uniqid() . '.json';
        $this->esp32ConfigFile = sys_get_temp_dir() . '/esp32-config-test-' . uniqid() . '.json';

        $this->mockIfttt = $this->createMock(IftttClientInterface::class);
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
        $this->esp32Temp = new Esp32TemperatureService($this->esp32TempFile, $this->equipmentStatus);
        $this->esp32Config = new Esp32SensorConfigService($this->esp32ConfigFile);
    }

    protected function tearDown(): void
    {
        foreach ([$this->stateFile, $this->equipmentStatusFile, $this->esp32TempFile, $this->esp32ConfigFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
    }

    private function createService(): TargetTemperatureService
    {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp
        );
    }

    private function createServiceWithCron(): TargetTemperatureService
    {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );
    }

    private function createBasicService(): TargetTemperatureService
    {
        // For tests that don't need full dependencies
        return new TargetTemperatureService($this->stateFile);
    }

    private function storeEsp32Reading(float $tempF): void
    {
        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => ($tempF - 32) * 5 / 9, 'temp_f' => $tempF],
            ],
            'uptime_seconds' => 3600,
        ]);
    }

    public function testGetStateReturnsInactiveWhenNoFileExists(): void
    {
        $service = $this->createBasicService();

        $state = $service->getState();

        $this->assertFalse($state['active']);
        $this->assertNull($state['target_temp_f']);
    }

    public function testStartCreatesStateFileWithTargetTemp(): void
    {
        $service = $this->createBasicService();

        $service->start(103.5);

        $state = $service->getState();
        $this->assertTrue($state['active']);
        $this->assertEquals(103.5, $state['target_temp_f']);
        $this->assertArrayHasKey('started_at', $state);
    }

    public function testStartRejectsTemperatureBelow80F(): void
    {
        $service = $this->createBasicService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target temperature must be between 80 and 110°F');

        $service->start(79.0);
    }

    public function testStartRejectsTemperatureAbove110F(): void
    {
        $service = $this->createBasicService();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target temperature must be between 80 and 110°F');

        $service->start(111.0);
    }

    public function testStopClearsState(): void
    {
        $service = $this->createBasicService();
        $service->start(103.5);

        $service->stop();

        $state = $service->getState();
        $this->assertFalse($state['active']);
    }

    // ========== checkAndAdjust tests ==========

    public function testCheckAndAdjustTurnsHeaterOnWhenCurrentBelowTargetAndHeaterOff(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOff();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-on')
            ->willReturn(true);

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['heater_turned_on']);
        $this->assertTrue($this->equipmentStatus->getStatus()['heater']['on']);
    }

    public function testCheckAndAdjustDoesNotTriggerIftttWhenHeaterAlreadyOn(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->never())
            ->method('trigger');

        $result = $service->checkAndAdjust();

        $this->assertFalse($result['heater_turned_on']);
        $this->assertTrue($result['heating']);
    }

    public function testCheckAndAdjustDoesNothingWhenNotActive(): void
    {
        $service = $this->createService();
        // Don't call start() - not active
        $this->storeEsp32Reading(82.0);

        $this->mockIfttt->expects($this->never())
            ->method('trigger');

        $result = $service->checkAndAdjust();

        $this->assertFalse($result['active']);
    }

    // ========== Target reached tests ==========

    public function testCheckAndAdjustTurnsHeaterOffWhenTargetReached(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(103.5); // Exactly at target
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['heater_turned_off']);
        $this->assertTrue($result['target_reached']);
        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);
    }

    public function testCheckAndAdjustTurnsHeaterOffWhenTargetExceeded(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(104.0); // Above target
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['heater_turned_off']);
        $this->assertTrue($result['target_reached']);
    }

    public function testCheckAndAdjustClearsStateWhenTargetReached(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(103.5);
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->method('trigger')->willReturn(true);

        $service->checkAndAdjust();

        $state = $service->getState();
        $this->assertFalse($state['active']);
    }

    public function testCheckAndAdjustDoesNotTriggerIftttWhenHeaterAlreadyOffAtTarget(): void
    {
        $service = $this->createService();
        $service->start(103.5);
        $this->storeEsp32Reading(103.5);
        $this->equipmentStatus->setHeaterOff();

        $this->mockIfttt->expects($this->never())
            ->method('trigger');

        $result = $service->checkAndAdjust();

        $this->assertFalse($result['heater_turned_off']);
        $this->assertTrue($result['target_reached']);
    }

    // ========== Cron scheduling tests ==========

    public function testCheckAndAdjustSchedulesNextCheckWhenHeating(): void
    {
        $service = $this->createServiceWithCron();
        $service->start(103.5);
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->stringContains('HOTTUB:heat-target'));

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['heating']);
        $this->assertTrue($result['cron_scheduled']);
    }

    public function testCheckAndAdjustCleansUpCronsWhenTargetReached(): void
    {
        $service = $this->createServiceWithCron();
        $service->start(103.5);
        $this->storeEsp32Reading(103.5);
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->method('trigger')->willReturn(true);

        $this->mockCrontab->expects($this->once())
            ->method('removeByPattern')
            ->with('HOTTUB:heat-target');

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['target_reached']);
    }

    public function testCalculateNextCheckTimeReturns5SecondsAfterNextEsp32Report(): void
    {
        $service = $this->createServiceWithCron();

        // Store a reading - received_at is set to current time by store()
        $this->storeEsp32Reading(82.0);

        // The ESP32 interval is 60 seconds when heater is on
        $this->equipmentStatus->setHeaterOn();

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();

        // Should be approximately: now + 60 (next report) + 5 (buffer) = now + 65
        // With some tolerance for execution time
        $expectedMin = $now + 60; // At least 60 seconds from now
        $expectedMax = $now + 70; // At most 70 seconds from now

        $this->assertGreaterThanOrEqual($expectedMin, $nextCheckTime);
        $this->assertLessThanOrEqual($expectedMax, $nextCheckTime);
    }

    public function testCleanupCronJobsRemovesAllHeatTargetEntries(): void
    {
        $service = $this->createServiceWithCron();

        $this->mockCrontab->expects($this->once())
            ->method('removeByPattern')
            ->with('HOTTUB:heat-target');

        $service->cleanupCronJobs();
    }

    /**
     * RACE CONDITION TEST: With old ESP32 reading, cron must still be in future minute.
     *
     * Bug scenario:
     * - ESP32 last reported 90 seconds ago
     * - calculateNextCheckTime() calculates: receivedAt + 60 + 5 = now - 25 (past!)
     * - Code adds interval: now - 25 + 60 = now + 35 (future, but possibly same minute)
     * - Cron daemon fires at :00, so if we're at :10 and schedule for :35, it's missed!
     *
     * Fix: Always ensure at least 60 seconds in future to guarantee next minute.
     */
    public function testCalculateNextCheckTimeWithOldReadingStillReturnsNextMinute(): void
    {
        $service = $this->createServiceWithCron();

        // Store a reading from 90 seconds ago
        $oldReceivedAt = time() - 90;
        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => 28.0, 'temp_f' => 82.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        // Manually set received_at to simulate old reading
        $stateFile = $this->esp32TempFile;
        $data = json_decode(file_get_contents($stateFile), true);
        $data['received_at'] = $oldReceivedAt;
        file_put_contents($stateFile, json_encode($data));

        // Heater is on, so interval is 60 seconds
        $this->equipmentStatus->setHeaterOn();

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();

        // MUST be at least 60 seconds in the future to guarantee next minute
        // This prevents the race condition where cron is scheduled for current minute
        $this->assertGreaterThanOrEqual(
            $now + 60,
            $nextCheckTime,
            "With old ESP32 reading, next check must be at least 60 seconds in future.\n" .
            "This ensures we're in the NEXT minute, not current minute.\n" .
            "Cron daemon fires at :00 - scheduling for current minute means it never fires!"
        );
    }

    // ========== Calibration tests ==========

    public function testCheckAndAdjustUsesCalibratedTemperatureNotRaw(): void
    {
        // Set up a calibration offset: +2°C on the water sensor
        $sensorAddress = '28:AA:BB:CC:DD:EE:FF:00';
        $this->esp32Config->setSensorRole($sensorAddress, 'water');
        $this->esp32Config->setCalibrationOffset($sensorAddress, 2.0); // +2°C offset

        // Store a raw reading: 36°C (96.8°F)
        // With +2°C offset, calibrated = 38°C (100.4°F)
        $rawTempC = 36.0;
        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => $sensorAddress, 'temp_c' => $rawTempC],
            ],
            'uptime_seconds' => 3600,
        ]);

        // Set heater on BEFORE calling start, so checkAndAdjust can turn it off
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $service = $this->createServiceWithCalibration();
        // start() now calls checkAndAdjust() internally
        // Since calibrated temp (100.4°F) >= target (100°F), target is reached
        $result = $service->start(100.0);

        // Should recognize target reached using CALIBRATED temp (100.4°F >= 100°F)
        // NOT raw temp (96.8°F < 100°F which would keep heating)
        $this->assertTrue($result['target_reached'], 'Should use calibrated temp (100.4°F) not raw (96.8°F)');
        $this->assertTrue($result['heater_turned_off']);
    }

    private function createServiceWithCalibration(): TargetTemperatureService
    {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            null, // crontab
            null, // cronRunnerPath
            null, // apiBaseUrl
            $this->esp32Config
        );
    }
}
