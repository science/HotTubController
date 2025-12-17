<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\MaintenanceCronService;
use PHPUnit\Framework\TestCase;

/**
 * Mock crontab adapter for integration testing.
 */
class IntegrationMockCrontabAdapter implements CrontabAdapterInterface
{
    public array $entries = [];

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

/**
 * Integration tests for the maintenance cron setup workflow.
 *
 * Tests the full deploy workflow: check if cron exists, create if not.
 */
class MaintenanceCronSetupTest extends TestCase
{
    private IntegrationMockCrontabAdapter $crontabAdapter;
    private string $cronScriptPath;

    protected function setUp(): void
    {
        $this->crontabAdapter = new IntegrationMockCrontabAdapter();
        $this->cronScriptPath = '/path/to/storage/bin/log-rotation-cron.sh';
    }

    public function testDeployWorkflowCreatesLogRotationCronWhenNotExists(): void
    {
        // Simulate fresh deploy - no existing cron jobs
        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );

        // Run the deploy setup
        $result = $service->ensureLogRotationCronExists();

        // Should have created the cron
        $this->assertTrue($result['created']);
        $this->assertCount(1, $this->crontabAdapter->entries);
        $this->assertStringContainsString('HOTTUB:log-rotation', $this->crontabAdapter->entries[0]);
    }

    public function testDeployWorkflowIsIdempotent(): void
    {
        // Simulate deploy with existing cron
        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );

        // First deploy
        $result1 = $service->ensureLogRotationCronExists();
        $this->assertTrue($result1['created']);

        // Second deploy (e.g., code update)
        $result2 = $service->ensureLogRotationCronExists();
        $this->assertFalse($result2['created']);

        // Should still have only one entry
        $this->assertCount(1, $this->crontabAdapter->entries);
    }

    public function testDeployWorkflowPreservesOtherCronJobs(): void
    {
        // Pre-existing cron jobs from scheduler
        $this->crontabAdapter->entries[] = '30 8 * * * /path/to/cron-runner.sh job-abc # HOTTUB:job-abc';
        $this->crontabAdapter->entries[] = '0 9 * * * /path/to/cron-runner.sh rec-xyz # HOTTUB:rec-xyz';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );

        // Run the deploy setup
        $service->ensureLogRotationCronExists();

        // Should have 3 entries: 2 existing + 1 new
        $this->assertCount(3, $this->crontabAdapter->entries);

        // Original entries should still be present
        $allEntries = implode("\n", $this->crontabAdapter->entries);
        $this->assertStringContainsString('HOTTUB:job-abc', $allEntries);
        $this->assertStringContainsString('HOTTUB:rec-xyz', $allEntries);
        $this->assertStringContainsString('HOTTUB:log-rotation', $allEntries);
    }

    public function testCreatedCronJobHasCorrectSchedule(): void
    {
        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );

        $service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->entries[0];

        // Should run at 3am on the 1st of each month
        $this->assertStringStartsWith('0 3 1 * *', $entry);
    }

    public function testCreatedCronJobUsesScript(): void
    {
        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );

        $service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->entries[0];

        // Should contain the script path
        $this->assertStringContainsString($this->cronScriptPath, $entry);
    }
}
