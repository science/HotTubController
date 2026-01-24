<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\ScheduledJobsCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ScheduledJobsCleanupService.
 *
 * CRITICAL: This service deletes files. Tests must verify:
 * 1. Only orphaned files are deleted (no matching cron entry)
 * 2. Only old files are deleted (older than threshold)
 * 3. Only valid job files are considered (proper JSON structure)
 * 4. Active job files are NEVER deleted
 */
class ScheduledJobsCleanupServiceTest extends TestCase
{
    private string $jobsDir;
    private MockObject&CrontabAdapterInterface $mockCrontab;

    protected function setUp(): void
    {
        $this->jobsDir = sys_get_temp_dir() . '/cleanup-test-' . uniqid();
        mkdir($this->jobsDir, 0755, true);

        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        if (is_dir($this->jobsDir)) {
            $files = glob($this->jobsDir . '/*') ?: [];
            foreach ($files as $file) {
                unlink($file);
            }
            rmdir($this->jobsDir);
        }
    }

    private function createService(int $minAgeSeconds = 3600): ScheduledJobsCleanupService
    {
        return new ScheduledJobsCleanupService(
            $this->jobsDir,
            $this->mockCrontab,
            $minAgeSeconds
        );
    }

    private function createJobFile(string $jobId, int $ageSeconds = 0): string
    {
        $path = $this->jobsDir . '/' . $jobId . '.json';
        $data = [
            'jobId' => $jobId,
            'endpoint' => '/api/test/endpoint',
            'apiBaseUrl' => 'https://example.com',
            'recurring' => false,
            'createdAt' => date('c', time() - $ageSeconds),
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));

        // Set file modification time to simulate age
        if ($ageSeconds > 0) {
            touch($path, time() - $ageSeconds);
        }

