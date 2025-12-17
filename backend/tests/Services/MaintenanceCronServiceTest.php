<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\MaintenanceCronService;
use PHPUnit\Framework\TestCase;

/**
 * Mock crontab adapter for testing MaintenanceCronService.
 */
class MockCrontabAdapterForMaintenance implements CrontabAdapterInterface
{
    public array $entries = [];
    public array $addedEntries = [];
    public array $removedPatterns = [];

    public function addEntry(string $entry): void
    {
        $this->entries[] = $entry;
        $this->addedEntries[] = $entry;
    }

    public function removeByPattern(string $pattern): void
    {
        $this->removedPatterns[] = $pattern;
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

class MaintenanceCronServiceTest extends TestCase
{
    private MockCrontabAdapterForMaintenance $crontabAdapter;
    private MaintenanceCronService $service;
    private string $apiBaseUrl;

    protected function setUp(): void
    {
        $this->crontabAdapter = new MockCrontabAdapterForMaintenance();
        $this->apiBaseUrl = 'https://example.com/tub/backend/public';
        $this->service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->apiBaseUrl
        );
    }

    // ========== ensureLogRotationCronExists() Tests ==========

    public function testEnsureLogRotationCronExistsCreatesJobWhenNotExists(): void
    {
        $result = $this->service->ensureLogRotationCronExists();

        $this->assertTrue($result['created']);
        $this->assertCount(1, $this->crontabAdapter->addedEntries);
    }

    public function testEnsureLogRotationCronExistsDoesNotCreateWhenAlreadyExists(): void
    {
        // Pre-populate with existing log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST -H "Authorization: Bearer $CRON_JWT" https://example.com/tub/backend/public/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $result = $this->service->ensureLogRotationCronExists();

        $this->assertFalse($result['created']);
        $this->assertEmpty($this->crontabAdapter->addedEntries);
    }

    public function testCreatedCronJobHasCorrectMarker(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString('HOTTUB:log-rotation', $entry);
    }

    public function testCreatedCronJobRunsMonthly(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        // Cron schedule: minute hour day-of-month month day-of-week
        // "0 3 1 * *" = 3am on the 1st of every month
        $this->assertStringStartsWith('0 3 1 * *', $entry);
    }

    public function testCreatedCronJobCallsCorrectEndpoint(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString('/api/maintenance/logs/rotate', $entry);
        $this->assertStringContainsString($this->apiBaseUrl, $entry);
    }

    public function testCreatedCronJobUsesAuthorizationHeader(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString('Authorization: Bearer', $entry);
    }

    public function testCreatedCronJobUsesPOSTMethod(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString('-X POST', $entry);
    }

    public function testEnsureLogRotationCronExistsReturnsStatus(): void
    {
        $result = $this->service->ensureLogRotationCronExists();

        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('entry', $result);
        $this->assertIsString($result['entry']);
    }

    public function testLogRotationCronExistsReturnsTrueWhenExists(): void
    {
        // Pre-populate with existing log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $this->assertTrue($this->service->logRotationCronExists());
    }

    public function testLogRotationCronExistsReturnsFalseWhenNotExists(): void
    {
        $this->assertFalse($this->service->logRotationCronExists());
    }

    public function testLogRotationCronExistsIgnoresOtherHottubCrons(): void
    {
        // Pre-populate with other HOTTUB cron jobs, but not log-rotation
        $this->crontabAdapter->entries[] = '30 8 * * * /path/to/cron-runner.sh job-abc123 # HOTTUB:job-abc123';
        $this->crontabAdapter->entries[] = '0 9 * * * /path/to/cron-runner.sh rec-xyz789 # HOTTUB:rec-xyz789';

        $this->assertFalse($this->service->logRotationCronExists());
    }

    // ========== removeLogRotationCron() Tests ==========

    public function testRemoveLogRotationCronRemovesExistingJob(): void
    {
        // Pre-populate with log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $result = $this->service->removeLogRotationCron();

        $this->assertTrue($result['removed']);
        $this->assertEmpty($this->crontabAdapter->entries);
    }

    public function testRemoveLogRotationCronDoesNothingWhenNotExists(): void
    {
        $result = $this->service->removeLogRotationCron();

        $this->assertFalse($result['removed']);
    }

    public function testRemoveLogRotationCronDoesNotAffectOtherCrons(): void
    {
        $otherCron = '30 8 * * * /path/to/cron-runner.sh job-abc123 # HOTTUB:job-abc123';
        $logRotationCron = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $this->crontabAdapter->entries[] = $otherCron;
        $this->crontabAdapter->entries[] = $logRotationCron;

        $this->service->removeLogRotationCron();

        $this->assertCount(1, $this->crontabAdapter->entries);
        $this->assertEquals($otherCron, $this->crontabAdapter->entries[0]);
    }
}
