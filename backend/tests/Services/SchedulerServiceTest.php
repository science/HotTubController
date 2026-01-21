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
        // Use a time with explicit timezone to ensure consistent results
        // The cron will be scheduled in system timezone
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();
        $inputTime = '2030-12-11T06:30:00+00:00'; // UTC input

        $result = $this->scheduler->scheduleJob('heater-on', $inputTime);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // Calculate expected cron time based on system timezone
        $expected = new \DateTime($inputTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));
        $expectedCronTime = sprintf(
            '%d %d %d %d',
            (int) $expected->format('i'),
            (int) $expected->format('G'),
            (int) $expected->format('j'),
            (int) $expected->format('n')
        );

        $this->assertStringContainsString($expectedCronTime, $entries[0], "Cron should be in system timezone ($systemTz)");
        $this->assertStringContainsString($result['jobId'], $entries[0]);
        $this->assertStringContainsString('HOTTUB:', $entries[0]);
    }

    public function testScheduleJobIncludesActionLabelInCrontabComment(): void
    {
        // Test that crontab comments include descriptive action labels for easier identification
        $testCases = [
            'heater-on' => ':ON',
            'heater-off' => ':OFF',
            'pump-run' => ':PUMP',
        ];

        foreach ($testCases as $action => $expectedLabel) {
            $result = $this->scheduler->scheduleJob($action, '2030-12-11T06:30:00');
            $entries = $this->crontabAdapter->listEntries();
            $lastEntry = end($entries);

            $this->assertStringContainsString(
                'HOTTUB:' . $result['jobId'] . $expectedLabel,
                $lastEntry,
                "Crontab comment for '$action' should include '$expectedLabel' label"
            );
        }
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

        // Cron runs in server-local time. We need to verify the cron expression
        // is correct for the server's SYSTEM timezone, not the client's.
        // 06:30 PST (UTC-8) = 14:30 UTC
        // The cron expression should be generated in system timezone
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // The system timezone determines what the cron hour should be
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();
        $utcTime = new \DateTime('2030-12-11T14:30:00', new \DateTimeZone('UTC'));
        $utcTime->setTimezone(new \DateTimeZone($systemTz));
        $expectedHour = (int) $utcTime->format('G');
        $expectedMinute = (int) $utcTime->format('i');
        $expectedDay = (int) $utcTime->format('j');
        $expectedMonth = (int) $utcTime->format('n');

        $expectedCronTime = sprintf('%d %d %d %d', $expectedMinute, $expectedHour, $expectedDay, $expectedMonth);
        $this->assertStringContainsString($expectedCronTime, $entries[0], "Cron should be in system timezone ($systemTz)");
    }

    /**
     * Test that timezone conversion works correctly when client and server are in different timezones.
     *
     * This is the core timezone bug test:
     * - Client in Los Angeles (PST/UTC-8) schedules 6:30 AM local time
     * - Cron should run at the equivalent time in the SYSTEM timezone
     *
     * NOTE: Uses actual system timezone, not PHP's default timezone,
     * because cron runs in the OS timezone.
     */
    public function testScheduleJobConvertsClientTimezoneToServerTimezoneForCron(): void
    {
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();

        // Client in Los Angeles (UTC-8) wants 6:30 AM their time on Dec 11
        // 6:30 AM PST = 14:30 UTC
        $clientTime = '2030-12-11T06:30:00-08:00';

        $result = $this->scheduler->scheduleJob('heater-on', $clientTime);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // Calculate expected cron time in system timezone
        $expected = new \DateTime($clientTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));
        $expectedCronTime = sprintf(
            '%d %d %d %d',
            (int) $expected->format('i'),
            (int) $expected->format('G'),
            (int) $expected->format('j'),
            (int) $expected->format('n')
        );

        $this->assertStringContainsString(
            $expectedCronTime,
            $entries[0],
            "Cron should run at {$expected->format('g:i A')} system time ($systemTz), which equals 6:30 AM client time (PST)"
        );
    }

    /**
     * Test timezone conversion across day boundaries.
     *
     * Client schedules 11:30 PM PST on Dec 10 = 7:30 AM UTC Dec 11
     * The cron time depends on system timezone, which may or may not cross a day boundary.
     */
    public function testScheduleJobHandlesTimezoneDayBoundary(): void
    {
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();

        // Client in PST (UTC-8) schedules 11:30 PM Dec 10
        // 23:30 PST Dec 10 = 07:30 UTC Dec 11
        $clientTime = '2030-12-10T23:30:00-08:00';

        $result = $this->scheduler->scheduleJob('heater-on', $clientTime);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // Calculate expected cron time in system timezone
        $expected = new \DateTime($clientTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));
        $expectedCronTime = sprintf(
            '%d %d %d %d',
            (int) $expected->format('i'),
            (int) $expected->format('G'),
            (int) $expected->format('j'),
            (int) $expected->format('n')
        );

        $this->assertStringContainsString(
            $expectedCronTime,
            $entries[0],
            "Cron should run at {$expected->format('g:i A')} on {$expected->format('M j')} system time ($systemTz) when client scheduled 11:30 PM Dec 10 PST"
        );
    }

    /**
     * Test that API returns times in UTC for consistent client display.
     */
    public function testScheduleJobReturnsTimeInUtc(): void
    {
        // Client sends time with offset
        $clientTime = '2030-12-11T06:30:00-08:00';

        $result = $this->scheduler->scheduleJob('heater-on', $clientTime);

        // The scheduledTime should be returned in UTC (with +00:00 or Z suffix)
        $this->assertStringContainsString(
            '2030-12-11T14:30:00',
            $result['scheduledTime'],
            'scheduledTime should be converted to UTC (14:30 UTC = 06:30 PST)'
        );

        // Should end with UTC indicator
        $this->assertTrue(
            str_ends_with($result['scheduledTime'], '+00:00') ||
            str_ends_with($result['scheduledTime'], 'Z'),
            'scheduledTime should have UTC timezone indicator'
        );
    }

    /**
     * Test that recurring jobs with timezone offset return UTC time.
     *
     * New behavior: Recurring jobs accept "HH:MM+/-HH:MM" format and return UTC.
     */
    public function testScheduleRecurringJobWithTimezoneReturnsUtcTime(): void
    {
        // Recurring job with timezone offset: 6:30 AM Pacific = 14:30 UTC
        $result = $this->scheduler->scheduleJob('heater-on', '06:30-08:00', recurring: true);

        // Should return time in UTC format
        $this->assertEquals('14:30:00+00:00', $result['scheduledTime']);
        $this->assertTrue($result['recurring']);
    }

    /**
     * Test that recurring jobs convert to system timezone for cron.
     *
     * NOTE: Uses actual system timezone, not PHP's default timezone,
     * because cron runs in the OS timezone.
     */
    public function testScheduleRecurringJobConvertsToServerTimezoneForCron(): void
    {
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();

        // Client in Pacific (UTC-8) wants 6:30 AM their time
        // 6:30 AM PST = 14:30 UTC
        $result = $this->scheduler->scheduleJob('heater-on', '06:30-08:00', recurring: true);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);

        // Calculate expected cron time in system timezone
        // Use reference date for conversion (same as TimeConverter)
        $expected = new \DateTime('2030-01-01T06:30:00-08:00');
        $expected->setTimezone(new \DateTimeZone($systemTz));
        $expectedCronTime = sprintf(
            '%d %d * * *',
            (int) $expected->format('i'),
            (int) $expected->format('G')
        );

        $this->assertStringContainsString(
            $expectedCronTime,
            $entries[0],
            "Recurring cron should be {$expected->format('g:i A')} system time ($systemTz) when client is Pacific"
        );
    }

    /**
     * Test backward compatibility: bare HH:MM format still works (assumes server timezone).
     */
    public function testScheduleRecurringJobBareTimeAssumesServerTimezone(): void
    {
        // For backward compatibility, bare "06:30" should work and assume server timezone
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        // Should store as server-local time (no UTC conversion)
        $this->assertEquals('06:30', $result['scheduledTime']);
        $this->assertTrue($result['recurring']);

        // Cron should be 6:30 server time
        $entries = $this->crontabAdapter->listEntries();
        $this->assertStringContainsString('30 6 * * *', $entries[0]);
    }

    // ========== Recurring Job Tests ==========

    public function testScheduleRecurringJobCreatesDailyCronExpression(): void
    {
        // Recurring jobs should use * * * for day, month, day-of-week (daily at same time)
        // Using bare time format for backward compatibility test
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('30 6 * * *', $entries[0]); // minute hour * * *
    }

    public function testScheduleRecurringJobUsesRecPrefix(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $this->assertStringStartsWith('rec-', $result['jobId']);
    }

    public function testScheduleRecurringJobIncludesDailySuffix(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $entries = $this->crontabAdapter->listEntries();
        $this->assertStringContainsString(':DAILY', $entries[0]);
        $this->assertStringContainsString('HOTTUB:' . $result['jobId'] . ':ON:DAILY', $entries[0]);
    }

    public function testScheduleOneOffJobIncludesOnceSuffix(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $entries = $this->crontabAdapter->listEntries();
        $this->assertStringContainsString(':ONCE', $entries[0]);
        $this->assertStringContainsString('HOTTUB:' . $result['jobId'] . ':ON:ONCE', $entries[0]);
    }

    public function testScheduleRecurringJobStoresTimeOnly(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $this->assertEquals('06:30', $result['scheduledTime']);
        $this->assertTrue($result['recurring']);

        // Verify job file also has time-only
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('06:30', $jobData['scheduledTime']);
        $this->assertTrue($jobData['recurring']);
    }

    public function testScheduleRecurringJobReturnsRecurringFlag(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $this->assertArrayHasKey('recurring', $result);
        $this->assertTrue($result['recurring']);
    }

    public function testScheduleOneOffJobReturnsRecurringFlagFalse(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $this->assertArrayHasKey('recurring', $result);
        $this->assertFalse($result['recurring']);
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

    public function testListJobsReturnsBothOneOffAndRecurringJobs(): void
    {
        $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00'); // one-off
        $this->scheduler->scheduleJob('heater-off', '18:00', recurring: true); // recurring

        $jobs = $this->scheduler->listJobs();

        $this->assertCount(2, $jobs);
    }

    public function testListJobsIncludesRecurringFlag(): void
    {
        $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00'); // one-off
        $this->scheduler->scheduleJob('heater-off', '18:00', recurring: true); // recurring

        $jobs = $this->scheduler->listJobs();

        // Find the one-off job
        $oneOff = array_filter($jobs, fn($j) => str_starts_with($j['jobId'], 'job-'));
        $oneOff = array_values($oneOff)[0];
        $this->assertArrayHasKey('recurring', $oneOff);
        $this->assertFalse($oneOff['recurring']);

        // Find the recurring job
        $recurring = array_filter($jobs, fn($j) => str_starts_with($j['jobId'], 'rec-'));
        $recurring = array_values($recurring)[0];
        $this->assertArrayHasKey('recurring', $recurring);
        $this->assertTrue($recurring['recurring']);
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

    public function testListJobsCleansUpOrphanedCrontabEntries(): void
    {
        // Simulate an orphaned crontab entry (crontab entry exists but no job file)
        // This can happen if:
        // 1. Job file was manually deleted
        // 2. Crash during cron-runner.sh execution
        // 3. Prior installation left stale entries
        $orphanedJobId = 'job-deadbeef';  // Use hex characters like real job IDs
        $orphanedCronEntry = "30 6 11 12 * '/path/to/cron-runner.sh' '$orphanedJobId' # HOTTUB:$orphanedJobId:ON:ONCE";
        $this->crontabAdapter->addEntry($orphanedCronEntry);

        // Create a legitimate job (this one has both crontab entry and job file)
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T07:30:00');

        // Before calling listJobs, we have 2 crontab entries
        $this->assertCount(2, $this->crontabAdapter->listEntries());

        // listJobs should detect the orphan and clean it up
        $jobs = $this->scheduler->listJobs();

        // Should only return the legitimate job
        $this->assertCount(1, $jobs);
        $this->assertEquals($result['jobId'], $jobs[0]['jobId']);

        // The orphaned crontab entry should have been removed
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringNotContainsString($orphanedJobId, $entries[0]);
    }

    public function testListJobsCleansUpOrphanedRecurringCrontabEntries(): void
    {
        // Orphaned recurring job entry
        $orphanedJobId = 'rec-deadbeef';
        $orphanedCronEntry = "30 6 * * * '/path/to/cron-runner.sh' '$orphanedJobId' # HOTTUB:$orphanedJobId:ON:DAILY";
        $this->crontabAdapter->addEntry($orphanedCronEntry);

        // Create a legitimate recurring job
        $result = $this->scheduler->scheduleJob('heater-on', '07:30', recurring: true);

        // Before calling listJobs, we have 2 crontab entries
        $this->assertCount(2, $this->crontabAdapter->listEntries());

        // listJobs should detect the orphan and clean it up
        $jobs = $this->scheduler->listJobs();

        // Should only return the legitimate job
        $this->assertCount(1, $jobs);
        $this->assertEquals($result['jobId'], $jobs[0]['jobId']);

        // The orphaned crontab entry should have been removed
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringNotContainsString($orphanedJobId, $entries[0]);
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

    public function testCancelRecurringJob(): void
    {
        $result = $this->scheduler->scheduleJob('heater-on', '06:30', recurring: true);
        $this->assertCount(1, $this->crontabAdapter->listEntries());

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->assertFileExists($jobFile);

        $this->scheduler->cancelJob($result['jobId']);

        $this->assertCount(0, $this->crontabAdapter->listEntries());
        $this->assertFileDoesNotExist($jobFile);
    }

    // ========== Non-HOTTUB Entry Preservation Tests ==========

    public function testScheduleJobPreservesNonHottubCrontabEntries(): void
    {
        // Simulate existing crontab with non-HOTTUB entries (ACME, etc.)
        $this->crontabAdapter->setInitialEntries([
            'SHELL="/bin/bash"',
            '30 1 * * 0 "/home/misuse/.acme.sh"/acme.sh --cron --home "/home/misuse/.acme.sh" > "/home/misuse/logs/acme.sh.log"',
            '30 2 * 1,7 * /home/misuse/bin/trim_logs.sh > /home/misuse/logs/trim_logs.sh.log',
        ]);

        // Schedule a hot tub job
        $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        // Verify all entries are preserved
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(4, $entries, 'Should have 3 original + 1 new HOTTUB entry');
        $this->assertStringContainsString('SHELL=', $entries[0]);
        $this->assertStringContainsString('acme.sh', $entries[1]);
        $this->assertStringContainsString('trim_logs.sh', $entries[2]);
        $this->assertStringContainsString('HOTTUB:', $entries[3]);
    }

    public function testCancelJobPreservesNonHottubCrontabEntries(): void
    {
        // Simulate existing crontab with non-HOTTUB entries plus a HOTTUB job
        $this->crontabAdapter->setInitialEntries([
            'SHELL="/bin/bash"',
            '30 1 * * 0 "/home/misuse/.acme.sh"/acme.sh --cron > "/home/misuse/logs/acme.sh.log"',
            '30 2 * 1,7 * /home/misuse/bin/trim_logs.sh > /home/misuse/logs/trim_logs.sh.log',
        ]);

        // Schedule a job first (so we have one to cancel)
        $result = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        // Verify we have 4 entries
        $this->assertCount(4, $this->crontabAdapter->listEntries());

        // Cancel the HOTTUB job
        $this->scheduler->cancelJob($result['jobId']);

        // Verify non-HOTTUB entries are still there
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(3, $entries, 'Should still have 3 non-HOTTUB entries');
        $this->assertStringContainsString('SHELL=', $entries[0]);
        $this->assertStringContainsString('acme.sh', $entries[1]);
        $this->assertStringContainsString('trim_logs.sh', $entries[2]);
    }

    public function testListJobsPreservesNonHottubCrontabEntriesWhenCleaningOrphans(): void
    {
        // This tests the scenario where orphaned HOTTUB entries are cleaned up
        // but non-HOTTUB entries should be preserved

        // Simulate existing crontab with non-HOTTUB entries plus an orphaned HOTTUB entry
        $this->crontabAdapter->setInitialEntries([
            'SHELL="/bin/bash"',
            '30 1 * * 0 "/home/misuse/.acme.sh"/acme.sh --cron > "/home/misuse/logs/acme.sh.log"',
            '0 6 12 12 * \'/path/to/cron-runner.sh\' \'job-deadbeef\' # HOTTUB:job-deadbeef:ON',
        ]);

        // Call listJobs - this should clean up the orphan (no job file exists)
        $jobs = $this->scheduler->listJobs();

        // Should return no jobs (the only HOTTUB entry was orphaned)
        $this->assertEmpty($jobs);

        // But non-HOTTUB entries should still be there
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(2, $entries, 'Should still have 2 non-HOTTUB entries after cleanup');
        $this->assertStringContainsString('SHELL=', $entries[0]);
        $this->assertStringContainsString('acme.sh', $entries[1]);
    }

    public function testMultipleScheduleAndCancelOperationsPreserveNonHottubEntries(): void
    {
        // Simulate a full workflow: existing crontab, schedule multiple jobs, cancel some
        $this->crontabAdapter->setInitialEntries([
            'SHELL="/bin/bash"',
            '30 1 * * 0 "/home/misuse/.acme.sh"/acme.sh --cron > "/home/misuse/logs/acme.sh.log"',
            '30 2 * 1,7 * /home/misuse/bin/trim_logs.sh > /home/misuse/logs/trim_logs.sh.log',
        ]);

        // Schedule multiple jobs
        $job1 = $this->scheduler->scheduleJob('heater-on', '2030-12-11T06:00:00');
        $job2 = $this->scheduler->scheduleJob('heater-off', '2030-12-11T06:45:00');
        $job3 = $this->scheduler->scheduleJob('pump-run', '2030-12-11T12:00:00');

        // Verify we have 6 entries (3 original + 3 HOTTUB)
        $this->assertCount(6, $this->crontabAdapter->listEntries());

        // Cancel one job
        $this->scheduler->cancelJob($job1['jobId']);

        // Should have 5 entries (3 original + 2 HOTTUB)
        $this->assertCount(5, $this->crontabAdapter->listEntries());

        // Cancel another job
        $this->scheduler->cancelJob($job2['jobId']);

        // Should have 4 entries (3 original + 1 HOTTUB)
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(4, $entries);

        // Verify non-HOTTUB entries are still intact
        $this->assertStringContainsString('SHELL=', $entries[0]);
        $this->assertStringContainsString('acme.sh', $entries[1]);
        $this->assertStringContainsString('trim_logs.sh', $entries[2]);
        $this->assertStringContainsString('HOTTUB:', $entries[3]);
    }

    // ========== Healthchecks.io Integration Tests ==========

    public function testScheduleJobCreatesHealthCheckWhenEnabled(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null, // TimeConverter
            $healthchecksClient
        );

        $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $checks = $healthchecksClient->getCreatedChecks();
        $this->assertCount(1, $checks, 'Should create one health check');
        $this->assertStringContainsString('job-', $checks[0]['name']);
    }

    public function testScheduleJobPingsHealthCheckToArm(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $pinged = $healthchecksClient->getPingedUrls();
        $this->assertCount(1, $pinged, 'Should ping health check once to arm it');
        $this->assertStringContainsString('hc-ping.com', $pinged[0]);
    }

    public function testScheduleJobStoresHealthCheckUuidInJobFile(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData);
        $this->assertEquals('mock-uuid-0', $jobData['healthcheckUuid']);
    }

    public function testScheduleJobWorksWhenHealthchecksDisabled(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: false);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        // Job should still be created successfully
        $this->assertArrayHasKey('jobId', $result);

        // Job file should NOT have healthcheckUuid (or it should be null)
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertTrue(
            !isset($jobData['healthcheckUuid']) || $jobData['healthcheckUuid'] === null,
            'Job file should not have healthcheckUuid when monitoring disabled'
        );

        // No API calls should be made
        $this->assertEmpty($healthchecksClient->getCreatedChecks());
        $this->assertEmpty($healthchecksClient->getPingedUrls());
    }

    public function testScheduleJobContinuesWhenHealthchecksFails(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $healthchecksClient->setShouldFailCreate(true);

        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        // Job should still be created even if healthchecks fails
        $result = $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $this->assertArrayHasKey('jobId', $result);

        // Cron should still be scheduled
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('HOTTUB:', $entries[0]);
    }

    public function testScheduleJobCreatesCorrectCronSchedule(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        // Schedule a job 2 hours from now
        $twoHoursFromNow = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+2 hours');
        $scheduler->scheduleJob('heater-on', $twoHoursFromNow->format(\DateTime::ATOM));

        $checks = $healthchecksClient->getCreatedChecks();
        $this->assertCount(1, $checks);

        // Should have a cron schedule (not timeout)
        $this->assertArrayHasKey('schedule', $checks[0]);

        // Schedule should contain the correct minute and hour
        $expectedMinute = (int) $twoHoursFromNow->format('i');
        $expectedHour = (int) $twoHoursFromNow->format('G');
        $this->assertStringContainsString("$expectedMinute $expectedHour", $checks[0]['schedule'],
            "Schedule should contain '$expectedMinute $expectedHour' for the scheduled time");
    }

    public function testScheduleJobWithoutHealthchecksClientStillWorks(): void
    {
        // Test backward compatibility: no healthchecks client = works as before
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter
        );

        $result = $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        $this->assertArrayHasKey('jobId', $result);

        // Cron should be scheduled
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
    }

    public function testCancelJobDeletesHealthCheck(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');
        $scheduler->cancelJob($result['jobId']);

        $deleted = $healthchecksClient->getDeletedUuids();
        $this->assertCount(1, $deleted);
        $this->assertEquals('mock-uuid-0', $deleted[0]);
    }

    public function testOneOffJobCreatesScheduleBasedHealthCheck(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        // Schedule a job for a specific date/time
        $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00Z');

        $checks = $healthchecksClient->getCreatedChecks();
        $this->assertCount(1, $checks, 'Should create one health check');

        // Check should have schedule (cron expression), not timeout
        $this->assertArrayHasKey('schedule', $checks[0]);
        $this->assertStringContainsString('30 6 11 12', $checks[0]['schedule'],
            'Schedule should be cron expression for Dec 11 at 6:30');

        // Check name should include job ID, action, and ONCE
        $this->assertStringContainsString('job-', $checks[0]['name']);
        $this->assertStringContainsString('heater-on', $checks[0]['name']);
        $this->assertStringContainsString('ONCE', $checks[0]['name']);
    }

    public function testRecurringJobStoresPingUrl(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        // Recurring jobs need ping_url for cron-runner.sh
        $this->assertArrayHasKey('healthcheckPingUrl', $jobData);
        $this->assertStringContainsString('hc-ping.com', $jobData['healthcheckPingUrl']);
    }

    public function testRecurringJobCreatesScheduledHealthCheck(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $checks = $healthchecksClient->getCreatedChecks();
        $this->assertCount(1, $checks, 'Should create one health check');
        $this->assertStringContainsString('rec-', $checks[0]['name']);
        $this->assertStringContainsString('heater-on', $checks[0]['name']);
        $this->assertStringContainsString('DAILY', $checks[0]['name']);
        $this->assertStringContainsString('30 6', $checks[0]['schedule']);
        $this->assertEquals('UTC', $checks[0]['timezone']);
    }

    public function testRecurringJobWithTimezoneCreatesUtcSchedule(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        // 06:30 Pacific (UTC-8) = 14:30 UTC
        $scheduler->scheduleJob('heater-on', '06:30-08:00', recurring: true);

        $checks = $healthchecksClient->getCreatedChecks();
        $this->assertCount(1, $checks);
        $this->assertStringContainsString('30 14', $checks[0]['schedule'],
            'Schedule should be 14:30 UTC (converted from 06:30 Pacific)');
    }

    public function testRecurringJobPingsHealthCheckToArm(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $pinged = $healthchecksClient->getPingedUrls();
        $this->assertCount(1, $pinged, 'Should ping health check once to arm it');
        $this->assertStringContainsString('hc-ping.com', $pinged[0]);
    }

    public function testRecurringJobStoresHealthCheckUuid(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData);
        $this->assertEquals('mock-uuid-0', $jobData['healthcheckUuid']);
    }

    public function testCancelRecurringJobDeletesHealthCheck(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);
        $scheduler->cancelJob($result['jobId']);

        $deleted = $healthchecksClient->getDeletedUuids();
        $this->assertCount(1, $deleted);
        $this->assertEquals('mock-uuid-0', $deleted[0]);
    }

    public function testRecurringJobContinuesWhenHealthchecksFails(): void
    {
        $healthchecksClient = new MockHealthchecksClient(enabled: true);
        $healthchecksClient->setShouldFailCreate(true);

        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $this->crontabAdapter,
            null,
            $healthchecksClient
        );

        // Job should still be created even if healthchecks fails
        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $this->assertArrayHasKey('jobId', $result);

        // Cron should still be scheduled
        $entries = $this->crontabAdapter->listEntries();
        $this->assertCount(1, $entries);
        $this->assertStringContainsString('HOTTUB:', $entries[0]);
    }

    // ========== Heat-to-Target Params Tests ==========

    public function testScheduleOneOffHeatToTargetStoresParams(): void
    {
        $result = $this->scheduler->scheduleJob('heat-to-target', '2030-12-11T06:30:00', false, ['target_temp_f' => 103]);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('params', $jobData);
        $this->assertEquals(103, $jobData['params']['target_temp_f']);
    }

    public function testScheduleRecurringHeatToTargetStoresParams(): void
    {
        // Bug #2: Recurring jobs should store params but the scheduleRecurringJob method
        // doesn't accept or store the params argument
        $result = $this->scheduler->scheduleJob('heat-to-target', '06:30', true, ['target_temp_f' => 102.5]);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('params', $jobData, 'Recurring heat-to-target jobs should store params');
        $this->assertEquals(102.5, $jobData['params']['target_temp_f'], 'Target temp should be stored');
    }

    public function testListJobsReturnsParamsForHeatToTargetJobs(): void
    {
        // Schedule both one-off and recurring heat-to-target jobs
        $this->scheduler->scheduleJob('heat-to-target', '2030-12-11T06:30:00', false, ['target_temp_f' => 103]);
        $this->scheduler->scheduleJob('heat-to-target', '07:30', true, ['target_temp_f' => 102.5]);

        $jobs = $this->scheduler->listJobs();

        $this->assertCount(2, $jobs);

        // One-off job should have params
        $oneOff = array_values(array_filter($jobs, fn($j) => str_starts_with($j['jobId'], 'job-')))[0];
        $this->assertArrayHasKey('params', $oneOff, 'One-off job should have params');
        $this->assertEquals(103, $oneOff['params']['target_temp_f']);

        // Recurring job should also have params
        $recurring = array_values(array_filter($jobs, fn($j) => str_starts_with($j['jobId'], 'rec-')))[0];
        $this->assertArrayHasKey('params', $recurring, 'Recurring job should have params');
        $this->assertEquals(102.5, $recurring['params']['target_temp_f']);
    }

    // ========== Bug Reproduction Test ==========

    /**
     * Test demonstrating the bug where crontab -l failure wipes entire crontab.
     *
     * BUG: If crontab -l fails (returns non-zero exit code), listEntries() returns
     * an empty array. Then addEntry() writes only the new entry, wiping everything!
     *
     * This test uses a realistic mock that simulates the real CrontabAdapter behavior.
     */
    public function testBugCrontabListFailureWipesEntireCrontab(): void
    {
        // Use the realistic mock that simulates full crontab rewrite behavior
        $realisticAdapter = new RealisticMockCrontabAdapter();

        $scheduler = new SchedulerService(
            $this->jobsDir,
            $this->cronRunnerPath,
            $this->apiBaseUrl,
            $realisticAdapter
        );

        // Simulate existing crontab with ACME jobs, etc.
        $realisticAdapter->setInitialEntries([
            'SHELL="/bin/bash"',
            '30 1 * * 0 "/home/misuse/.acme.sh"/acme.sh --cron > /dev/null',
            '30 2 * 1,7 * /home/misuse/bin/trim_logs.sh > /dev/null',
        ]);

        // Verify we have 3 entries before
        $this->assertCount(3, $realisticAdapter->listEntries());

        // Now simulate a transient failure where crontab -l fails ONCE
        // This could happen due to a brief lock, permission issue, etc.
        $realisticAdapter->setTransientFailures(1);

        // Schedule a HOTTUB job - this will trigger the bug
        $scheduler->scheduleJob('heater-on', '2030-12-11T06:30:00');

        // BUG: All non-HOTTUB entries are now GONE!
        $entries = $realisticAdapter->listEntries();

        // This assertion FAILS - demonstrating the bug
        // We expect 4 entries (3 original + 1 HOTTUB), but we only get 1
        $this->assertCount(
            1,  // BUG: We only have the new HOTTUB entry
            $entries,
            'BUG DEMONSTRATED: Transient crontab -l failure wiped all existing entries!'
        );

        // The only entry is the new HOTTUB job - everything else is gone
        $this->assertStringContainsString('HOTTUB:', $entries[0]);
    }
}

