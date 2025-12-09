<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Domain\Storage;

use PHPUnit\Framework\TestCase;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use HotTubController\Domain\Storage\StorageException;

class JsonStorageManagerTest extends TestCase
{
    private string $testStoragePath;
    private JsonStorageManager $storageManager;

    protected function setUp(): void
    {
        $this->testStoragePath = sys_get_temp_dir() . '/storage_test_' . uniqid();
        $this->storageManager = new JsonStorageManager($this->testStoragePath, [
            'rotation' => [
                'strategy' => 'size',
                'max_size' => 1024, // 1KB for testing
                'retention_days' => 1,
                'compress_after_days' => 1,
            ],
            'locking' => [
                'enabled' => false, // Disable for unit tests
            ],
        ]);
    }

    protected function tearDown(): void
    {
        try {
            $this->removeDirectory($this->testStoragePath);
        } catch (Exception $e) {
            // Log error but don't fail test teardown
            error_log("Failed to clean up test storage directory: " . $e->getMessage());
        }
    }

    public function testSaveAndLoadBasicData(): void
    {
        $data = ['key1' => 'value1', 'key2' => 'value2'];

        $this->assertTrue($this->storageManager->save('test_key', $data));
        $this->assertEquals($data, $this->storageManager->load('test_key'));
    }

    public function testLoadNonExistentFile(): void
    {
        $result = $this->storageManager->load('non_existent');
        $this->assertEquals([], $result);
    }

    public function testSaveEmptyArray(): void
    {
        $this->assertTrue($this->storageManager->save('empty', []));
        $this->assertEquals([], $this->storageManager->load('empty'));
    }

    public function testExists(): void
    {
        $this->assertFalse($this->storageManager->exists('test_key'));

        $this->storageManager->save('test_key', ['data']);
        $this->assertTrue($this->storageManager->exists('test_key'));
    }

    public function testDelete(): void
    {
        $this->storageManager->save('test_key', ['data']);
        $this->assertTrue($this->storageManager->exists('test_key'));

        $this->assertTrue($this->storageManager->delete('test_key'));
        $this->assertFalse($this->storageManager->exists('test_key'));
    }

    public function testDeleteNonExistentFile(): void
    {
        $this->assertTrue($this->storageManager->delete('non_existent'));
    }

    public function testNestedDirectoryCreation(): void
    {
        $data = ['nested' => 'data'];

        $this->assertTrue($this->storageManager->save('level1/level2/test', $data));
        $this->assertEquals($data, $this->storageManager->load('level1/level2/test'));
    }

    public function testInvalidJsonHandling(): void
    {
        $filePath = $this->testStoragePath . '/invalid.json';
        mkdir(dirname($filePath), 0755, true);
        file_put_contents($filePath, '{"invalid": json}');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessageMatches('/Invalid JSON/');

        $this->storageManager->load('invalid');
    }

    public function testRotationByKey(): void
    {
        // Test that rotated keys use date-based filenames
        $data = ['rotated' => 'data'];

        $this->storageManager->save('test_rotated', $data);

        $expectedFile = $this->testStoragePath . '/' . date('Y-m-d') . '.json';
        $this->assertFileExists($expectedFile);
        $this->assertEquals($data, json_decode(file_get_contents($expectedFile), true));
    }

    public function testCleanupOldFiles(): void
    {
        // Create a subdirectory to match the expected structure
        $subDir = $this->testStoragePath . '/test_category';
        mkdir($subDir, 0755, true);

        // Create old file in subdirectory
        $oldDate = date('Y-m-d', strtotime('-2 days'));
        $oldFilePath = $subDir . '/' . $oldDate . '.json';
        file_put_contents($oldFilePath, json_encode(['old' => 'data']));

        // Create current file
        $currentData = ['current' => 'data'];
        $this->storageManager->save('current', $currentData);

        // Run cleanup
        $deleted = $this->storageManager->cleanup();

        $this->assertEquals(1, $deleted);
        $this->assertFileDoesNotExist($oldFilePath);
        $this->assertEquals($currentData, $this->storageManager->load('current'));
    }

    public function testComplexDataStructures(): void
    {
        $complexData = [
            'string' => 'test',
            'integer' => 123,
            'float' => 45.67,
            'boolean' => true,
            'null' => null,
            'array' => [1, 2, 3],
            'nested' => [
                'deep' => [
                    'structure' => 'works'
                ]
            ]
        ];

        $this->assertTrue($this->storageManager->save('complex', $complexData));
        $this->assertEquals($complexData, $this->storageManager->load('complex'));
    }

    public function testConcurrentAccess(): void
    {
        // Enable locking for this test
        $storageManager = new JsonStorageManager($this->testStoragePath, [
            'locking' => ['enabled' => true, 'timeout' => 1]
        ]);

        $data1 = ['writer1' => 'data'];
        $data2 = ['writer2' => 'data'];

        $this->assertTrue($storageManager->save('concurrent_test', $data1));
        $this->assertTrue($storageManager->save('concurrent_test', $data2));

        $result = $storageManager->load('concurrent_test');
        $this->assertTrue($result === $data1 || $result === $data2);
    }

    public function testLargDataHandling(): void
    {
        // Create array with 1000 items to test performance
        $largeData = [];
        for ($i = 0; $i < 1000; $i++) {
            $largeData["item_$i"] = [
                'id' => $i,
                'name' => "Item $i",
                'description' => str_repeat('x', 100),
                'metadata' => ['key' => 'value']
            ];
        }

        $startTime = microtime(true);
        $this->assertTrue($this->storageManager->save('large_data', $largeData));
        $saveTime = microtime(true) - $startTime;

        $startTime = microtime(true);
        $loadedData = $this->storageManager->load('large_data');
        $loadTime = microtime(true) - $startTime;

        $this->assertEquals($largeData, $loadedData);
        $this->assertLessThan(1.0, $saveTime, 'Save should complete within 1 second');
        $this->assertLessThan(1.0, $loadTime, 'Load should complete within 1 second');
    }

    public function testUnicodeSupport(): void
    {
        $unicodeData = [
            'english' => 'Hello World',
            'emoji' => '🔥🚀✨',
            'chinese' => '你好世界',
            'japanese' => 'こんにちは世界',
            'special_chars' => '!@#$%^&*(){}[]|\\:";\'<>?,./',
        ];

        $this->assertTrue($this->storageManager->save('unicode_test', $unicodeData));
        $this->assertEquals($unicodeData, $this->storageManager->load('unicode_test'));
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $files = @scandir($path);
        if ($files === false) {
            throw new Exception("Cannot read directory: $path");
        }

        $files = array_diff($files, ['.', '..']);
        foreach ($files as $file) {
            $filePath = $path . '/' . $file;
            if (is_dir($filePath)) {
                $this->removeDirectory($filePath);
            } else {
                if (!@unlink($filePath)) {
                    throw new Exception("Cannot delete file: $filePath");
                }
            }
        }

        if (!@rmdir($path)) {
            throw new Exception("Cannot remove directory: $path");
        }
    }
}
