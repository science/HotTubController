<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\CrontabBackupService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CrontabBackupServiceTest extends TestCase
{
    private string $backupDir;
    private CrontabBackupService $backupService;

    protected function setUp(): void
    {
        $this->backupDir = sys_get_temp_dir() . '/crontab-backup-test-' . uniqid();
        mkdir($this->backupDir, 0755, true);

        $this->backupService = new CrontabBackupService($this->backupDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->backupDir);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    // ========== backup() Tests ==========

    public function testBackupCreatesTimestampedFile(): void
    {
        $crontabContent = "SHELL=\"/bin/bash\"\n30 1 * * 0 /path/to/script.sh\n";

        $backupPath = $this->backupService->backup($crontabContent);

        $this->assertFileExists($backupPath);
        $this->assertStringStartsWith($this->backupDir . '/crontab-', $backupPath);
        $this->assertStringEndsWith('.txt', $backupPath);
    }

    public function testBackupPreservesContent(): void
    {
        $crontabContent = "SHELL=\"/bin/bash\"\n30 1 * * 0 /path/to/script.sh\n";

        $backupPath = $this->backupService->backup($crontabContent);

        $this->assertEquals($crontabContent, file_get_contents($backupPath));
    }

    public function testBackupReturnsUniquePathsForMultipleBackups(): void
    {
        $content = "test content\n";

        $path1 = $this->backupService->backup($content);
        usleep(1100000); // Sleep 1.1 seconds to ensure different timestamp
        $path2 = $this->backupService->backup($content);

        $this->assertNotEquals($path1, $path2);
    }

    public function testBackupCreatesDirectoryIfNotExists(): void
    {
        $nonExistentDir = $this->backupDir . '/subdir/deep';
        $service = new CrontabBackupService($nonExistentDir);

        $backupPath = $service->backup("test\n");

        $this->assertFileExists($backupPath);
        $this->assertDirectoryExists($nonExistentDir);
    }

    public function testBackupSkipsEmptyContent(): void
    {
        $backupPath = $this->backupService->backup('');

        $this->assertNull($backupPath);
    }

    public function testBackupSkipsWhitespaceOnlyContent(): void
    {
        $backupPath = $this->backupService->backup("   \n\n  \t  \n");

        $this->assertNull($backupPath);
    }

    // ========== listBackups() Tests ==========

    public function testListBackupsReturnsEmptyArrayWhenNoBackups(): void
    {
        $backups = $this->backupService->listBackups();

        $this->assertIsArray($backups);
        $this->assertEmpty($backups);
    }

    public function testListBackupsReturnsAllBackupFiles(): void
    {
        $this->backupService->backup("content1\n");
        usleep(1100000);
        $this->backupService->backup("content2\n");

        $backups = $this->backupService->listBackups();

        $this->assertCount(2, $backups);
    }

    public function testListBackupsSortsByDateDescending(): void
    {
        $path1 = $this->backupService->backup("older\n");
        usleep(1100000);
        $path2 = $this->backupService->backup("newer\n");

        $backups = $this->backupService->listBackups();

        // Newer should be first
        $this->assertEquals(basename($path2), $backups[0]['filename']);
        $this->assertEquals(basename($path1), $backups[1]['filename']);
    }

    public function testListBackupsIncludesMetadata(): void
    {
        $this->backupService->backup("test content\n");

        $backups = $this->backupService->listBackups();

        $this->assertArrayHasKey('filename', $backups[0]);
        $this->assertArrayHasKey('path', $backups[0]);
        $this->assertArrayHasKey('size', $backups[0]);
        $this->assertArrayHasKey('created', $backups[0]);
    }

    // ========== cleanup() Tests ==========

    public function testCleanupDeletesFilesOlderThanThreshold(): void
    {
        // Create a backup and manually set its mtime to 31 days ago
        $path = $this->backupService->backup("old content\n");
        touch($path, time() - (31 * 24 * 60 * 60));

        $deleted = $this->backupService->cleanup(daysToKeep: 30);

        $this->assertCount(1, $deleted);
        $this->assertFileDoesNotExist($path);
    }

    public function testCleanupPreservesRecentFiles(): void
    {
        // Create a recent backup
        $path = $this->backupService->backup("recent content\n");

        $deleted = $this->backupService->cleanup(daysToKeep: 30);

        $this->assertEmpty($deleted);
        $this->assertFileExists($path);
    }

    public function testCleanupCompressesFilesOlderThanCompressThreshold(): void
    {
        // Create a backup and set its mtime to 8 days ago
        $path = $this->backupService->backup("should compress\n");
        touch($path, time() - (8 * 24 * 60 * 60));

        $compressed = $this->backupService->cleanup(daysToKeep: 30, daysToCompress: 7);

        $this->assertFileDoesNotExist($path);
        $this->assertFileExists($path . '.gz');
    }

    public function testCleanupDoesNotRecompressAlreadyCompressedFiles(): void
    {
        // Create a backup, compress it, and set old mtime
        $path = $this->backupService->backup("already compressed\n");
        $gzPath = $path . '.gz';
        file_put_contents($gzPath, gzencode(file_get_contents($path)));
        unlink($path);
        touch($gzPath, time() - (8 * 24 * 60 * 60));

        $sizeBefore = filesize($gzPath);
        $this->backupService->cleanup(daysToKeep: 30, daysToCompress: 7);

        $this->assertFileExists($gzPath);
        $this->assertEquals($sizeBefore, filesize($gzPath));
    }

    public function testCleanupDeletesOldCompressedFiles(): void
    {
        // Create a compressed file older than retention
        $path = $this->backupDir . '/crontab-2025-01-01-120000.txt.gz';
        file_put_contents($path, gzencode("old compressed\n"));
        touch($path, time() - (100 * 24 * 60 * 60));

        $deleted = $this->backupService->cleanup(daysToKeep: 90);

        $this->assertCount(1, $deleted);
        $this->assertFileDoesNotExist($path);
    }
}
