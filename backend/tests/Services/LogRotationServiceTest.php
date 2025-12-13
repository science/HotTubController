<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\LogRotationService;
use PHPUnit\Framework\TestCase;

class LogRotationServiceTest extends TestCase
{
    private string $tempDir;
    private LogRotationService $service;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/log-rotation-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->service = new LogRotationService();
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

    // ========== rotate() Tests ==========

    public function testRotateCompressesFilesOlderThanCompressThreshold(): void
    {
        // Create a file 10 days old (should be compressed at default 7 day threshold)
        $logFile = $this->tempDir . '/api.log';
        $this->createOldFile($logFile, 10);

        $result = $this->service->rotate($this->tempDir);

        $this->assertFileDoesNotExist($logFile);
        $this->assertFileExists($logFile . '.gz');
        $this->assertContains($logFile, $result['compressed']);
    }

    public function testRotateDoesNotCompressFilesNewerThanThreshold(): void
    {
        // Create a file 3 days old (should NOT be compressed at default 7 day threshold)
        $logFile = $this->tempDir . '/api.log';
        $this->createOldFile($logFile, 3);

        $result = $this->service->rotate($this->tempDir);

        $this->assertFileExists($logFile);
        $this->assertFileDoesNotExist($logFile . '.gz');
        $this->assertEmpty($result['compressed']);
    }

    public function testRotateDeletesFilesOlderThanDeleteThreshold(): void
    {
        // Create a file 100 days old (should be deleted at default 90 day threshold)
        $logFile = $this->tempDir . '/old.log';
        $this->createOldFile($logFile, 100);

        $result = $this->service->rotate($this->tempDir);

        $this->assertFileDoesNotExist($logFile);
        $this->assertContains($logFile, $result['deleted']);
    }

    public function testRotateDeletesCompressedFilesOlderThanDeleteThreshold(): void
    {
        // Create a compressed file 100 days old
        $gzFile = $this->tempDir . '/old.log.gz';
        $this->createOldFile($gzFile, 100);

        $result = $this->service->rotate($this->tempDir);

        $this->assertFileDoesNotExist($gzFile);
        $this->assertContains($gzFile, $result['deleted']);
    }

    public function testRotateDoesNotDeleteFilesNewerThanThreshold(): void
    {
        // Create a file 30 days old (should NOT be deleted at default 90 day threshold)
        $logFile = $this->tempDir . '/recent.log';
        $this->createOldFile($logFile, 30);

        $result = $this->service->rotate($this->tempDir);

        // Should be compressed but not deleted
        $this->assertFileExists($logFile . '.gz');
        $this->assertNotContains($logFile, $result['deleted']);
    }

    public function testRotateSkipsAlreadyCompressedFiles(): void
    {
        // Create an already compressed file 10 days old
        $gzFile = $this->tempDir . '/api.log.gz';
        $this->createOldFile($gzFile, 10);
        $originalSize = filesize($gzFile);

        $result = $this->service->rotate($this->tempDir);

        // Should not try to compress again or delete
        $this->assertFileExists($gzFile);
        $this->assertEmpty($result['compressed']);
        $this->assertEmpty($result['deleted']);
    }

    public function testRotateHandlesEmptyDirectory(): void
    {
        $result = $this->service->rotate($this->tempDir);

        $this->assertEmpty($result['compressed']);
        $this->assertEmpty($result['deleted']);
    }

    public function testRotateHandlesNonExistentDirectory(): void
    {
        $result = $this->service->rotate('/nonexistent/path');

        $this->assertEmpty($result['compressed']);
        $this->assertEmpty($result['deleted']);
    }

    public function testRotateUsesCustomThresholds(): void
    {
        // Create file 5 days old
        $logFile = $this->tempDir . '/api.log';
        $this->createOldFile($logFile, 5);

        // Use custom threshold: compress after 3 days
        $result = $this->service->rotate($this->tempDir, daysToCompress: 3, daysToDelete: 90);

        $this->assertFileDoesNotExist($logFile);
        $this->assertFileExists($logFile . '.gz');
        $this->assertContains($logFile, $result['compressed']);
    }

