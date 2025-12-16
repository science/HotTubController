<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\HealthchecksClient;
use HotTub\Services\SchedulerService;
use PHPUnit\Framework\TestCase;

/**
 * Live integration tests for SchedulerService health check creation.
 *
 * These tests verify that both one-off and recurring jobs create
 * health checks in Healthchecks.io with proper configuration.
 *
 * IMPORTANT: Tests clean up after themselves to avoid polluting
 * the Healthchecks.io account with test data.
 *
 * @group live
 * @group healthchecks
 */
class SchedulerHealthchecksLiveTest extends TestCase
{
    private ?HealthchecksClient $healthchecksClient = null;
    private string $jobsDir;
    private string $apiKey;
    private string $channelId;

    /** @var array<string> UUIDs of checks to clean up */
    private array $createdChecks = [];

    /** @var array<string> Job files to clean up */
    private array $createdJobFiles = [];

    protected function setUp(): void
    {
        // Load API key from env.production config
        $envFile = dirname(__DIR__, 3) . '/config/env.production';
        $config = [];

        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            foreach (explode("\n", $content) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                    $config[$matches[1]] = trim($matches[2]);
                }
            }
        }

        $this->apiKey = $config['HEALTHCHECKS_IO_KEY'] ?? getenv('HEALTHCHECKS_IO_KEY') ?: '';
        $this->channelId = $config['HEALTHCHECKS_IO_CHANNEL'] ?? '';

        if (empty($this->apiKey)) {
            $this->markTestSkipped('HEALTHCHECKS_IO_KEY not configured');
        }

        $this->healthchecksClient = new HealthchecksClient(
            $this->apiKey,
            $this->channelId,
            '/tmp/scheduler-healthchecks-test.log'
        );

        // Create temp directory for job files
        $this->jobsDir = sys_get_temp_dir() . '/scheduler-hc-test-' . uniqid();
        mkdir($this->jobsDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up health checks in Healthchecks.io
        if ($this->healthchecksClient !== null) {
            foreach ($this->createdChecks as $uuid) {
                $this->healthchecksClient->delete($uuid);
            }
        }

        // Clean up job files
        foreach ($this->createdJobFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up jobs directory
        if (is_dir($this->jobsDir)) {
            $files = glob($this->jobsDir . '/*');
            if ($files) {
                foreach ($files as $file) {
                    unlink($file);
                }
            }
            rmdir($this->jobsDir);
        }
    }

    /**
     * Test that one-off jobs create health checks with cron schedule.
     *
     * One-off jobs should use schedule-based checks just like recurring jobs,
     * but with a specific date/time cron expression (e.g., "30 14 15 12 *").
     * The only difference from recurring is: on success, delete vs ping.
     */
    public function testOneOffJobCreatesHealthCheckWithSchedule(): void
    {
        $scheduler = $this->createScheduler();

        // Schedule a job 1 hour from now
        $oneHourFromNow = (new \DateTime('now', new \DateTimeZone('UTC')))->modify('+1 hour');
        $result = $scheduler->scheduleJob('heater-on', $oneHourFromNow->format(\DateTime::ATOM));

        // Read job file to get health check UUID
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->createdJobFiles[] = $jobFile;

        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData, 'One-off job should have healthcheckUuid');
        $this->createdChecks[] = $jobData['healthcheckUuid'];

        // Verify check exists in Healthchecks.io
        $check = $this->getCheckFromApi($jobData['healthcheckUuid']);
        $this->assertNotNull($check, 'Health check should exist in Healthchecks.io');

        // One-off job should have a SCHEDULE (not timeout) - this is the key change
        $this->assertArrayHasKey('schedule', $check, 'One-off check should use schedule, not timeout');

        // Schedule should include the specific hour and minute
        $expectedMinute = (int) $oneHourFromNow->format('i');
        $expectedHour = (int) $oneHourFromNow->format('G');
        $this->assertStringContainsString("$expectedMinute $expectedHour", $check['schedule'],
            "Schedule should contain '{$expectedMinute} {$expectedHour}' for the scheduled time");

        // Check should be pinged (armed)
        $this->assertEquals('up', $check['status'], 'Check should be pinged/armed');
        $this->assertEquals(1, $check['n_pings'], 'Check should have exactly 1 ping');
    }

    /**
     * Test that recurring jobs create health checks with cron schedule.
     *
     * This is the key test - recurring jobs should use schedule-based
     * monitoring rather than timeout-based monitoring.
     */
    public function testRecurringJobCreatesHealthCheckWithSchedule(): void
    {
        $scheduler = $this->createScheduler();

        // Schedule a recurring job at 6:30 AM
        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        // Read job file to get health check UUID
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->createdJobFiles[] = $jobFile;

        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData, 'Recurring job should have healthcheckUuid');
        $this->createdChecks[] = $jobData['healthcheckUuid'];

        // Verify check exists in Healthchecks.io
        $check = $this->getCheckFromApi($jobData['healthcheckUuid']);
        $this->assertNotNull($check, 'Health check should exist in Healthchecks.io');

        // Recurring job should have a schedule (cron expression)
        $this->assertArrayHasKey('schedule', $check, 'Recurring check should have a schedule');
        $this->assertStringContainsString('30 6', $check['schedule'], 'Schedule should be for 6:30');

        // Check should be pinged (armed)
        $this->assertEquals('up', $check['status'], 'Check should be pinged/armed');
    }

    /**
     * Test that recurring jobs with timezone offset create correct schedule.
     */
    public function testRecurringJobWithTimezoneCreatesCorrectSchedule(): void
    {
        $scheduler = $this->createScheduler();

        // Schedule a recurring job at 6:30 AM Pacific (UTC-8)
        // In UTC, this is 14:30
        $result = $scheduler->scheduleJob('heater-on', '06:30-08:00', recurring: true);

        // Read job file to get health check UUID
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->createdJobFiles[] = $jobFile;

        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData, 'Recurring job should have healthcheckUuid');
        $this->createdChecks[] = $jobData['healthcheckUuid'];

        // Verify check exists
        $check = $this->getCheckFromApi($jobData['healthcheckUuid']);
        $this->assertNotNull($check);

        // Schedule should be in UTC (14:30)
        $this->assertArrayHasKey('schedule', $check);
        $this->assertStringContainsString('30 14', $check['schedule'],
            'Schedule should be 14:30 UTC (06:30 Pacific converted)');
    }

    /**
     * Test that canceling a recurring job deletes its health check.
     */
    public function testCancelRecurringJobDeletesHealthCheck(): void
    {
        $scheduler = $this->createScheduler();

        // Schedule a recurring job
        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        // Get the health check UUID
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->createdJobFiles[] = $jobFile;

        $jobData = json_decode(file_get_contents($jobFile), true);
        $healthcheckUuid = $jobData['healthcheckUuid'] ?? null;

        $this->assertNotNull($healthcheckUuid, 'Job should have health check');

        // Cancel the job
        $scheduler->cancelJob($result['jobId']);

        // Verify health check is deleted
        $check = $this->getCheckFromApi($healthcheckUuid);
        $this->assertNull($check, 'Health check should be deleted when job is canceled');

        // Don't add to cleanup list since we already deleted it
    }

    /**
     * Test that health checks have descriptive names.
     *
     * Check names should include: job ID, action type, and job type (ONCE/DAILY)
     * so they're identifiable in the Healthchecks.io admin panel.
     */
    public function testHealthChecksHaveDescriptiveNames(): void
    {
        $scheduler = $this->createScheduler();

        // Create both job types
        $oneHourFromNow = (new \DateTime())->modify('+1 hour');
        $oneOff = $scheduler->scheduleJob('heater-on', $oneHourFromNow->format(\DateTime::ATOM));
        $recurring = $scheduler->scheduleJob('heater-off', '18:00', recurring: true);

        // Track for cleanup
        $oneOffFile = $this->jobsDir . '/' . $oneOff['jobId'] . '.json';
        $recurringFile = $this->jobsDir . '/' . $recurring['jobId'] . '.json';
        $this->createdJobFiles[] = $oneOffFile;
        $this->createdJobFiles[] = $recurringFile;

        $oneOffData = json_decode(file_get_contents($oneOffFile), true);
        $recurringData = json_decode(file_get_contents($recurringFile), true);

        $this->createdChecks[] = $oneOffData['healthcheckUuid'];
        $this->createdChecks[] = $recurringData['healthcheckUuid'];

        // Get checks from API
        $oneOffCheck = $this->getCheckFromApi($oneOffData['healthcheckUuid']);
        $recurringCheck = $this->getCheckFromApi($recurringData['healthcheckUuid']);

        // One-off check should have descriptive name with job ID, action, and ONCE
        $this->assertStringContainsString($oneOff['jobId'], $oneOffCheck['name'],
            'One-off check name should contain job ID');
        $this->assertStringContainsString('heater-on', $oneOffCheck['name'],
            'One-off check name should contain action');
        $this->assertStringContainsString('ONCE', $oneOffCheck['name'],
            'One-off check name should indicate it is a one-time job');

        // Recurring check should have descriptive name with job ID, action, and DAILY
        $this->assertStringContainsString($recurring['jobId'], $recurringCheck['name'],
            'Recurring check name should contain job ID');
        $this->assertStringContainsString('heater-off', $recurringCheck['name'],
            'Recurring check name should contain action');
        $this->assertStringContainsString('DAILY', $recurringCheck['name'],
            'Recurring check name should indicate it is a daily job');
    }

    /**
     * Test that recurring jobs store ping_url for use by cron-runner.sh.
     *
     * Recurring jobs need to PING on success (not delete), so they need
     * the ping_url stored in the job file.
     */
    public function testRecurringJobStoresPingUrl(): void
    {
        $scheduler = $this->createScheduler();

        $result = $scheduler->scheduleJob('heater-on', '06:30', recurring: true);

        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->createdJobFiles[] = $jobFile;

        $jobData = json_decode(file_get_contents($jobFile), true);

        $this->assertArrayHasKey('healthcheckUuid', $jobData);
        $this->createdChecks[] = $jobData['healthcheckUuid'];

        // Recurring jobs should also store ping_url for cron-runner.sh to use
        $this->assertArrayHasKey('healthcheckPingUrl', $jobData,
            'Recurring job should store ping_url for cron-runner.sh');
        $this->assertStringContainsString('hc-ping.com', $jobData['healthcheckPingUrl'],
            'Ping URL should be a valid hc-ping.com URL');
    }

    /**
     * Test that health checks are created with the notification channel.
     */
    public function testHealthChecksHaveChannelAttached(): void
    {
        if (empty($this->channelId)) {
            $this->markTestSkipped('HEALTHCHECKS_IO_CHANNEL not configured');
        }

        $scheduler = $this->createScheduler();

        // Create both job types
        $oneHourFromNow = (new \DateTime())->modify('+1 hour');
        $oneOff = $scheduler->scheduleJob('heater-on', $oneHourFromNow->format(\DateTime::ATOM));
        $recurring = $scheduler->scheduleJob('heater-off', '18:00', recurring: true);

        // Track for cleanup
        $oneOffFile = $this->jobsDir . '/' . $oneOff['jobId'] . '.json';
        $recurringFile = $this->jobsDir . '/' . $recurring['jobId'] . '.json';
        $this->createdJobFiles[] = $oneOffFile;
        $this->createdJobFiles[] = $recurringFile;

        $oneOffData = json_decode(file_get_contents($oneOffFile), true);
        $recurringData = json_decode(file_get_contents($recurringFile), true);

        $this->createdChecks[] = $oneOffData['healthcheckUuid'];
        $this->createdChecks[] = $recurringData['healthcheckUuid'];

        // Verify both have channels
        $oneOffCheck = $this->getCheckFromApi($oneOffData['healthcheckUuid']);
        $recurringCheck = $this->getCheckFromApi($recurringData['healthcheckUuid']);

        $this->assertNotEmpty($oneOffCheck['channels'], 'One-off check should have channel');
        $this->assertNotEmpty($recurringCheck['channels'], 'Recurring check should have channel');
    }

    /**
     * Create a SchedulerService with real Healthchecks.io client but mock crontab.
     */
    private function createScheduler(): SchedulerService
    {
        return new SchedulerService(
            $this->jobsDir,
            '/fake/cron-runner.sh',
            'https://example.com/api',
            new NoOpCrontabAdapter(),
            null, // TimeConverter
            $this->healthchecksClient
        );
    }

    /**
     * Get a check directly from the Healthchecks.io API with full details.
     */
    private function getCheckFromApi(string $uuid): ?array
    {
        $ch = curl_init("https://healthchecks.io/api/v3/checks/{$uuid}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Api-Key: ' . $this->apiKey,
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return null;
        }

        return json_decode($response, true);
    }
}

/**
 * No-op crontab adapter for tests that don't need real crontab.
 */
class NoOpCrontabAdapter implements CrontabAdapterInterface
{
    public function addEntry(string $entry): void
    {
        // No-op
    }

    public function removeByPattern(string $pattern): void
    {
        // No-op
    }

    public function listEntries(): array
    {
        return [];
    }
}