/**
 * Mock crontab adapter for testing.
 * This simulates the behavior of the real CrontabAdapter more closely.
 */
class MockCrontabAdapter implements CrontabAdapterInterface
{
    /** @var array<string> */
    private array $entries = [];

    /** @var bool Simulate crontab -l failure */
    private bool $simulateListFailure = false;

    /**
     * Pre-populate with initial entries (simulating existing crontab).
     *
     * @param array<string> $entries
     */
    public function setInitialEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    /**
     * Simulate crontab -l failure (returns empty array).
     */
    public function setSimulateListFailure(bool $fail): void
    {
        $this->simulateListFailure = $fail;
    }

    public function addEntry(string $entry): void
    {
        // Simulates the real behavior: read all, append, write all
        $this->entries[] = $entry;
    }

    public function removeByPattern(string $pattern): void
    {
        // Simulates grep -v behavior: remove lines containing the pattern
        $this->entries = array_values(array_filter(
            $this->entries,
            fn($entry) => strpos($entry, $pattern) === false
        ));
    }

    public function listEntries(): array
    {
        if ($this->simulateListFailure) {
            return [];  // Simulates crontab -l returning non-zero
        }
        return $this->entries;
    }
}

/**
 * Mock crontab adapter that more closely simulates real CrontabAdapter behavior.
 * The real CrontabAdapter reads the full crontab, appends/modifies, and rewrites all.
 */