    public function testRotateUsesCustomDeleteThreshold(): void
    {
        // Create file 20 days old
        $logFile = $this->tempDir . '/api.log';
        $this->createOldFile($logFile, 20);

        // Use custom threshold: delete after 15 days
        $result = $this->service->rotate($this->tempDir, daysToCompress: 7, daysToDelete: 15);

        $this->assertFileDoesNotExist($logFile);
        $this->assertContains($logFile, $result['deleted']);
    }

    public function testRotateProcessesMultipleFiles(): void
    {
        // Create various files
        $this->createOldFile($this->tempDir . '/new.log', 2);      // Keep
        $this->createOldFile($this->tempDir . '/compress.log', 10); // Compress
        $this->createOldFile($this->tempDir . '/old.log', 100);     // Delete

        $result = $this->service->rotate($this->tempDir);

        $this->assertFileExists($this->tempDir . '/new.log');
        $this->assertFileExists($this->tempDir . '/compress.log.gz');
        $this->assertFileDoesNotExist($this->tempDir . '/old.log');

        $this->assertCount(1, $result['compressed']);
        $this->assertCount(1, $result['deleted']);
    }

    public function testRotatePreservesModificationTimeOnCompressedFile(): void
    {
        $logFile = $this->tempDir . '/api.log';
        $this->createOldFile($logFile, 10);
        $originalMtime = filemtime($logFile);

        $this->service->rotate($this->tempDir);

        $gzMtime = filemtime($logFile . '.gz');
        $this->assertEquals($originalMtime, $gzMtime);
    }

    // ========== Pattern Filtering Tests ==========

    public function testRotateWithPatternOnlyProcessesMatchingFiles(): void
    {
        $this->createOldFile($this->tempDir . '/api.log', 10);
        $this->createOldFile($this->tempDir . '/events.log', 10);
        $this->createOldFile($this->tempDir . '/other.txt', 10);

        // Only process .log files
        $result = $this->service->rotate($this->tempDir, pattern: '*.log');

        $this->assertFileExists($this->tempDir . '/api.log.gz');
        $this->assertFileExists($this->tempDir . '/events.log.gz');
        $this->assertFileExists($this->tempDir . '/other.txt'); // Not matched, unchanged
        $this->assertCount(2, $result['compressed']);
    }

    public function testRotateWithPatternHandlesCompressedVersions(): void
    {
        // The pattern should also match .gz versions for deletion
        $this->createOldFile($this->tempDir . '/api.log.gz', 100);

        $result = $this->service->rotate($this->tempDir, pattern: '*.log*');

        $this->assertFileDoesNotExist($this->tempDir . '/api.log.gz');
        $this->assertCount(1, $result['deleted']);
    }

    // ========== rotateMultiple() Tests ==========

    public function testRotateMultipleProcessesMultipleDirectories(): void
    {
        // Create subdirectories
        $dir1 = $this->tempDir . '/logs';
        $dir2 = $this->tempDir . '/backups';
        mkdir($dir1);
        mkdir($dir2);

        $this->createOldFile($dir1 . '/api.log', 10);
        $this->createOldFile($dir2 . '/backup.txt', 100);

        $configs = [
            ['path' => $dir1, 'pattern' => '*.log', 'daysToCompress' => 7, 'daysToDelete' => 90],
            ['path' => $dir2, 'pattern' => '*.txt', 'daysToCompress' => 7, 'daysToDelete' => 30],
        ];

        $results = $this->service->rotateMultiple($configs);

        $this->assertFileExists($dir1 . '/api.log.gz');
        $this->assertFileDoesNotExist($dir2 . '/backup.txt');
    }

    // ========== Compression Content Tests ==========

    public function testCompressedFileContainsOriginalContent(): void
    {
        $logFile = $this->tempDir . '/api.log';
        $content = "Line 1\nLine 2\nLine 3\n";
        file_put_contents($logFile, $content);
        touch($logFile, time() - (10 * 24 * 60 * 60));

        $this->service->rotate($this->tempDir);

        $decompressed = gzdecode(file_get_contents($logFile . '.gz'));
        $this->assertEquals($content, $decompressed);
    }
}
