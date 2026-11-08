<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Controllers\MaintenanceController;
use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\CrontabBackupService;
use HotTub\Services\LogRotationService;
use HotTub\Services\ScheduledJobsCleanupService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Mock Healthchecks client for testing MaintenanceController.
 */
class MockHealthchecksClientForController implements HealthchecksClientInterface
{
    public bool $enabled = true;
    public array $pings = [];
    public bool $pingResult = true;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array {
        return null;
    }

    public function ping(string $pingUrl): bool
    {
        $this->pings[] = $pingUrl;
        return $this->pingResult;
    }

    public function delete(string $uuid): bool
    {
        return true;
    }

    public function getCheck(string $uuid): ?array
    {
        return null;
    }
}

class MaintenanceControllerTest extends TestCase
{
    private string $tempDir;
    private LogRotationService $logRotationService;
    private MaintenanceController $controller;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/maintenance-controller-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/logs', 0755, true);

        $this->logRotationService = new LogRotationService();
        $this->controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs'
        );
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempDir);
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

    private function createOldFile(string $path, int $daysOld): void
    {
        file_put_contents($path, "test log content\n");
        $timestamp = time() - ($daysOld * 24 * 60 * 60);
        touch($path, $timestamp);
    }

    // ========== rotateLogs() Tests ==========

    public function testRotateLogsReturns200OnSuccess(): void
    {
        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('compressed', $response['body']);
        $this->assertArrayHasKey('deleted', $response['body']);
    }

    public function testRotateLogsCompressesOldLogFiles(): void
    {
        // Create a file 35 days old (should be compressed at 30 day threshold)
        $logFile = $this->tempDir . '/logs/api.log';
        $this->createOldFile($logFile, 35);

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileDoesNotExist($logFile);
        $this->assertFileExists($logFile . '.gz');
        $this->assertCount(1, $response['body']['compressed']);
    }

    public function testRotateLogsDeletesCompressedFilesOlderThanTwoYears(): void
    {
        // 740 days is past the 730-day (two-year) retention
        $gzFile = $this->tempDir . '/logs/old.log.gz';
        $this->createOldFile($gzFile, 740);

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileDoesNotExist($gzFile);
        $this->assertCount(1, $response['body']['deleted']);
    }

    public function testRotateLogsDoesNotCompressRecentFiles(): void
    {
        // Create a file 10 days old (should NOT be compressed at 30 day threshold)
        $logFile = $this->tempDir . '/logs/recent.log';
        $this->createOldFile($logFile, 10);

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileExists($logFile);
        $this->assertFileDoesNotExist($logFile . '.gz');
        $this->assertEmpty($response['body']['compressed']);
    }

    /**
     * Old temperature logs are kept (compressed) for two years so heating
     * behaviour can be compared across seasons, e.g. to spot a heater element
     * slowing down as it scales. They cost about 3 KB per day gzipped.
     */
    public function testRotateLogsKeepsCompressedLogsForTwoYears(): void
    {
        $sixMonths = $this->tempDir . '/logs/temperature-six-months.log.gz';
        $almostTwoYears = $this->tempDir . '/logs/temperature-almost-two-years.log.gz';
        $this->createOldFile($sixMonths, 200);
        $this->createOldFile($almostTwoYears, 720);

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileExists($sixMonths);
        $this->assertFileExists($almostTwoYears);
        $this->assertEmpty($response['body']['deleted']);
    }

    public function testRotateLogsHandlesEmptyLogDirectory(): void
    {
        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertEmpty($response['body']['compressed']);
        $this->assertEmpty($response['body']['deleted']);
    }

    public function testRotateLogsOnlyProcessesLogFiles(): void
    {
        // Create old files with different extensions
        $this->createOldFile($this->tempDir . '/logs/api.log', 35);
        $this->createOldFile($this->tempDir . '/logs/cron.log', 35);
        $this->createOldFile($this->tempDir . '/logs/other.txt', 35); // Should be ignored

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        // Only .log files should be compressed
        $this->assertCount(2, $response['body']['compressed']);
        // The .txt file should still exist uncompressed
        $this->assertFileExists($this->tempDir . '/logs/other.txt');
    }

    public function testRotateLogsReturnsTimestamp(): void
    {
        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('timestamp', $response['body']);
        // Timestamp should be a valid ISO 8601 date
        $this->assertNotFalse(strtotime($response['body']['timestamp']));
    }

    // ========== Crontab backup pruning ==========

    private function controllerWithCrontabBackups(string $backupDir): MaintenanceController
    {
        return new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            crontabBackupService: new CrontabBackupService($backupDir)
        );
    }

    private function makeBackupDir(): string
    {
        $dir = $this->tempDir . '/crontab-backups';
        mkdir($dir, 0755, true);
        return $dir;
    }

    public function testRotateLogsPrunesCrontabBackupsOlderThan30Days(): void
    {
        $dir = $this->makeBackupDir();
        $this->createOldFile($dir . '/crontab-old.txt', 31);
        $this->createOldFile($dir . '/crontab-older.txt.gz', 300);

        $response = $this->controllerWithCrontabBackups($dir)->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileDoesNotExist($dir . '/crontab-old.txt');
        $this->assertFileDoesNotExist($dir . '/crontab-older.txt.gz');
        // A count, not a path list: the first run deletes thousands of files and
        // log-rotation-cron.sh writes the whole response body into cron.log.
        $this->assertSame(2, $response['body']['crontab_backups_deleted']);
    }

    public function testRotateLogsKeepsRecentCrontabBackupsAsPlainText(): void
    {
        $dir = $this->makeBackupDir();
        $this->createOldFile($dir . '/crontab-recent.txt', 20);

        $response = $this->controllerWithCrontabBackups($dir)->rotateLogs();

        // Kept, and left uncompressed so they stay greppable; they're ~500 bytes each.
        $this->assertFileExists($dir . '/crontab-recent.txt');
        $this->assertFileDoesNotExist($dir . '/crontab-recent.txt.gz');
        $this->assertSame(0, $response['body']['crontab_backups_deleted']);
    }

    public function testRotateLogsReportsZeroCrontabBackupsWhenServiceNotConfigured(): void
    {
        $response = $this->controller->rotateLogs();

        $this->assertSame(0, $response['body']['crontab_backups_deleted']);
    }

    public function testRunAllAlsoPrunesCrontabBackups(): void
    {
        $dir = $this->makeBackupDir();
        $this->createOldFile($dir . '/crontab-old.txt', 31);

        $response = $this->controllerWithCrontabBackups($dir)->runAll();

        $this->assertFileDoesNotExist($dir . '/crontab-old.txt');
        $this->assertSame(1, $response['body']['crontab_backups_deleted']);
    }

    // ========== Healthchecks Integration Tests ==========

    public function testRotateLogsPingsHealthcheckOnSuccess(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();
        $pingUrl = 'https://hc-ping.com/test-uuid';

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            $pingUrl
        );

        $controller->rotateLogs();

        $this->assertCount(1, $healthchecksClient->pings);
        $this->assertEquals($pingUrl, $healthchecksClient->pings[0]);
    }

    public function testRotateLogsDoesNotPingWhenNoHealthcheckConfigured(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();

        // No ping URL configured
        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            null
        );

        $controller->rotateLogs();

        $this->assertEmpty($healthchecksClient->pings);
    }

    public function testRotateLogsDoesNotPingWhenHealthchecksDisabled(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();
        $healthchecksClient->enabled = false;
        $pingUrl = 'https://hc-ping.com/test-uuid';

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            $pingUrl
        );

        $controller->rotateLogs();

        $this->assertEmpty($healthchecksClient->pings);
    }

    public function testRotateLogsReturnsHealthcheckPingedStatus(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();
        $pingUrl = 'https://hc-ping.com/test-uuid';

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            $pingUrl
        );

        $response = $controller->rotateLogs();

        $this->assertArrayHasKey('healthcheck_pinged', $response['body']);
        $this->assertTrue($response['body']['healthcheck_pinged']);
    }

    public function testRotateLogsReturnsHealthcheckNotPingedWhenNoUrl(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            null
        );

        $response = $controller->rotateLogs();

        $this->assertArrayHasKey('healthcheck_pinged', $response['body']);
        $this->assertFalse($response['body']['healthcheck_pinged']);
    }

    public function testRotateLogsStillSucceedsIfHealthcheckPingFails(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();
        $healthchecksClient->pingResult = false;
        $pingUrl = 'https://hc-ping.com/test-uuid';

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            $pingUrl
        );

        $response = $controller->rotateLogs();

        // Log rotation should still succeed even if ping fails
        $this->assertEquals(200, $response['status']);
        // But indicate the ping failed
        $this->assertFalse($response['body']['healthcheck_pinged']);
    }

    // ========== cleanupOrphanedJobs() Tests ==========

    public function testCleanupOrphanedJobsReturns200WithServiceConfigured(): void
    {
        $jobsDir = $this->tempDir . '/jobs';
        mkdir($jobsDir, 0755, true);

        /** @var MockObject&CrontabAdapterInterface */
        $mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $mockCrontab->method('listEntries')->willReturn([]);

        $cleanupService = new ScheduledJobsCleanupService($jobsDir, $mockCrontab, 3600);

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            null,
            null,
            $cleanupService
        );

        $response = $controller->cleanupOrphanedJobs();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('timestamp', $response['body']);
        $this->assertArrayHasKey('deleted', $response['body']);
        $this->assertArrayHasKey('skipped_active', $response['body']);
        $this->assertArrayHasKey('skipped_recent', $response['body']);
        $this->assertArrayHasKey('skipped_invalid', $response['body']);
    }

    public function testCleanupOrphanedJobsReturns200WithNoteWhenServiceNotConfigured(): void
    {
        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs'
        );

        $response = $controller->cleanupOrphanedJobs();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('note', $response['body']);
        $this->assertEquals('Job cleanup service not configured', $response['body']['note']);
    }

    public function testCleanupOrphanedJobsDeletesOrphanedFiles(): void
    {
        $jobsDir = $this->tempDir . '/jobs';
        mkdir($jobsDir, 0755, true);

        // Create an orphaned job file (old, no cron entry)
        $jobId = 'orphan-test-12345678';
        $jobFile = $jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode(['jobId' => $jobId]));
        touch($jobFile, time() - 7200); // 2 hours old

        /** @var MockObject&CrontabAdapterInterface */
        $mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $mockCrontab->method('listEntries')->willReturn([]);

        $cleanupService = new ScheduledJobsCleanupService($jobsDir, $mockCrontab, 3600);

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            null,
            null,
            $cleanupService
        );

        $response = $controller->cleanupOrphanedJobs();

        $this->assertEquals(200, $response['status']);
        $this->assertContains($jobId, $response['body']['deleted']);
        $this->assertFileDoesNotExist($jobFile);
    }

    // ========== runAll() Tests ==========

    public function testRunAllExecutesLogRotationAndJobCleanup(): void
    {
        $jobsDir = $this->tempDir . '/jobs';
        mkdir($jobsDir, 0755, true);

        // Create an old log file to compress
        $logFile = $this->tempDir . '/logs/api.log';
        $this->createOldFile($logFile, 35);

        // Create an orphaned job file
        $jobId = 'orphan-runall-12345678';
        $jobFile = $jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode(['jobId' => $jobId]));
        touch($jobFile, time() - 7200);

        /** @var MockObject&CrontabAdapterInterface */
        $mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $mockCrontab->method('listEntries')->willReturn([]);

        $cleanupService = new ScheduledJobsCleanupService($jobsDir, $mockCrontab, 3600);

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            null,
            null,
            $cleanupService
        );

        $response = $controller->runAll();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('logs', $response['body']);
        $this->assertArrayHasKey('jobs', $response['body']);

        // Log rotation should have compressed the old log
        $this->assertCount(1, $response['body']['logs']['compressed']);
        $this->assertFileExists($logFile . '.gz');

        // Job cleanup should have deleted the orphan
        $this->assertContains($jobId, $response['body']['jobs']['deleted']);
        $this->assertFileDoesNotExist($jobFile);
    }

    public function testRunAllWorksWithoutJobCleanupService(): void
    {
        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs'
        );

        $response = $controller->runAll();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('logs', $response['body']);
        $this->assertArrayHasKey('jobs', $response['body']);
        // Jobs should have empty arrays when service not configured
        $this->assertEmpty($response['body']['jobs']['deleted']);
    }

    public function testRunAllPingsHealthcheckOnSuccess(): void
    {
        $healthchecksClient = new MockHealthchecksClientForController();
        $pingUrl = 'https://hc-ping.com/test-uuid';

        $controller = new MaintenanceController(
            $this->logRotationService,
            $this->tempDir . '/logs',
            $healthchecksClient,
            $pingUrl
        );

        $response = $controller->runAll();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['healthcheck_pinged']);
        $this->assertCount(1, $healthchecksClient->pings);
    }
}
