<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\SchedulerService;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SchedulerServiceTest extends TestCase
{
    private string $jobsDir;
    private string $cronRunnerPath;
    private string $apiBaseUrl;
    private MockCrontabAdapter $crontabAdapter;
    private SchedulerService $scheduler;

    protected function setUp(): void
    {
        $this->jobsDir = sys_get_temp_dir() . '/scheduler-test-' . uniqid();
        mkdir($this->jobsDir, 0755, true);

        $this->cronRunnerPath = '/var/www/backend/storage/bin/cron-runner.sh';
        $this->apiBaseUrl = 'https://example.com/tub/backend/public';
        $this->crontabAdapter = new MockCrontabAdapter();

        $this->scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter
        );
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $files = glob($this->jobsDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->jobsDir);
    }

    // ========== scheduleJob Tests ==========

    public function testScheduleJobCreatesJobFile(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->assertFileExists($jobFile);

        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('heater-on', $jobData['action']);
        $this->assertEquals('/api/equipment/heater/on', $jobData['endpoint']);
        $this->assertEquals($this->apiBaseUrl, $jobData['apiBaseUrl']);
    }

    public function testScheduleJobAddsCrontabEntry(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('30 6 11 12', $entries[0]); // minute hour day month
        $this->assertStringContainsString($result['jobId'], $entries[0]);
        $this->assertStringContainsString('HOTTUB:', $entries[0]);
    }

    public function testScheduleJobReturnsJobDetails(): void
    {
        $scheduledTime = '2030-12-11T06:30:00';
        $result = $this->scheduler->scheduleJob('heater-on', $scheduledTime);

        $this->assertArrayHasKey('jobId', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('scheduledTime', $result);
        $this->assertArrayHasKey('createdAt', $result);
        $this->assertEquals('heater-on', $result['action']);
        $this->assertStringStartsWith('job-', $result['jobId']);
    }

    public function testScheduleJobRejectsInvalidAction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid action');

        $this->scheduler->scheduleJob('invalid-action', '2030-12-11T06:30:00');
    }

    public function testScheduleJobRejectsPastTime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('past');

        $this->scheduler->scheduleJob('heater-on', '2020-01-01T06:30:00');
    }

    public function testScheduleJobHandlesTimezoneOffset(): void
    {
        // Schedule a time that includes timezone offset
        // This simulates a browser in PST sending a local time with offset
        $futureTimeWithOffset = '2030-12-11T06:30:00-08:00';

        $result = $this->scheduler->scheduleJob('heater-on', $futureTimeWithOffset);

        // Should accept the time (it's in the future)
        $this->assertArrayHasKey('jobId', $result);

        // The cron should be scheduled for the local time from the input
        // (assuming server cron runs in the same timezone as the user)
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('30 6 11 12', $entries[0]); // 06:30 in PST
    }

    public function testScheduleJobGeneratesUniqueJobIds(): void
    {
        $result1 = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $result2 = $this->scheduler->scheduleJob('heater-off', '2030-12-11T07:30:00');

        $this->assertNotEquals($result1['jobId'], $result2['jobId']);
    }

    public function testScheduleJobMapsActionsToEndpoints(): void
    {
        $actions = [
            'heater-on' => '/api/equipment/heater/on',
            'heater-off' => '/api/equipment/heater/off',
            'pump-run' => '/api/equipment/pump/run',
        ];

        foreach ($actions as $action => $expectedEndpoint) {
            $result = $this->scheduler->scheduleJob($action, '2030-12-11T06:30:00');
            $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
            $jobData = json_decode(file_get_contents($jobFile), true);

            $this->assertEquals($expectedEndpoint, $jobData['endpoint'], "Action $action should map to $expectedEndpoint");
        }
    }

    public function testApiBaseUrlWithTrailingSlashDoesNotCreateDoubleSlash(): void
    {
        // Bug: When apiBaseUrl has a trailing slash (e.g., from dirname('/index.php') returning '/'),
        // concatenating with endpoint creates double slashes like '//api/equipment/heater/on'
        $schedulerWithTrailingSlash = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            'http://localhost:8080/',  // Trailing slash - the bug condition
            $this->crontabAdapter
        );

        $result = $schedulerWithTrailingSlash->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        // The apiBaseUrl should be normalized to NOT have a trailing slash
        $this->assertEquals('http://localhost:8080', $jobData['apiBaseUrl'], 'apiBaseUrl should not have trailing slash');

        // Verify the full URL would be correct (no double slash)
        $fullUrl = $jobData['apiBaseUrl'] . $jobData['endpoint'];
        $this->assertStringNotContainsString('//', parse_url($fullUrl, PHP_URL_PATH), 'Path should not contain double slashes');
    }

    // ========== listJobs Tests ==========

    public function testListJobsReturnsEmptyArrayWhenNoJobs(): void
    {
        $jobs = $this->scheduler->listJobs();

        $this->assertIsArray($jobs);
        $this->assertEmpty($jobs);
    }

    public function testListJobsReturnsAllPendingJobs(): void
    {
        $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $this->scheduler->scheduleJob('heater-off', '2030-12-12T18:00:00');

        $jobs = $this->scheduler->listJobs();

        $this->assertCount(2, $jobs);
    }

    public function testListJobsSortsByScheduledTime(): void
    {
        $this->scheduler->scheduleJob('heater-off', '2030-12-12T18:00:00'); // Later
        $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');  // Earlier

        $jobs = $this->scheduler->listJobs();

        $this->assertCount(2, $jobs);
        $this->assertEquals('heater-on', $jobs[0]['action']);
        $this->assertEquals('heater-off', $jobs[1]['action']);
    }

    // ========== cancelJob Tests ==========

    public function testCancelJobRemovesCrontabEntry(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $this->assertCount(1, $this->crontabAdapter->listEntries());

        $this->scheduler->cancelJob($result['jobId']);

        $this->assertCount(0, $this->crontabAdapter->listEntries());
    }

    public function testCancelJobDeletesJobFile(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->assertFileExists($jobFile);

        $this->scheduler->cancelJob($result['jobId']);

        $this->assertFileDoesNotExist($jobFile);
    }

    public function testCancelJobThrowsForUnknownJob(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('not found');

        $this->scheduler->cancelJob('job-nonexistent');
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
