<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Controllers\ScheduleController;
use HotTub\Services\SchedulerService;
use PHPUnit\Framework\TestCase;

class ScheduleControllerTest extends TestCase
{
    private string $jobsDir;
    private MockCrontabAdapter $crontabAdapter;
    private SchedulerService $scheduler;
    private ScheduleController $controller;

    protected function setUp(): void
    {
        $this->jobsDir = sys_get_temp_dir() . '/controller-test-' . uniqid();
        mkdir($this->jobsDir, 0755, true);

        $this->crontabAdapter = new MockCrontabAdapter();
        $this->scheduler = new SchedulerService(
            $this->jobsDir,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->crontabAdapter
        );

        $this->controller = new ScheduleController($this->scheduler);
    }

    protected function tearDown(): void
    {
        $files = glob($this->jobsDir . '/*');
        if ($files) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        rmdir($this->jobsDir);
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