class RealisticMockCrontabAdapter implements CrontabAdapterInterface
{
    /** @var array<string> */
    private array $entries = [];

    /** @var bool Simulate crontab -l failure */
    private bool $simulateListFailure = false;

    /** @var int Count of times we should fail before succeeding */
    private int $failuresRemaining = 0;

    public function setInitialEntries(array $entries): void
    {
        $this->entries = $entries;
    }

    public function setSimulateListFailure(bool $fail): void
    {
        $this->simulateListFailure = $fail;
    }

    /**
     * Simulate transient failure: fail N times, then succeed.
     */
    public function setTransientFailures(int $count): void
    {
        $this->failuresRemaining = $count;
    }

    public function addEntry(string $entry): void
    {
        // This is the critical difference: real adapter reads all entries
        // then REWRITES THE ENTIRE CRONTAB
        $currentEntries = $this->listEntries();
        $currentEntries[] = $entry;
        // Simulates writing the crontab - overwrites all entries
        $this->entries = $currentEntries;
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
        if ($this->simulateListFailure) {
            return [];
        }
        if ($this->failuresRemaining > 0) {
            $this->failuresRemaining--;
            return [];
        }
        return $this->entries;
    }
}

/**
 * Mock Healthchecks.io client for testing SchedulerService integration.
 *
 * All checks use schedule-based monitoring (unified interface).
 */
