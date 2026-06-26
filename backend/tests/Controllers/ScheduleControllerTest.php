<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Controllers\ScheduleController;
use HotTub\Services\SchedulerService;
use HotTub\Services\DtdtService;
use HotTub\Services\HeatTargetSettingsService;
use HotTub\Services\TimeConverter;
use PHPUnit\Framework\TestCase;

class ScheduleControllerTest extends TestCase
{
    private string $baseDir;
    private string $jobsDir;
    private string $stateDir;
    private MockCrontabAdapter $crontabAdapter;
    private SchedulerService $scheduler;
    private ScheduleController $controller;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir() . '/controller-test-' . uniqid();
        $this->jobsDir = $this->baseDir . '/scheduled-jobs';
        $this->stateDir = $this->baseDir . '/state';
        mkdir($this->jobsDir, 0755, true);
        mkdir($this->stateDir, 0755, true);

        $this->crontabAdapter = new MockCrontabAdapter();
        $this->scheduler = new SchedulerService(
            $this->jobsDir,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->crontabAdapter,
            null,
            null,
            null,
            $this->stateDir
        );

        $this->controller = new ScheduleController($this->scheduler);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->baseDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ========== POST /api/schedule Tests ==========

    public function testCreateReturns201OnSuccess(): void
    {
        $response = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('jobId', $response['body']);
        $this->assertEquals('heater-on', $response['body']['action']);
    }

