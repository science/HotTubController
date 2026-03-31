<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\HeatTargetSettingsService;
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
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        foreach ([$this->stateFile, $this->equipmentStatusFile, $this->esp32TempFile, $this->esp32ConfigFile, $lockFile] as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->tearDownDynamicFiles();
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

    public function testStopTurnsOffHeaterWhenHeaterIsOn(): void
    {
        $this->equipmentStatus->setHeaterOn();

        $this->mockIfttt->expects($this->once())
            ->method('trigger')
            ->with('hot-tub-heat-off')
            ->willReturn(true);

        $service = $this->createService();
        $service->start(103.5);

        $service->stop();

        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);
    }

    public function testStopDoesNotTriggerIftttWhenHeaterAlreadyOff(): void
    {
        $this->equipmentStatus->setHeaterOff();

        $this->mockIfttt->expects($this->never())
            ->method('trigger');

        $service = $this->createService();
        $service->start(103.5);

        $service->stop();

        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);
    }

    // ========== Job file cleanup tests ==========

    public function testStopCleansUpHeatTargetJobFiles(): void
    {
        // Set up directory structure that mimics production:
        // storage/state/target-temperature.json
        // storage/scheduled-jobs/heat-target-*.json
        $tempDir = sys_get_temp_dir() . '/target-temp-cleanup-test-' . uniqid();
        $stateDir = $tempDir . '/state';
        $jobsDir = $tempDir . '/scheduled-jobs';
        mkdir($stateDir, 0755, true);
        mkdir($jobsDir, 0755, true);

        $stateFile = $stateDir . '/target-temperature.json';

        // Create some heat-target job files
        $jobFile1 = $jobsDir . '/heat-target-abc12345.json';
        $jobFile2 = $jobsDir . '/heat-target-def67890.json';
        file_put_contents($jobFile1, json_encode(['jobId' => 'heat-target-abc12345']));
        file_put_contents($jobFile2, json_encode(['jobId' => 'heat-target-def67890']));

        // Also create a non-heat-target job file (should NOT be deleted)
        $otherJobFile = $jobsDir . '/job-regular123.json';
        file_put_contents($otherJobFile, json_encode(['jobId' => 'job-regular123']));

        $service = new TargetTemperatureService($stateFile);
        $service->start(103.5);

        // Verify files exist before stop
        $this->assertFileExists($jobFile1);
        $this->assertFileExists($jobFile2);
        $this->assertFileExists($otherJobFile);

        $service->stop();

        // Verify heat-target job files are deleted
        $this->assertFileDoesNotExist($jobFile1, 'heat-target job file 1 should be deleted');
        $this->assertFileDoesNotExist($jobFile2, 'heat-target job file 2 should be deleted');

        // Verify non-heat-target job file is NOT deleted
        $this->assertFileExists($otherJobFile, 'Regular job file should NOT be deleted');

        // Cleanup
        unlink($otherJobFile);
        rmdir($jobsDir);
        rmdir($stateDir);
        rmdir($tempDir);
    }

    public function testStopHandlesMissingJobsDirectory(): void
    {
        // Set up state file but NO scheduled-jobs directory
        $tempDir = sys_get_temp_dir() . '/target-temp-no-jobs-' . uniqid();
        $stateDir = $tempDir . '/state';
        mkdir($stateDir, 0755, true);
        // Intentionally NOT creating $tempDir . '/scheduled-jobs'

        $stateFile = $stateDir . '/target-temperature.json';

        $service = new TargetTemperatureService($stateFile);
        $service->start(103.5);

        // This should not throw an exception even if jobs dir doesn't exist
        $service->stop();

        $state = $service->getState();
        $this->assertFalse($state['active']);

        // Cleanup
        rmdir($stateDir);
        rmdir($tempDir);
    }

    public function testStopCleansUpJobFilesEvenWhenStateFileAlreadyDeleted(): void
    {
        // Edge case: state file was already deleted but job files remain
        $tempDir = sys_get_temp_dir() . '/target-temp-edge-' . uniqid();
        $stateDir = $tempDir . '/state';
        $jobsDir = $tempDir . '/scheduled-jobs';
        mkdir($stateDir, 0755, true);
        mkdir($jobsDir, 0755, true);

        $stateFile = $stateDir . '/target-temperature.json';

        // Create orphaned job file (state file doesn't exist)
        $jobFile = $jobsDir . '/heat-target-orphaned.json';
        file_put_contents($jobFile, json_encode(['jobId' => 'heat-target-orphaned']));

        $service = new TargetTemperatureService($stateFile);
        // Note: NOT calling start(), state file doesn't exist

        $this->assertFileExists($jobFile);

        $service->stop();

        // Job file should still be cleaned up
        $this->assertFileDoesNotExist($jobFile, 'Orphaned heat-target job file should be deleted');

        // Cleanup
        rmdir($jobsDir);
        rmdir($stateDir);
        rmdir($tempDir);
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

        // Called twice: once for cleanup, once for race condition protection
        $this->mockCrontab->expects($this->exactly(2))
            ->method('removeByPattern')
            ->with('HOTTUB:heat-target');

        $result = $service->checkAndAdjust();

        $this->assertTrue($result['target_reached']);
    }

    public function testCalculateNextCheckTimeReturnsValidMinuteBoundary(): void
    {
        $service = $this->createServiceWithCron();

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();

        // Should be a minute boundary (at :00)
        $this->assertEquals(0, $nextCheckTime % 60,
            "Scheduled time must be at a minute boundary (:00 seconds)");

        // Should be in a future minute (not current minute)
        $currentMinute = (int) floor($now / 60);
        $scheduledMinute = (int) floor($nextCheckTime / 60);
        $this->assertGreaterThan($currentMinute, $scheduledMinute,
            "Must schedule for a future minute, not current minute");

        // Should be within reasonable range (1-2 minutes typically)
        $this->assertLessThanOrEqual($now + 120, $nextCheckTime,
            "Should not schedule more than 2 minutes in the future");
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
     * Verify scheduling doesn't depend on external state (ESP32 readings, etc).
     *
     * The scheduling algorithm should be deterministic based solely on current time.
     * This test verifies it works even without any ESP32 data.
     */
    public function testCalculateNextCheckTimeWorksWithoutEsp32Data(): void
    {
        $service = $this->createServiceWithCron();
        // Note: NOT storing any ESP32 reading - esp32TempFile doesn't exist

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();
        $currentMinute = (int) floor($now / 60);
        $scheduledMinute = (int) floor($nextCheckTime / 60);

        // Must be at minute boundary
        $this->assertEquals(0, $nextCheckTime % 60,
            "Must schedule at minute boundary");

        // Must be in a strictly future minute
        $this->assertGreaterThan(
            $currentMinute,
            $scheduledMinute,
            "Must schedule for FUTURE minute, not current"
        );

        // Must have at least 5 seconds safety margin
        $secondsUntilFire = $nextCheckTime - $now;
        $this->assertGreaterThanOrEqual(5, $secondsUntilFire,
            "Must have at least 5 seconds until cron fires (got $secondsUntilFire)");
    }

    // ========== Minute Boundary Scheduling Tests ==========

    /**
     * Verify scheduled time is always at :00 of a minute (minute boundary).
     *
     * Cron only fires at minute boundaries, so scheduling for :05 or :30
     * within a minute doesn't make sense. The time should snap to :00.
     */
    public function testCalculateNextCheckTimeReturnsMinuteBoundary(): void
    {
        $service = $this->createServiceWithCron();
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $nextCheckTime = $service->calculateNextCheckTime();

        // Should be exactly at :00 of a minute
        $seconds = (int) date('s', $nextCheckTime);
        $this->assertEquals(
            0,
            $seconds,
            "Scheduled time should be at :00 of a minute, but got :" . sprintf('%02d', $seconds) .
            "\nScheduled: " . date('Y-m-d H:i:s', $nextCheckTime)
        );
    }

    /**
     * Verify scheduled minute is strictly in the future (not current minute).
     *
     * If we're at 5:01:XX, we must schedule for 5:02 or later, never 5:01.
     * Cron daemon fires at :00 - if we're past that, the job never runs.
     */
    public function testCalculateNextCheckTimeSchedulesForFutureMinute(): void
    {
        $service = $this->createServiceWithCron();
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $now = time();
        $currentMinute = (int) floor($now / 60);
        $nextCheckTime = $service->calculateNextCheckTime();
        $scheduledMinute = (int) floor($nextCheckTime / 60);

        $this->assertGreaterThan(
            $currentMinute,
            $scheduledMinute,
            "Scheduled minute must be AFTER current minute.\n" .
            "Current: " . date('Y-m-d H:i:s', $now) . " (minute $currentMinute)\n" .
            "Scheduled: " . date('Y-m-d H:i:s', $nextCheckTime) . " (minute $scheduledMinute)\n" .
            "Cron for current minute would never fire!"
        );
    }

    /**
     * Verify at least 5 seconds margin before scheduled minute.
     *
     * We need buffer time to write the crontab entry before the daemon fires.
     * If scheduled minute is only 2 seconds away, we might miss it.
     */
    public function testCalculateNextCheckTimeHasSafetyMargin(): void
    {
        $service = $this->createServiceWithCron();
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();
        $secondsUntilFire = $nextCheckTime - $now;

        $this->assertGreaterThanOrEqual(
            5,
            $secondsUntilFire,
            "Must be at least 5 seconds until cron fires.\n" .
            "Current: " . date('Y-m-d H:i:s', $now) . "\n" .
            "Scheduled: " . date('Y-m-d H:i:s', $nextCheckTime) . "\n" .
            "Seconds until fire: $secondsUntilFire\n" .
            "Not enough time to write crontab before daemon fires!"
        );
    }

    /**
     * Edge case: Late in the minute (e.g., :55) should skip to minute+2.
     *
     * At 5:01:55, the next minute boundary is 5:02:00 (only 5 seconds away).
     * That's too close - we should schedule for 5:03:00 instead.
     */
    public function testCalculateNextCheckTimeLateInMinuteSkipsToNextNext(): void
    {
        $service = $this->createServiceWithCron();
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        // This test is timing-sensitive. Run it multiple times to catch edge cases.
        // The key assertion is that we ALWAYS have >= 5 seconds margin.
        for ($i = 0; $i < 10; $i++) {
            $now = time();
            $nextCheckTime = $service->calculateNextCheckTime();
            $secondsUntilFire = $nextCheckTime - $now;

            $this->assertGreaterThanOrEqual(
                5,
                $secondsUntilFire,
                "Iteration $i: Must be at least 5 seconds until cron fires.\n" .
                "Current: " . date('Y-m-d H:i:s', $now) . " (second " . date('s', $now) . ")\n" .
                "Scheduled: " . date('Y-m-d H:i:s', $nextCheckTime) . "\n" .
                "Seconds until fire: $secondsUntilFire"
            );

            usleep(100000); // 100ms between iterations
        }
    }

    // ========== Next minute scheduling tests (no ESP32 syncing) ==========

    /**
     * Verify scheduling does NOT sync to ESP32 timing.
     *
     * The old buggy code would calculate:
     * - nextReport = receivedAt + interval (e.g., now + 58 seconds if just reported)
     * - desiredCheckTime = nextReport + 5 = now + 63 seconds
     * - Round up to minute boundary = minute+2 instead of minute+1!
     *
     * This caused skipped minutes in production (06:57 → 06:59, skipping 06:58).
     *
     * The fix: Always schedule for the immediate next minute, regardless of
     * when ESP32 last reported. ESP32 prereport alignment (`:53` or `:55`)
     * ensures data is fresh when the check runs.
     */
    public function testCalculateNextCheckTimeIgnoresEsp32Timing(): void
    {
        $service = $this->createServiceWithCron();
        $this->equipmentStatus->setHeaterOn();

        // Store ESP32 reading received just now (2 seconds ago)
        // Old code would calculate: nextReport = now + 58, desiredTime = now + 63
        // Then round up to minute+2, SKIPPING the immediate next minute!
        $now = time();
        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => 28.0, 'temp_f' => 82.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        // Manually set received_at to 2 seconds ago
        $data = json_decode(file_get_contents($this->esp32TempFile), true);
        $data['received_at'] = $now - 2;
        file_put_contents($this->esp32TempFile, json_encode($data));

        $nextCheckTime = $service->calculateNextCheckTime();

        // Calculate expected: should be NEXT minute, not minute+2
        $currentMinute = (int) floor($now / 60);
        $secondsIntoMinute = $now % 60;

        // If we're late in the minute (> 55 seconds, i.e., less than 5 seconds margin),
        // next minute is too close, so we expect minute+2. Otherwise, we expect minute+1.
        // Note: safety margin is < 5, so at :55 we have exactly 5 seconds which is OK.
        $expectedMinute = ($secondsIntoMinute > 55)
            ? $currentMinute + 2
            : $currentMinute + 1;

        $scheduledMinute = (int) floor($nextCheckTime / 60);

        $this->assertEquals(
            $expectedMinute,
            $scheduledMinute,
            "Should schedule for immediate next minute, not sync to ESP32 timing.\n" .
            "Now: " . date('Y-m-d H:i:s', $now) . " (second $secondsIntoMinute of minute)\n" .
            "Expected minute: $expectedMinute\n" .
            "Scheduled minute: $scheduledMinute\n" .
            "ESP32 timing should NOT affect scheduling!"
        );
    }

    /**
     * Verify that scheduling is simple: always next minute (or +2 if close).
     *
     * Run this test at different seconds-into-minute to verify behavior:
     * - At :00-:54 → schedule for next minute
     * - At :55-:59 → schedule for minute+2 (safety margin)
     */
    public function testCalculateNextCheckTimeAlwaysSchedulesNextAvailableMinute(): void
    {
        $service = $this->createServiceWithCron();
        $this->storeEsp32Reading(82.0);
        $this->equipmentStatus->setHeaterOn();

        $now = time();
        $nextCheckTime = $service->calculateNextCheckTime();

        $currentMinute = (int) floor($now / 60);
        $scheduledMinute = (int) floor($nextCheckTime / 60);
        $secondsIntoMinute = $now % 60;

        // Should be exactly 1 minute ahead (or 2 if we're close to boundary)
        $minutesDiff = $scheduledMinute - $currentMinute;

        if ($secondsIntoMinute > 55) {
            // Very late in minute (< 5 seconds margin), expect +2
            $this->assertEquals(2, $minutesDiff,
                "At second $secondsIntoMinute, should schedule 2 minutes ahead");
        } else {
            // Normal case (>= 5 seconds margin), expect +1
            $this->assertEquals(1, $minutesDiff,
                "At second $secondsIntoMinute, should schedule 1 minute ahead, " .
                "but got $minutesDiff minutes ahead");
        }
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

    // ========== Duplicate start prevention tests ==========

    public function testStartRejectsWhenAlreadyActive(): void
    {
        $service = $this->createBasicService();
        $service->start(103.5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already active');

        $service->start(103.5);
    }

    public function testStartAllowsAfterPreviousSessionStopped(): void
    {
        $service = $this->createBasicService();
        $service->start(103.5);
        $service->stop();

        // Should not throw - previous session was stopped
        $service->start(100.0);

        $state = $service->getState();
        $this->assertTrue($state['active']);
        $this->assertEquals(100.0, $state['target_temp_f']);
    }

    public function testStartRejectsWithDifferentTargetTemp(): void
    {
        $service = $this->createBasicService();
        $service->start(103.5);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already active');

        $service->start(100.0);
    }

    public function testCheckAndAdjustSkipsWhenLockHeld(): void
    {
        $service = $this->createBasicService();
        $service->start(103.5);

        // Hold the lock externally
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        $fp = fopen($lockFile, 'c');
        flock($fp, LOCK_EX);

        $result = $service->checkAndAdjust();

        flock($fp, LOCK_UN);
        fclose($fp);

        $this->assertArrayHasKey('skipped', $result);
        $this->assertTrue($result['skipped']);
    }

    public function testCheckAndAdjustReleasesLockAfterCompletion(): void
    {
        $service = $this->createBasicService();
        // Don't start - checkAndAdjust on inactive state should still acquire/release lock

        $service->checkAndAdjust();

        // Lock should be released - we should be able to acquire it
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        $fp = fopen($lockFile, 'c');
        $locked = flock($fp, LOCK_EX | LOCK_NB);
        flock($fp, LOCK_UN);
        fclose($fp);

        $this->assertTrue($locked, 'Lock should be released after checkAndAdjust completes');
    }

    // ==================== Dynamic Target Tests ====================

    private function createServiceWithDynamic(
        HeatTargetSettingsService $heatTargetSettings,
        ?string $equipmentEventLogFile = null
    ): TargetTemperatureService {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->esp32Config,
            null, // cronSchedulingService
            $heatTargetSettings,
            null, // stallEventFile
            $equipmentEventLogFile
        );
    }

    private function storeEsp32ReadingWithAmbient(float $waterTempF, float $ambientTempF): void
    {
        $waterTempC = ($waterTempF - 32) * 5 / 9;
        $ambientTempC = ($ambientTempF - 32) * 5 / 9;

        $this->esp32Temp->store([
            'device_id' => 'TEST:AA:BB:CC:DD:EE',
            'sensors' => [
                ['address' => '28:AA:BB:CC:DD:EE:FF:00', 'temp_c' => $waterTempC, 'temp_f' => $waterTempF],
                ['address' => '28:BB:CC:DD:EE:FF:00:11', 'temp_c' => $ambientTempC, 'temp_f' => $ambientTempF],
            ],
            'uptime_seconds' => 3600,
        ]);

        // Configure sensor roles
        $this->esp32Config->setSensorRole('28:AA:BB:CC:DD:EE:FF:00', 'water');
        $this->esp32Config->setSensorRole('28:BB:CC:DD:EE:FF:00:11', 'ambient');
    }

    private function createDynamicHeatTargetSettings(
        bool $dynamicMode = true,
        ?array $calibrationPoints = null
    ): HeatTargetSettingsService {
        $settingsFile = sys_get_temp_dir() . '/test_dynamic_settings_' . uniqid() . '.json';
        $this->dynamicSettingsFiles[] = $settingsFile;
        $service = new HeatTargetSettingsService($settingsFile);
        $points = $calibrationPoints ?? [
            'cold'    => ['ambient_f' => 45.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 60.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];
        $service->updateDynamicSettings($dynamicMode, $points);
        $service->updateSettings(true, 102.0); // static target as fallback
        return $service;
    }

    /** @var string[] Temp files created by dynamic tests, cleaned up in tearDown */
    private array $dynamicSettingsFiles = [];

    public function testStartWithDynamicModeComputesCorrectTarget(): void
    {
        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings);

        // Ambient at comfort point (60F) → water target 102.0F
        $this->storeEsp32ReadingWithAmbient(90.0, 60.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $result = $service->start(102.0);

        $state = $service->getState();
        $this->assertTrue($state['active']);
        $this->assertEqualsWithDelta(102.0, $state['target_temp_f'], 0.01);
        $this->assertArrayHasKey('dynamic_target_info', $state);
        $this->assertTrue($state['dynamic_target_info']['dynamic_mode']);
        $this->assertEqualsWithDelta(60.0, $state['dynamic_target_info']['ambient_temp_f'], 0.1);
        $this->assertFalse($state['dynamic_target_info']['fallback']);
    }

    public function testStartWithDynamicModeInterpolatesColdSegment(): void
    {
        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings);

        // Ambient at 52.5F (midpoint of cold segment) → water target 103.0F
        $this->storeEsp32ReadingWithAmbient(90.0, 52.5);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $result = $service->start(102.0);

        $state = $service->getState();
        $this->assertEqualsWithDelta(103.0, $state['target_temp_f'], 0.01);
        $this->assertEqualsWithDelta(103.0, $state['dynamic_target_info']['computed_target_f'], 0.01);
    }

    public function testStartWithDynamicModeClampsBelowCold(): void
    {
        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings);

        // Ambient at 30F (below cold point 45F) → clamp to 104.0F
        $this->storeEsp32ReadingWithAmbient(90.0, 30.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $result = $service->start(102.0);

        $state = $service->getState();
        $this->assertEqualsWithDelta(104.0, $state['target_temp_f'], 0.01);
        $this->assertTrue($state['dynamic_target_info']['clamped']);
    }

    public function testStartWithDynamicModeFallsBackWhenNoAmbient(): void
    {
        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings);

        // Only water sensor, no ambient
        $this->storeEsp32Reading(90.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $result = $service->start(102.0);

        $state = $service->getState();
        // Falls back to static target
        $this->assertEqualsWithDelta(102.0, $state['target_temp_f'], 0.01);
        $this->assertTrue($state['dynamic_target_info']['fallback']);
    }

    public function testStartWithDynamicModeDisabledUsesStaticTarget(): void
    {
        $heatSettings = $this->createDynamicHeatTargetSettings(false);
        $service = $this->createServiceWithDynamic($heatSettings);

        $this->storeEsp32ReadingWithAmbient(90.0, 52.5);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $result = $service->start(102.0);

        $state = $service->getState();
        // Should use the passed-in target, not dynamic
        $this->assertEqualsWithDelta(102.0, $state['target_temp_f'], 0.01);
        $this->assertArrayNotHasKey('dynamic_target_info', $state);
    }

    public function testDynamicStartLogsDecisionContext(): void
    {
        $logFile = sys_get_temp_dir() . '/test_equip_events_' . uniqid() . '.log';
        $this->dynamicSettingsFiles[] = $logFile;

        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings, $logFile);

        $this->storeEsp32ReadingWithAmbient(90.0, 52.5);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $service->start(102.0);

        $this->assertFileExists($logFile);
        $content = file_get_contents($logFile);
        $lines = array_filter(explode("\n", trim($content)));

        // Find the dynamic_heat_target_start log entry
        $dynamicLogEntry = null;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && ($entry['action'] ?? '') === 'dynamic_heat_target_start') {
                $dynamicLogEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($dynamicLogEntry, 'Should log dynamic_heat_target_start event');
        $this->assertEquals('heater', $dynamicLogEntry['equipment']);
        $this->assertEqualsWithDelta(52.5, $dynamicLogEntry['ambient_temp_f'], 0.1);
        $this->assertEqualsWithDelta(103.0, $dynamicLogEntry['computed_target_f'], 0.01);
        $this->assertEqualsWithDelta(102.0, $dynamicLogEntry['static_target_f'], 0.01);
        $this->assertArrayHasKey('calibration_points', $dynamicLogEntry);
        $this->assertFalse($dynamicLogEntry['clamped']);
        $this->assertFalse($dynamicLogEntry['fallback']);
    }

    public function testDynamicFallbackLogsCorrectAction(): void
    {
        $logFile = sys_get_temp_dir() . '/test_equip_events_' . uniqid() . '.log';
        $this->dynamicSettingsFiles[] = $logFile;

        $heatSettings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithDynamic($heatSettings, $logFile);

        // Only water sensor, no ambient
        $this->storeEsp32Reading(90.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');

        $service->start(102.0);

        $content = file_get_contents($logFile);
        $lines = array_filter(explode("\n", trim($content)));

        $fallbackEntry = null;
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if ($entry && ($entry['action'] ?? '') === 'dynamic_heat_target_fallback') {
                $fallbackEntry = $entry;
                break;
            }
        }

        $this->assertNotNull($fallbackEntry, 'Should log dynamic_heat_target_fallback event');
        $this->assertTrue($fallbackEntry['fallback']);
        $this->assertArrayHasKey('fallback_reason', $fallbackEntry);
    }

    protected function tearDownDynamicFiles(): void
    {
        foreach ($this->dynamicSettingsFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }
        $this->dynamicSettingsFiles = [];
    }

    // ==================== ETA Computation Tests ====================

    private function createHeatingCharacteristicsFile(
        float $velocity = 0.1,
        float $startupLag = 5.0
    ): string {
        $file = sys_get_temp_dir() . '/test_heating_chars_' . uniqid() . '.json';
        $this->dynamicSettingsFiles[] = $file;
        file_put_contents($file, json_encode([
            'heating_velocity_f_per_min' => $velocity,
            'startup_lag_minutes' => $startupLag,
            'overshoot_degrees_f' => 0.5,
            'sessions_analyzed' => 3,
            'generated_at' => date('c'),
        ]));
        return $file;
    }

    private function createServiceWithEta(
        ?string $heatingCharsFile = null
    ): TargetTemperatureService {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->esp32Config,
            null, // cronSchedulingService
            null, // heatTargetSettings
            null, // stallEventFile
            null, // equipmentEventLogFile
            $heatingCharsFile
        );
    }

    public function testComputeEtaReturnsNullWhenNotActive(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile();
        $service = $this->createServiceWithEta($charsFile);

        $this->assertNull($service->computeEta());
    }

    public function testComputeEtaReturnsNullWhenNoCharacteristics(): void
    {
        $service = $this->createServiceWithEta(null);

        // Start a session
        $this->storeEsp32Reading(90.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');
        $service->start(103.0);

        $this->assertNull($service->computeEta());
    }

    public function testComputeEtaReturnsNullWhenCharacteristicsFileMissing(): void
    {
        $service = $this->createServiceWithEta('/tmp/nonexistent_chars.json');

        $this->storeEsp32Reading(90.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');
        $service->start(103.0);

        $this->assertNull($service->computeEta());
    }

    public function testComputeEtaCalculatesCorrectly(): void
    {
        // 0.1°F/min velocity, 5 min startup lag
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $service = $this->createServiceWithEta($charsFile);

        // Water at 93°F, target 103°F → 10°F to go at 0.1°F/min = 100 min
        $this->storeEsp32Reading(93.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');
        $service->start(103.0);

        $eta = $service->computeEta();

        $this->assertNotNull($eta);
        $this->assertArrayHasKey('eta_timestamp', $eta);
        $this->assertArrayHasKey('minutes_remaining', $eta);
        $this->assertArrayHasKey('heating_velocity', $eta);
        // Just started → full startup lag applies: 100 + 5 = 105 min
        $this->assertEqualsWithDelta(105.0, $eta['minutes_remaining'], 1.0);
        $this->assertEqualsWithDelta(0.1, $eta['heating_velocity'], 0.001);
    }

    public function testComputeEtaSkipsStartupLagWhenPastLagWindow(): void
    {
        // 0.1°F/min velocity, 5 min startup lag
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $service = $this->createServiceWithEta($charsFile);

        // Simulate a session started 10 minutes ago (past the 5-min lag)
        $this->storeEsp32Reading(93.0);
        $startedAt = (new \DateTimeImmutable('-10 minutes', new \DateTimeZone('UTC')))->format('c');
        $state = [
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $eta = $service->computeEta();

        $this->assertNotNull($eta);
        // 10°F at 0.1°F/min = 100 min, no lag since past window
        $this->assertEqualsWithDelta(100.0, $eta['minutes_remaining'], 1.0);
    }

    public function testComputeEtaIncludesPartialStartupLag(): void
    {
        // 0.1°F/min velocity, 10 min startup lag
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 10.0);
        $service = $this->createServiceWithEta($charsFile);

        // Session started 3 minutes ago (7 min of lag remaining)
        $this->storeEsp32Reading(93.0);
        $startedAt = (new \DateTimeImmutable('-3 minutes', new \DateTimeZone('UTC')))->format('c');
        $state = [
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => $startedAt,
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $eta = $service->computeEta();

        $this->assertNotNull($eta);
        // 10°F at 0.1°F/min = 100 min + 7 min remaining lag = 107 min
        $this->assertEqualsWithDelta(107.0, $eta['minutes_remaining'], 1.5);
    }

    public function testComputeEtaReturnsNullWhenTargetReached(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $service = $this->createServiceWithEta($charsFile);

        // Water already at target
        $this->storeEsp32Reading(103.0);
        $state = [
            'active' => true,
            'target_temp_f' => 103.0,
            'started_at' => date('c'),
        ];
        file_put_contents($this->stateFile, json_encode($state));

        $eta = $service->computeEta();

        $this->assertNull($eta);
    }

    public function testComputeEtaIncludesEtaTimestamp(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(1.0, 0.0);
        $service = $this->createServiceWithEta($charsFile);

        // 10°F to go at 1°F/min = 10 min from now
        $this->storeEsp32Reading(93.0);
        $this->mockIfttt->expects($this->atLeastOnce())->method('trigger');
        $service->start(103.0);

        $eta = $service->computeEta();

        $this->assertNotNull($eta);
        $etaTime = new \DateTimeImmutable($eta['eta_timestamp']);
        $expectedTime = new \DateTimeImmutable('+10 minutes');
        // Should be within 2 minutes of expected
        $diffSeconds = abs($etaTime->getTimestamp() - $expectedTime->getTimestamp());
        $this->assertLessThan(120, $diffSeconds);
    }

    // ==================== Projected ETA Tests ====================

    private function createServiceWithProjectedEta(
        HeatTargetSettingsService $heatSettings,
        ?string $heatingCharsFile = null
    ): TargetTemperatureService {
        return new TargetTemperatureService(
            $this->stateFile,
            $this->mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $this->mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->esp32Config,
            null, // cronSchedulingService
            $heatSettings,
            null, // stallEventFile
            null, // equipmentEventLogFile
            $heatingCharsFile
        );
    }

    private function createStaticHeatTargetSettings(
        bool $enabled = true,
        float $targetTempF = 103.0
    ): HeatTargetSettingsService {
        $file = sys_get_temp_dir() . '/test_static_settings_' . uniqid() . '.json';
        $this->dynamicSettingsFiles[] = $file;
        $service = new HeatTargetSettingsService($file);
        $service->updateSettings($enabled, $targetTempF);
        return $service;
    }

    public function testComputeProjectedEtaWhenHeaterOff(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $settings = $this->createStaticHeatTargetSettings(true, 103.0);
        $service = $this->createServiceWithProjectedEta($settings, $charsFile);

        // Water at 92°F, no active session
        $this->storeEsp32Reading(92.0);

        $eta = $service->computeProjectedEta();

        $this->assertNotNull($eta);
        // 11°F at 0.1°F/min = 110 min + 5 min full startup lag = 115 min
        $this->assertEqualsWithDelta(115.0, $eta['minutes_remaining'], 1.0);
        $this->assertTrue($eta['projected']);
        $this->assertEqualsWithDelta(103.0, $eta['target_temp_f'], 0.1);
    }

    public function testComputeProjectedEtaUsesDynamicTarget(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $settings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithProjectedEta($settings, $charsFile);

        // Water at 92°F, ambient at 52.5°F → dynamic target ~103°F (midpoint cold segment)
        $this->storeEsp32ReadingWithAmbient(92.0, 52.5);

        $eta = $service->computeProjectedEta();

        $this->assertNotNull($eta);
        $this->assertTrue($eta['projected']);
        // Dynamic target should be ~103°F, so ~110 min + 5 lag = 115
        $this->assertEqualsWithDelta(103.0, $eta['target_temp_f'], 0.5);
    }

    public function testComputeProjectedEtaReturnsNullWhenDisabled(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $settings = $this->createStaticHeatTargetSettings(false, 103.0);
        $service = $this->createServiceWithProjectedEta($settings, $charsFile);

        $this->storeEsp32Reading(92.0);

        $this->assertNull($service->computeProjectedEta());
    }

    public function testComputeProjectedEtaReturnsNullWhenAtTarget(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $settings = $this->createStaticHeatTargetSettings(true, 103.0);
        $service = $this->createServiceWithProjectedEta($settings, $charsFile);

        $this->storeEsp32Reading(103.5);

        $this->assertNull($service->computeProjectedEta());
    }

    public function testComputeProjectedEtaDynamicFallsBackWithoutAmbient(): void
    {
        $charsFile = $this->createHeatingCharacteristicsFile(0.1, 5.0);
        $settings = $this->createDynamicHeatTargetSettings(true);
        $service = $this->createServiceWithProjectedEta($settings, $charsFile);

        // Only water sensor, no ambient → falls back to static target
        $this->storeEsp32Reading(92.0);

        $eta = $service->computeProjectedEta();

        $this->assertNotNull($eta);
        // Falls back to static target (102.0 from createDynamicHeatTargetSettings)
        $this->assertEqualsWithDelta(102.0, $eta['target_temp_f'], 0.1);
    }
}