        return $path;
    }

    // =========================================================================
    // SAFETY TESTS: Ensure we don't delete files we shouldn't
    // =========================================================================

    public function testDoesNotDeleteFilesWithMatchingCronEntry(): void
    {
        // Create a job file that's old enough to be cleaned
        $jobId = 'job-active-12345678';
        $this->createJobFile($jobId, 7200); // 2 hours old

        // Mock: crontab contains this job
        $this->mockCrontab->method('listEntries')->willReturn([
            "30 14 24 01 * /path/to/cron-runner.sh '$jobId' # HOTTUB:$jobId:TEST",
        ]);

        $service = $this->createService(3600); // 1 hour threshold
        $result = $service->cleanup();

        // File should NOT be deleted (has matching cron)
        $this->assertFileExists($this->jobsDir . '/' . $jobId . '.json');
        $this->assertEmpty($result['deleted']);
        $this->assertContains($jobId, $result['skipped_active']);
    }

    public function testDoesNotDeleteRecentFiles(): void
    {
        // Create a job file that's too recent
        $jobId = 'job-recent-12345678';
        $this->createJobFile($jobId, 1800); // 30 minutes old

        // Mock: no cron entries (file is orphaned)
        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600); // 1 hour threshold
        $result = $service->cleanup();

        // File should NOT be deleted (too recent)
        $this->assertFileExists($this->jobsDir . '/' . $jobId . '.json');
        $this->assertEmpty($result['deleted']);
        $this->assertContains($jobId, $result['skipped_recent']);
    }

    public function testDoesNotDeleteNonJsonFiles(): void
    {
        // Create a non-JSON file
        $path = $this->jobsDir . '/readme.txt';
        file_put_contents($path, 'This is not a job file');
        touch($path, time() - 7200); // 2 hours old

        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // File should NOT be deleted (not a .json file)
        $this->assertFileExists($path);
        $this->assertEmpty($result['deleted']);
    }

    public function testDoesNotDeleteInvalidJsonFiles(): void
    {
        // Create a .json file with invalid content
        $path = $this->jobsDir . '/broken.json';
        file_put_contents($path, 'not valid json {{{');
        touch($path, time() - 7200); // 2 hours old

        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // File should NOT be deleted (invalid JSON)
        $this->assertFileExists($path);
        $this->assertEmpty($result['deleted']);
        $this->assertContains('broken', $result['skipped_invalid']);
    }

    public function testDoesNotDeleteJsonFilesWithoutJobId(): void
    {
        // Create a .json file without jobId field
        $path = $this->jobsDir . '/nojobid.json';
        file_put_contents($path, json_encode(['foo' => 'bar']));
        touch($path, time() - 7200); // 2 hours old

        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // File should NOT be deleted (no jobId field)
        $this->assertFileExists($path);
        $this->assertEmpty($result['deleted']);
        $this->assertContains('nojobid', $result['skipped_invalid']);
    }

    // =========================================================================
    // DELETION TESTS: Ensure we DO delete orphaned files
    // =========================================================================

    public function testDeletesOrphanedOldJobFile(): void
    {
        // Create an old orphaned job file
        $jobId = 'job-orphan-12345678';
        $this->createJobFile($jobId, 7200); // 2 hours old

        // Mock: no cron entries (file is orphaned)
        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600); // 1 hour threshold
        $result = $service->cleanup();

        // File SHOULD be deleted (orphaned and old)
        $this->assertFileDoesNotExist($this->jobsDir . '/' . $jobId . '.json');
        $this->assertContains($jobId, $result['deleted']);
    }

    public function testDeletesMultipleOrphanedFiles(): void
    {
        // Create multiple orphaned job files
        $jobIds = ['job-orphan-1', 'job-orphan-2', 'job-orphan-3'];
        foreach ($jobIds as $jobId) {
            $this->createJobFile($jobId, 7200); // 2 hours old
        }

        // Mock: no cron entries
        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // All orphaned files should be deleted
        foreach ($jobIds as $jobId) {
            $this->assertFileDoesNotExist($this->jobsDir . '/' . $jobId . '.json');
            $this->assertContains($jobId, $result['deleted']);
        }
    }

    public function testMixedScenario(): void
    {
        // Create mix of files: active, recent orphan, old orphan
        $activeJobId = 'job-active-aaaa';
        $recentOrphanId = 'job-recent-bbbb';
        $oldOrphanId = 'job-orphan-cccc';

        $this->createJobFile($activeJobId, 7200);   // 2 hours old, but has cron
        $this->createJobFile($recentOrphanId, 1800); // 30 mins old, no cron
        $this->createJobFile($oldOrphanId, 7200);    // 2 hours old, no cron

        // Mock: only active job has cron entry
        $this->mockCrontab->method('listEntries')->willReturn([
            "30 14 24 01 * /path/to/cron-runner.sh '$activeJobId' # HOTTUB:$activeJobId:TEST",
        ]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // Active: should exist (has cron)
        $this->assertFileExists($this->jobsDir . '/' . $activeJobId . '.json');
        $this->assertContains($activeJobId, $result['skipped_active']);

        // Recent orphan: should exist (too recent)
        $this->assertFileExists($this->jobsDir . '/' . $recentOrphanId . '.json');
        $this->assertContains($recentOrphanId, $result['skipped_recent']);

        // Old orphan: should be deleted
        $this->assertFileDoesNotExist($this->jobsDir . '/' . $oldOrphanId . '.json');
        $this->assertContains($oldOrphanId, $result['deleted']);
    }

    // =========================================================================
    // EDGE CASES
    // =========================================================================

    public function testHandlesEmptyDirectory(): void
    {
        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        $this->assertEmpty($result['deleted']);
        $this->assertEmpty($result['skipped_active']);
        $this->assertEmpty($result['skipped_recent']);
        $this->assertEmpty($result['skipped_invalid']);
    }

    public function testHandlesNonexistentDirectory(): void
    {
        $this->mockCrontab->method('listEntries')->willReturn([]);

        // Use a non-existent directory
        $service = new ScheduledJobsCleanupService(
            '/nonexistent/path/that/does/not/exist',
            $this->mockCrontab,
            3600
        );

        $result = $service->cleanup();

        $this->assertEmpty($result['deleted']);
    }

    public function testRecurringJobsAreAlsoProtectedByCron(): void
    {
        // Create a recurring job file
        $jobId = 'rec-daily-heater';
        $path = $this->jobsDir . '/' . $jobId . '.json';
        $data = [
            'jobId' => $jobId,
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'https://example.com',
            'recurring' => true,
            'createdAt' => date('c', time() - 86400), // 1 day old
        ];
        file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT));
        touch($path, time() - 86400);

        // Mock: crontab contains this recurring job
        $this->mockCrontab->method('listEntries')->willReturn([
            "30 14 * * * /path/to/cron-runner.sh '$jobId' # HOTTUB:$jobId:REC",
        ]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // File should NOT be deleted (has matching cron)
        $this->assertFileExists($path);
        $this->assertContains($jobId, $result['skipped_active']);
    }

    public function testHeatTargetJobsAreHandled(): void
    {
        // Create a heat-target job file (like the one we saw orphaned in production)
        $jobId = 'heat-target-9f027762';
        $this->createJobFile($jobId, 7200); // 2 hours old

        // Mock: no cron entries (orphaned)
        $this->mockCrontab->method('listEntries')->willReturn([]);

        $service = $this->createService(3600);
        $result = $service->cleanup();

        // File SHOULD be deleted (orphaned heat-target job)
        $this->assertFileDoesNotExist($this->jobsDir . '/' . $jobId . '.json');
        $this->assertContains($jobId, $result['deleted']);
    }
}