    public function testCreateReturns400ForInvalidAction(): void
    {
        $response = $this->controller->create([
            'action' => 'invalid',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('Invalid action', $response['body']['error']);
    }

    // ========== PUT /api/schedule/{id}/reschedule Tests ==========

    private function createOneOff(float $tempF = 100.0): string
    {
        $job = $this->scheduler->scheduleJob(
            'heat-to-target',
            (new \DateTime('+3 hours'))->format(\DateTime::ATOM),
            recurring: false,
            params: ['target_temp_f' => $tempF]
        );
        return $job['jobId'];
    }

    public function testRescheduleMovesAOneOffTimeAndTempInPlace(): void
    {
        $jobId = $this->createOneOff(100.0);
        $newTime = (new \DateTime('+5 hours'))->format(\DateTime::ATOM);

        $response = $this->controller->reschedule($jobId, [
            'scheduledTime' => $newTime,
            'target_temp_f' => 104.5,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        // Same job id — the event was moved in place, not recreated.
        $this->assertEquals($jobId, $response['body']['job']['jobId']);
        $this->assertEquals(104.5, $response['body']['job']['params']['target_temp_f']);

        // Persisted to disk; the stored instant moved to the new time.
        $saved = json_decode(file_get_contents($this->jobsDir . '/' . $jobId . '.json'), true);
        $this->assertEquals(104.5, $saved['params']['target_temp_f']);
        $this->assertEquals(
            (new \DateTime($newTime))->getTimestamp(),
            (new \DateTime($saved['scheduledTime']))->getTimestamp()
        );
    }

    public function testRescheduleReturns404ForMissingJob(): void
    {
        $response = $this->controller->reschedule('job-does-not-exist', [
            'scheduledTime' => (new \DateTime('+5 hours'))->format(\DateTime::ATOM),
        ]);
        $this->assertEquals(404, $response['status']);
    }

    public function testRescheduleRejectsRecurringJobs(): void
    {
        $rec = $this->scheduler->scheduleJob(
            'heat-to-target',
            '06:55',
            recurring: true,
            params: ['target_temp_f' => 102.0],
            timezone: 'America/Los_Angeles'
        );
        $response = $this->controller->reschedule($rec['jobId'], [
            'scheduledTime' => (new \DateTime('+5 hours'))->format(\DateTime::ATOM),
        ]);
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('recurring', strtolower($response['body']['error']));
    }

    public function testRescheduleRejectsPastTime(): void
    {
        $jobId = $this->createOneOff();
        $response = $this->controller->reschedule($jobId, [
            'scheduledTime' => (new \DateTime('-1 hour'))->format(\DateTime::ATOM),
        ]);
        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('past', strtolower($response['body']['error']));
    }

    public function testRescheduleRejectsTempOutOfRange(): void
    {
        $jobId = $this->createOneOff();
        $response = $this->controller->reschedule($jobId, [
            'scheduledTime' => (new \DateTime('+5 hours'))->format(\DateTime::ATOM),
            'target_temp_f' => 200.0,
        ]);
        $this->assertEquals(400, $response['status']);
    }

    public function testRescheduleRequiresScheduledTime(): void
    {
        $jobId = $this->createOneOff();
        $response = $this->controller->reschedule($jobId, []);
        $this->assertEquals(400, $response['status']);
    }

    public function testCreateReturns400ForPastTime(): void
    {
        $response = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2020-01-01T06:30:00',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('past', strtolower($response['body']['error']));
    }

    public function testCreateReturns400ForMissingAction(): void
    {
        $response = $this->controller->create([
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testCreateReturns400ForMissingScheduledTime(): void
    {
        $response = $this->controller->create([
            'action' => 'heater-on',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // ========== Recurring Job Tests ==========

    public function testCreateRecurringJobReturns201(): void
    {
        $response = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '06:30',
            'recurring' => true,
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('jobId', $response['body']);
        $this->assertStringStartsWith('rec-', $response['body']['jobId']);
        $this->assertTrue($response['body']['recurring']);
    }

    public function testCreateOneOffJobReturnsRecurringFalse(): void
    {
        $response = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertArrayHasKey('recurring', $response['body']);
        $this->assertFalse($response['body']['recurring']);
    }

    public function testListIncludesRecurringFlag(): void
    {
        // Create one-off and recurring jobs
        $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);
        $this->controller->create([
            'action' => 'heater-off',
            'scheduledTime' => '18:00',
            'recurring' => true,
        ]);

        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertCount(2, $response['body']['jobs']);

        // Check that recurring flag is present on all jobs
        foreach ($response['body']['jobs'] as $job) {
            $this->assertArrayHasKey('recurring', $job);
        }
    }

    // ========== GET /api/schedule Tests ==========

    public function testListReturnsJobsArray(): void
    {
        // Create a job first
        $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);

        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('jobs', $response['body']);
        $this->assertCount(1, $response['body']['jobs']);
    }

    public function testListReturnsEmptyArrayWhenNoJobs(): void
    {
        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('jobs', $response['body']);
        $this->assertEmpty($response['body']['jobs']);
    }

    // ========== DELETE /api/schedule/{id} Tests ==========

    public function testCancelReturns200OnSuccess(): void
    {
        // Create a job first
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->cancel($jobId);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testCancelReturns404ForUnknownJob(): void
    {
        $response = $this->controller->cancel('job-nonexistent');

        $this->assertEquals(404, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('not found', strtolower($response['body']['error']));
    }

    public function testCancelActuallyRemovesJob(): void
    {
        // Create a job
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);
        $jobId = $createResponse['body']['jobId'];

        // Verify it exists
        $listBefore = $this->controller->list();
        $this->assertCount(1, $listBefore['body']['jobs']);

        // Cancel it
        $this->controller->cancel($jobId);

        // Verify it's gone
        $listAfter = $this->controller->list();
        $this->assertCount(0, $listAfter['body']['jobs']);
    }

    // ========== Skip/Unskip Tests ==========

    public function testSkipReturns200ForRecurringJob(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '06:30',
            'recurring' => true,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->skip($jobId);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testSkipReturns400ForNonRecurring(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->skip($jobId);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testSkipReturns400WhenAlreadySkipped(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '06:30',
            'recurring' => true,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $this->controller->skip($jobId);
        $response = $this->controller->skip($jobId);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testUnskipReturns200WhenSkipped(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '06:30',
            'recurring' => true,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $this->controller->skip($jobId);
        $response = $this->controller->unskip($jobId);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }

    public function testUnskipReturns400WhenNotSkipped(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '06:30',
            'recurring' => true,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->unskip($jobId);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // ========== PUT /api/schedule/{id}/target-temp Tests ==========

    public function testUpdateTargetTempReturns200OnSuccess(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => '2030-12-11T06:30:00',
            'target_temp_f' => 100,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->updateTargetTemp($jobId, ['target_temp_f' => 105]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(105.0, $response['body']['params']['target_temp_f']);
    }

    public function testUpdateTargetTempReturns400ForMissingField(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => '2030-12-11T06:30:00',
            'target_temp_f' => 100,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->updateTargetTemp($jobId, []);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testUpdateTargetTempReturns400ForNonHeatToTargetJob(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heater-on',
            'scheduledTime' => '2030-12-11T06:30:00',
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->updateTargetTemp($jobId, ['target_temp_f' => 105]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testUpdateTargetTempReturns404ForNonexistentJob(): void
    {
        $response = $this->controller->updateTargetTemp('job-nonexistent', ['target_temp_f' => 105]);

        $this->assertEquals(404, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testUpdateTargetTempReturns400ForOutOfRangeTemp(): void
    {
        $createResponse = $this->controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => '2030-12-11T06:30:00',
            'target_temp_f' => 100,
        ]);
        $jobId = $createResponse['body']['jobId'];

        $response = $this->controller->updateTargetTemp($jobId, ['target_temp_f' => 120]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // ========== DTDT Ready-By Integration Tests ==========

    public function testScheduleControllerUsesReadyByMode(): void
    {
        // Set up settings with ready_by mode
        $settingsFile = sys_get_temp_dir() . '/test_heat_target_' . uniqid() . '.json';
        $settings = new HeatTargetSettingsService($settingsFile);
        $settings->updateScheduleMode('ready_by');

        // Set up heating characteristics
        $charsFile = sys_get_temp_dir() . '/test_heating_chars_' . uniqid() . '.json';
        file_put_contents($charsFile, json_encode([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]));

        $dtdtService = new DtdtService(
            $this->scheduler,
            null, // TargetTemperatureService
            null, // Esp32CalibratedTemperatureService
            $charsFile
        );

        $controller = new ScheduleController($this->scheduler, $dtdtService, $settings);

        $response = $controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => '06:30-08:00',
            'recurring' => true,
            'target_temp_f' => 103,
        ]);

        $this->assertEquals(201, $response['status']);

        // Job file should use wake-up endpoint
        $jobFile = $this->jobsDir . '/' . $response['body']['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('/api/maintenance/dtdt-wakeup', $jobData['endpoint']);
        $this->assertEquals('06:30-08:00', $jobData['params']['ready_by_time']);

        // Cleanup
        @unlink($settingsFile);
        @unlink($charsFile);
    }

    public function testScheduleControllerNormalInStartAtMode(): void
    {
        // Set up settings with start_at mode (default)
        $settingsFile = sys_get_temp_dir() . '/test_heat_target_' . uniqid() . '.json';
        $settings = new HeatTargetSettingsService($settingsFile);

        $charsFile = sys_get_temp_dir() . '/test_heating_chars_' . uniqid() . '.json';
        file_put_contents($charsFile, json_encode([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'max_cooling_k' => 0.001,
        ]));

        $dtdtService = new DtdtService(
            $this->scheduler,
            null,
            null,
            $charsFile
        );

        $controller = new ScheduleController($this->scheduler, $dtdtService, $settings);

        $response = $controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => '06:30-08:00',
            'recurring' => true,
            'target_temp_f' => 103,
        ]);

        $this->assertEquals(201, $response['status']);

        // Job file should use standard endpoint (not wake-up)
        $jobFile = $this->jobsDir . '/' . $response['body']['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('/api/equipment/heat-to-target', $jobData['endpoint']);

        // Cleanup
        @unlink($settingsFile);
        @unlink($charsFile);
    }

    // ========== override-next ("adjust just the next run") Tests ==========

    /** Build a controller wired with DTDT + settings (chars under baseDir, auto-cleaned). */
    private function dtdtController(): array
    {
        $settingsFile = $this->baseDir . '/ht-' . uniqid() . '.json';
        $settings = new HeatTargetSettingsService($settingsFile);

        $charsFile = $this->baseDir . '/chars-' . uniqid() . '.json';
        file_put_contents($charsFile, json_encode([
            'heating_velocity_f_per_min' => 0.3,
            'startup_lag_minutes' => 10.0,
            'cooling_coefficient_k' => 0.0002,
            'max_cooling_k' => 0.001,
        ]));

        $dtdt = new DtdtService($this->scheduler, null, null, $charsFile);
        return [new ScheduleController($this->scheduler, $dtdt, $settings), $settings];
    }

    /**
     * A recurring heat-to-target parent at the current minute in the SYSTEM timezone
     * (the tz skipNextOccurrence uses), so the skip deterministically lands tomorrow
     * and the override one-off is always in the future.
     */
    private function createRecurringParent(ScheduleController $controller): string
    {
        $sysTz = TimeConverter::getSystemTimezone();
        $nowMinute = (new \DateTime('now', new \DateTimeZone($sysTz)))->format('H:i');
        $resp = $controller->create([
            'action' => 'heat-to-target',
            'scheduledTime' => $nowMinute,
            'recurring' => true,
            'target_temp_f' => 102,
            'timezone' => $sysTz,
        ]);
        return $resp['body']['jobId'];
    }

    /** Find override one-off jobs for a parent by scanning the jobs dir. */
    private function findOverrides(string $parentId): array
    {
        $out = [];
        foreach (glob($this->jobsDir . '/job-*.json') ?: [] as $f) {
            $d = json_decode(file_get_contents($f), true);
            if (is_array($d) && (($d['params']['override_of'] ?? null) === $parentId)) {
                $out[] = $d;
            }
        }
        return $out;
    }

    public function testOverrideNextSkipsParentAndCreatesStartAtOverride(): void
    {
        [$controller] = $this->dtdtController();
        $parentId = $this->createRecurringParent($controller);

        $resp = $controller->overrideNext($parentId, ['scheduledTime' => '08:00', 'target_temp_f' => 104]);
        $this->assertEquals(200, $resp['status']);

        // Parent's next occurrence is skipped.
        $this->assertTrue($this->scheduler->isSkipped($parentId));

        // Exactly one override one-off, pointing back at the parent, at 104°F, start-at endpoint.
        $overrides = $this->findOverrides($parentId);
        $this->assertCount(1, $overrides);
        $this->assertEquals(104.0, $overrides[0]['params']['target_temp_f']);
        $this->assertFalse($overrides[0]['recurring']);
        $this->assertEquals('/api/equipment/heat-to-target', $overrides[0]['endpoint']);
    }

    public function testOverrideNextIsIdempotentReplace(): void
    {
        [$controller] = $this->dtdtController();
        $parentId = $this->createRecurringParent($controller);

        $controller->overrideNext($parentId, ['scheduledTime' => '08:00', 'target_temp_f' => 104]);
        $controller->overrideNext($parentId, ['scheduledTime' => '09:00', 'target_temp_f' => 105]);

        $overrides = $this->findOverrides($parentId);
        $this->assertCount(1, $overrides); // replaced, not duplicated
        $this->assertEquals(105.0, $overrides[0]['params']['target_temp_f']);
    }

    public function testOverrideNextReadyByInheritsWakeupMode(): void
    {
        [$controller, $settings] = $this->dtdtController();
        $settings->updateScheduleMode('ready_by');
        $parentId = $this->createRecurringParent($controller);

        // Parent is a ready-by job (fires the wakeup endpoint).
        $this->assertArrayHasKey('ready_by_time', $this->scheduler->getJob($parentId)['params']);

        $resp = $controller->overrideNext($parentId, ['scheduledTime' => '08:00', 'target_temp_f' => 103]);
        $this->assertEquals(200, $resp['status']);

        $overrides = $this->findOverrides($parentId);
        $this->assertCount(1, $overrides);
        // Override inherits ready-by: fires the wakeup endpoint with the new ready_by_time.
        $this->assertEquals('/api/maintenance/dtdt-wakeup', $overrides[0]['endpoint']);
        $this->assertEquals('08:00', $overrides[0]['params']['ready_by_time']);
    }

    public function testOverrideNextRejectsNonRecurring(): void
    {
        [$controller] = $this->dtdtController();
        $oneOff = $controller->create([
            'action' => 'heater-on',
            'scheduledTime' => (new \DateTime('+2 hours'))->format(\DateTime::ATOM),
        ])['body']['jobId'];

        $resp = $controller->overrideNext($oneOff, ['scheduledTime' => '08:00', 'target_temp_f' => 104]);
        $this->assertEquals(400, $resp['status']);
    }

    public function testOverrideNextRejectsBadTemp(): void
    {
        [$controller] = $this->dtdtController();
        $parentId = $this->createRecurringParent($controller);

        $resp = $controller->overrideNext($parentId, ['scheduledTime' => '08:00', 'target_temp_f' => 120]);
        $this->assertEquals(400, $resp['status']);
    }

    public function testOverrideNextReturns404ForMissingJob(): void
    {
        [$controller] = $this->dtdtController();
        $resp = $controller->overrideNext('rec-doesnotexist', ['scheduledTime' => '08:00', 'target_temp_f' => 104]);
        $this->assertEquals(404, $resp['status']);
    }

    public function testClearOverrideRemovesOneOffAndUnskips(): void
    {
        [$controller] = $this->dtdtController();
        $parentId = $this->createRecurringParent($controller);

        $controller->overrideNext($parentId, ['scheduledTime' => '08:00', 'target_temp_f' => 104]);
        $this->assertCount(1, $this->findOverrides($parentId));
        $this->assertTrue($this->scheduler->isSkipped($parentId));

        $resp = $controller->clearOverride($parentId);
        $this->assertEquals(200, $resp['status']);
        $this->assertCount(0, $this->findOverrides($parentId)); // override gone
        $this->assertFalse($this->scheduler->isSkipped($parentId)); // back to normal daily
    }
}

/**
 * Mock crontab adapter for testing.
 */
class MockCrontabAdapter implements CrontabAdapterInterface
{
    /** @var array<string> */
    private array $entries = [];

    public function addEntry(string $entry): void
    {
        $this->entries[] = $entry;
    }

    public function removeByPattern(string $pattern): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            fn($entry) => strpos($entry, $pattern) === false
        ));
    }

    public function listEntries(): array
    {
        return $this->entries;
    }
}
