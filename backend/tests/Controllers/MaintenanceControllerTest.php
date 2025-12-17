<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Controllers\MaintenanceController;
use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Services\LogRotationService;
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

    public function testRotateLogsDeletesVeryOldCompressedFiles(): void
    {
        // Create a compressed file 200 days old (should be deleted at 180 day threshold)
        $gzFile = $this->tempDir . '/logs/old.log.gz';
        $this->createOldFile($gzFile, 200);

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

    public function testRotateLogsDoesNotDeleteFilesUnder6Months(): void
    {
        // Create a compressed file 100 days old (should NOT be deleted at 180 day threshold)
        $gzFile = $this->tempDir . '/logs/moderate.log.gz';
        $this->createOldFile($gzFile, 100);

        $response = $this->controller->rotateLogs();

        $this->assertEquals(200, $response['status']);
        $this->assertFileExists($gzFile);
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
}
