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
}
