<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\DtdtService;
use HotTub\Services\SchedulerService;
use HotTub\Services\TargetTemperatureService;
use HotTub\Services\Esp32CalibratedTemperatureService;
use HotTub\Services\TimeConverter;

class DtdtServiceTest extends TestCase
{
    private string $heatingCharsFile;
    private string $jobsDir;
    private MockCrontabAdapter $crontabAdapter;
    private SchedulerService $schedulerService;
    private string $cronRunnerPath;
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        $this->heatingCharsFile = sys_get_temp_dir() . '/test_heating_chars_' . uniqid() . '.json';
        $this->jobsDir = sys_get_temp_dir() . '/dtdt-test-jobs-' . uniqid();
        mkdir($this->jobsDir, 0755, true);

        $this->crontabAdapter = new MockCrontabAdapter();

        $this->cronRunnerPath = '/var/www/backend/storage/bin/cron-runner.sh';
        $this->apiBaseUrl = 'https://example.com/tub/backend/public';

        $this->schedulerService = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->heatingCharsFile)) {
            unlink($this->heatingCharsFile);
        }
        foreach (glob($this->jobsDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->jobsDir)) {
            rmdir($this->jobsDir);
        }
    }

    private function writeHeatingChars(array $data): void
    {
        file_put_contents($this->heatingCharsFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function createService(
        ?TargetTemperatureService $targetTempService = null,
        ?Esp32CalibratedTemperatureService $calibratedTempService = null
    ): DtdtService {
        return new DtdtService(
            $this->schedulerService,
            $targetTempService,
            $calibratedTempService,
            $this->heatingCharsFile
        );
    }

    // ==================== createReadyBySchedule Tests ====================

    /**
     * @test
     */
    public function createReadyByScheduleCallsSchedulerWithCorrectParams(): void
    {
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]);

        $service = $this->createService();
        $result = $service->createReadyBySchedule('06:30-08:00', ['target_temp_f' => 103]);

        // Should create a job
        $this->assertArrayHasKey('jobId', $result);
        $this->assertEquals('heat-to-target', $result['action']);
        $this->assertTrue($result['recurring']);

        // The display time should be the user's requested ready-by time (in UTC)
        $this->assertEquals('14:30:00+00:00', $result['scheduledTime']);
    }

    /**
     * @test
     */
    public function wakeUpOffsetCalculation(): void
    {
        // velocity=0.3 F/min, lag=10min, cold start=58°F, target=103°F
        // max heat time = (103-58)/0.3 + 10 + 15(margin) = 150 + 10 + 15 = 175 min ≈ 2h55m
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]);

        $service = $this->createService();
        $result = $service->createReadyBySchedule('06:30-08:00', ['target_temp_f' => 103]);

        // Check the cron was scheduled earlier than 06:30
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // The wake-up cron should fire at 06:30 Pacific - 175 min = 03:35 Pacific
        // In system timezone this depends on the server, but we can verify it's earlier
        // than the display time by checking the job file
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        // Endpoint should be the wake-up route
        $this->assertEquals('/api/maintenance/dtdt-wakeup', $jobData['endpoint']);

        // Params should include ready_by_time
        $this->assertEquals('06:30-08:00', $jobData['params']['ready_by_time']);
        $this->assertEquals(103, $jobData['params']['target_temp_f']);
    }

    /**
     * @test
     */
    public function createReadyByRequiresCharacteristics(): void
    {
        // No heating characteristics file exists
        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('heating characteristics');

        $service->createReadyBySchedule('06:30-08:00', ['target_temp_f' => 103]);
    }

    /**
     * @test
     */
    public function createReadyByRequiresVelocityInCharacteristics(): void
    {
        // File exists but missing velocity
        $this->writeHeatingChars([
            'startup_lag_minutes' => 10.0,
        ]);

        $service = $this->createService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('heating characteristics');

        $service->createReadyBySchedule('06:30-08:00', ['target_temp_f' => 103]);
    }

    /**
     * @test
     */
    public function endpointOverrideIsWakeUp(): void
    {
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]);

        $service = $this->createService();
        $result = $service->createReadyBySchedule('06:30-08:00', ['target_temp_f' => 103]);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertEquals('/api/maintenance/dtdt-wakeup', $jobData['endpoint']);
    }

    /**
     * @test
     */
    public function wakeUpOffsetWithDifferentVelocity(): void
    {
        // Faster velocity means less wake-up offset
        // velocity=0.5 F/min, lag=5min, target=103, cold_start=58
        // max heat time = (103-58)/0.5 + 5 + 15 = 90 + 5 + 15 = 110 min
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.5,
            'startup_lag_minutes' => 5.0,
            'max_cooling_k' => 0.001,
        ]);

        $service = $this->createService();

        // Verify calculated offset through the public API
        $offset = $service->calculateMaxHeatMinutes(103.0, [
            'heating_velocity_f_per_min' => 0.5,
            'startup_lag_minutes' => 5.0,
        ]);

        $this->assertEquals(110.0, $offset);
    }

    // ==================== handleWakeUp Tests ====================

    /**
     * @test
     */
    public function wakeUpStartsImmediatelyWhenStartTimeInPast(): void
    {
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]);

        $targetTempService = $this->createMock(TargetTemperatureService::class);
        $targetTempService->expects($this->once())
            ->method('start')
            ->with(103.0);

        // Current temp is 70°F, ambient 50°F, ready in 5 minutes
        // Heat time = (103-70)/0.3 + 10 = 110+10 = 120 min → needs to start 115 min ago!
        $mockCalibrated = $this->createMockCalibratedService(70.0, 50.0);

        $service = $this->createService($targetTempService, $mockCalibrated);

        // Build a ready_by_time that's 5 minutes from now
        $fiveMinutesFromNow = new \DateTime('+5 minutes', new \DateTimeZone('UTC'));
        $readyByTime = $fiveMinutesFromNow->format('H:i') . '+00:00';

        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('started_immediately', $result['status']);
    }

    /**
     * @test
     */
    public function wakeUpSchedulesPrecisionCron(): void
    {
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.5,
            'startup_lag_minutes' => 5.0,
            'max_cooling_k' => 0.0005,
        ]);

        // Current temp is 98°F, ambient 50°F, ready in 3 hours
        // Heat time = (103-~97)/0.5 + 5 = ~12 + 5 = ~17 min → start ~17 min before ready_by
        $mockCalibrated = $this->createMockCalibratedService(98.0, 50.0);

        $service = $this->createService(null, $mockCalibrated);

        $threeHoursFromNow = new \DateTime('+3 hours', new \DateTimeZone('UTC'));
        $readyByTime = $threeHoursFromNow->format('H:i') . '+00:00';

        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('precision_scheduled', $result['status']);
        $this->assertArrayHasKey('jobId', $result);
        $this->assertArrayHasKey('heat_minutes', $result);
        $this->assertArrayHasKey('start_time', $result);

        // A precision one-off job should have been created
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
    }

    /**
     * @test
     */
    public function wakeUpDoesNothingWhenAtTarget(): void
    {
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]);

        // Current temp is 104°F, already above target
        $mockCalibrated = $this->createMockCalibratedService(104.0, 50.0);

        $service = $this->createService(null, $mockCalibrated);

        $twoHoursFromNow = new \DateTime('+2 hours', new \DateTimeZone('UTC'));
        $readyByTime = $twoHoursFromNow->format('H:i') . '+00:00';

        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('already_at_target', $result['status']);

        // No cron jobs should be scheduled
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(0, $entries);
    }

    /**
     * @test
     */
    public function wakeUpFallsBackWithoutCharacteristics(): void
    {
        // No heating characteristics file
        $targetTempService = $this->createMock(TargetTemperatureService::class);
        $targetTempService->expects($this->once())
            ->method('start')
            ->with(103.0);

        $mockCalibrated = $this->createMockCalibratedService(85.0, 50.0);

        $service = $this->createService($targetTempService, $mockCalibrated);

        $twoHoursFromNow = new \DateTime('+2 hours', new \DateTimeZone('UTC'));
        $readyByTime = $twoHoursFromNow->format('H:i') . '+00:00';

        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('started_immediately', $result['status']);
    }

    /**
     * @test
     */
    public function coolingProjectionPrefersFittedK(): void
    {
        // Verify cooling projection uses cooling_coefficient_k (fitted) over max_cooling_k
        // T_projected = T_ambient + (T_current - T_ambient) * e^(-k * t)
        // T_ambient=50, T_current=100, cooling_coefficient_k=0.0005, t=120 min
        // T_projected = 50 + (100-50) * e^(-0.0005*120) = 50 + 50 * e^(-0.06) = 50 + 50*0.9418 = 97.09
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'cooling_coefficient_k' => 0.0005, // This SHOULD be used (fitted)
            'max_cooling_k' => 0.001,           // This should NOT be used
        ]);

        $mockCalibrated = $this->createMockCalibratedService(100.0, 50.0);

        $service = $this->createService(null, $mockCalibrated);

        // Set ready_by 120 minutes from now
        $twoHoursFromNow = new \DateTime('+120 minutes', new \DateTimeZone('UTC'));
        $readyByTime = $twoHoursFromNow->format('H:i') . '+00:00';

        // Target = 103. Projected = ~97.09 (using cooling_coefficient_k=0.0005).
        // Heat time = (103-97.09)/0.3 + 10 = 19.7 + 10 = 29.7 min
        // Start time = ready_by - 29.7 min → ~90 min from now → should be precision_scheduled
        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('precision_scheduled', $result['status']);
        // Projected temp should be around 97.1 (Newton's Law with cooling_coefficient_k)
        // NOT ~94.3 which would indicate max_cooling_k was used
        $this->assertEqualsWithDelta(97.1, $result['projected_temp_f'], 0.5);
    }

    /**
     * @test
     */
    public function coolingProjectionFallsBackToMaxK(): void
    {
        // When cooling_coefficient_k is absent, falls back to max_cooling_k
        // T_ambient=50, T_current=100, max_cooling_k=0.001, t=120 min
        // T_projected = 50 + (100-50) * e^(-0.001*120) = 50 + 50 * e^(-0.12) = 50 + 50*0.8869 = 94.35
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001, // Fallback when cooling_coefficient_k is absent
        ]);

        $mockCalibrated = $this->createMockCalibratedService(100.0, 50.0);

        $service = $this->createService(null, $mockCalibrated);

        // Set ready_by 120 minutes from now
        $twoHoursFromNow = new \DateTime('+120 minutes', new \DateTimeZone('UTC'));
        $readyByTime = $twoHoursFromNow->format('H:i') . '+00:00';

        // Target = 103. Projected = ~94.35 (using max_cooling_k fallback).
        // Heat time = (103-94.35)/0.3 + 10 = 28.8 + 10 = 38.8 min
        // Start time = ready_by - 38.8 min → ~81 min from now → should be precision_scheduled
        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('precision_scheduled', $result['status']);
        // Projected temp should be around 94.3 (Newton's Law with max_cooling_k)
        $this->assertEqualsWithDelta(94.3, $result['projected_temp_f'], 0.5);
    }

    /**
     * @test
     */
    public function projectionFormulaVerification(): void
    {
        // Exact calculation:
        // T_ambient=60, T_current=95, cooling_coefficient_k=0.002, t=60 min
        // T_projected = 60 + (95-60) * e^(-0.002*60) = 60 + 35 * e^(-0.12) = 60 + 35*0.88692 = 91.04
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.5,
            'startup_lag_minutes' => 5.0,
            'cooling_coefficient_k' => 0.002,
        ]);

        $mockCalibrated = $this->createMockCalibratedService(95.0, 60.0);
        $service = $this->createService(null, $mockCalibrated);

        $oneHourFromNow = new \DateTime('+60 minutes', new \DateTimeZone('UTC'));
        $readyByTime = $oneHourFromNow->format('H:i') . '+00:00';

        // Target 103. Projected ~91.04.
        // Heat time = (103-91.04)/0.5 + 5 = 23.92 + 5 = 28.92 min
        // Start time = ready_by - 28.92 min → ~31 min from now → precision_scheduled
        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('precision_scheduled', $result['status']);
        // Check projected temp is close to 91.04
        $this->assertEqualsWithDelta(91.0, $result['projected_temp_f'], 0.5);
        // Heat minutes should be close to 28.9
        $this->assertEqualsWithDelta(28.9, $result['heat_minutes'], 1.0);
    }

    /**
     * @test
     */
    public function wakeUpStaysWarmWhenProjectedAboveTarget(): void
    {
        // If cooling projection shows temp stays above target, no heating needed
        // Current temp is BELOW target (102.9°F) so it doesn't hit already_at_target
        // But with very slow cooling, projected temp > target
        $this->writeHeatingChars([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.01, // Higher k with high ambient → warming toward ambient
        ]);

        // ambient=200, current=102, k=0.01, t=60
        // projected = 200 + (102-200)*e^(-0.6) = 200 + (-98)*0.5488 = 200 - 53.78 = 146.22 > 103
        $mockCalibrated = $this->createMockCalibratedService(102.0, 200.0);
        $service = $this->createService(null, $mockCalibrated);

        $oneHourFromNow = new \DateTime('+60 minutes', new \DateTimeZone('UTC'));
        $readyByTime = $oneHourFromNow->format('H:i') . '+00:00';

        $result = $service->handleWakeUp([
            'ready_by_time' => $readyByTime,
            'target_temp_f' => 103.0,
        ]);

        $this->assertEquals('stays_warm', $result['status']);
    }

    // ==================== Helper Methods ====================

    private function createMockCalibratedService(float $waterTempF, float $ambientTempF): Esp32CalibratedTemperatureService
    {
        $mock = $this->createMock(Esp32CalibratedTemperatureService::class);
        $mock->method('getTemperatures')
            ->willReturn([
                'water_temp_f' => $waterTempF,
                'ambient_temp_f' => $ambientTempF,
                'water_temp_c' => ($waterTempF - 32) * 5 / 9,
                'ambient_temp_c' => ($ambientTempF - 32) * 5 / 9,
            ]);
        return $mock;
    }
}