class MockHealthchecksClient implements \HotTub\Contracts\HealthchecksClientInterface
{
    private bool $enabled;
    private array $createdChecks = [];
    private array $pingedUrls = [];
    private array $deletedUuids = [];
    private bool $shouldFailCreate = false;

    public function __construct(bool $enabled = true)
    {
        $this->enabled = $enabled;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function setShouldFailCreate(bool $fail): void
    {
        $this->shouldFailCreate = $fail;
    }

    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array {
        if ($this->shouldFailCreate) {
            return null;
        }

        $uuid = 'mock-uuid-' . count($this->createdChecks);
        $pingUrl = 'https://hc-ping.com/' . $uuid;

        $this->createdChecks[] = [
            'name' => $name,
            'schedule' => $schedule,
            'timezone' => $timezone,
            'grace' => $grace,
            'channels' => $channels,
        ];

        return [
            'uuid' => $uuid,
            'ping_url' => $pingUrl,
            'status' => 'new',
        ];
    }

    public function ping(string $pingUrl): bool
    {
        $this->pingedUrls[] = $pingUrl;
        return true;
    }

    public function delete(string $uuid): bool
    {
        $this->deletedUuids[] = $uuid;
        return true;
    }

    public function getCheck(string $uuid): ?array
    {
        return null;
    }

    // Test helpers
    public function getCreatedChecks(): array
    {
        return $this->createdChecks;
    }

    public function getPingedUrls(): array
    {
        return $this->pingedUrls;
    }

    public function getDeletedUuids(): array
    {
        return $this->deletedUuids;
    }
}
