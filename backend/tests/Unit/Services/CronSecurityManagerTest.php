<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use HotTubController\Services\CronSecurityManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CronSecurityManager
 * 
 * These tests verify the API key generation, validation, and storage
 * functionality for the cron authentication system.
 */
class CronSecurityManagerTest extends TestCase
{
    private CronSecurityManager $securityManager;
    private string $testProjectRoot;
    private string $testApiKeyFile;
    
    protected function setUp(): void
    {
        $this->testProjectRoot = sys_get_temp_dir() . '/cron-security-test-' . uniqid();
        mkdir($this->testProjectRoot, 0755, true);
        
        $this->securityManager = new CronSecurityManager($this->testProjectRoot);
        $this->testApiKeyFile = $this->testProjectRoot . '/storage/cron-api-key.txt';
    }
    
    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testProjectRoot);
    }
    
    public function testConstructorCreatesDirectories(): void
    {
        $storageDir = $this->testProjectRoot . '/storage';
        $logsDir = $this->testProjectRoot . '/storage/logs';
        
        $this->assertDirectoryExists($storageDir);
        $this->assertDirectoryExists($logsDir);
    }
    
    public function testGenerateApiKeyFormat(): void
    {
        $apiKey = $this->securityManager->generateApiKey(false);
        
        // Check format: should start with 'cron_api_' and be followed by 64 hex chars
        $this->assertStringStartsWith('cron_api_', $apiKey);
        $this->assertEquals(73, strlen($apiKey)); // 'cron_api_' (9) + 64 hex chars = 73
        
        // Extract hex part and verify it's valid hex
        $hexPart = substr($apiKey, 9);
        $this->assertEquals(64, strlen($hexPart));
        $this->assertTrue(ctype_xdigit($hexPart));
    }
    
    public function testGenerateApiKeyCreatesFile(): void
    {
        $this->assertFalse(file_exists($this->testApiKeyFile));
        
        $apiKey = $this->securityManager->generateApiKey(false);
        
        $this->assertFileExists($this->testApiKeyFile);
        $this->assertEquals($apiKey, trim(file_get_contents($this->testApiKeyFile)));
        
        // Check file permissions are restrictive
        $permissions = substr(sprintf('%o', fileperms($this->testApiKeyFile)), -4);
        $this->assertEquals('0600', $permissions);
    }
    
    public function testApiKeyExists(): void
    {
        $this->assertFalse($this->securityManager->apiKeyExists());
        
        $this->securityManager->generateApiKey(false);
        
        $this->assertTrue($this->securityManager->apiKeyExists());
    }
    
    public function testGetCurrentApiKey(): void
    {
        // Should throw when no key exists
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cron API key file not found');
        $this->securityManager->getCurrentApiKey();
    }
    
    public function testGetCurrentApiKeyValid(): void
    {
        $originalKey = $this->securityManager->generateApiKey(false);
        $retrievedKey = $this->securityManager->getCurrentApiKey();
        
        $this->assertEquals($originalKey, $retrievedKey);
    }
    
    public function testGetCurrentApiKeyEmpty(): void
    {
        // Create empty key file
        file_put_contents($this->testApiKeyFile, '');
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cron API key file is empty');
        $this->securityManager->getCurrentApiKey();
    }
    
    public function testGetCurrentApiKeyInvalidFormat(): void
    {
        // Create key file with invalid content
        file_put_contents($this->testApiKeyFile, 'invalid_api_key_format');
        chmod($this->testApiKeyFile, 0600);
        
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid API key format in file');
        $this->securityManager->getCurrentApiKey();
    }
    
    public function testIsValidApiKey(): void
    {
        // Valid key format
        $validKey = 'cron_api_' . bin2hex(random_bytes(32));
        $this->assertTrue($this->securityManager->isValidApiKey($validKey));
        
        // Invalid formats
        $this->assertFalse($this->securityManager->isValidApiKey('wrong_prefix_' . bin2hex(random_bytes(32))));
        $this->assertFalse($this->securityManager->isValidApiKey('cron_api_tooshort'));
        $this->assertFalse($this->securityManager->isValidApiKey('cron_api_' . str_repeat('z', 64))); // non-hex
        $this->assertFalse($this->securityManager->isValidApiKey('cron_api_' . bin2hex(random_bytes(31)))); // wrong length
    }
    
    public function testVerifyApiKey(): void
    {
        $apiKey = $this->securityManager->generateApiKey(false);
        
        // Correct key should verify
        $this->assertTrue($this->securityManager->verifyApiKey($apiKey));
        
        // Wrong key should not verify
        $wrongKey = 'cron_api_' . bin2hex(random_bytes(32));
        $this->assertFalse($this->securityManager->verifyApiKey($wrongKey));
        
        // Empty key should not verify
        $this->assertFalse($this->securityManager->verifyApiKey(''));
    }
    
    public function testVerifyApiKeyNoFile(): void
    {
        // Should return false when no key file exists
        $this->assertFalse($this->securityManager->verifyApiKey('any_key'));
    }
    
    public function testRotateApiKey(): void
    {
        $firstKey = $this->securityManager->generateApiKey(false);
        
        $rotationResult = $this->securityManager->rotateApiKey();
        
        $this->assertArrayHasKey('old_key', $rotationResult);
        $this->assertArrayHasKey('new_key', $rotationResult);
        $this->assertEquals($firstKey, $rotationResult['old_key']);
        $this->assertNotEquals($firstKey, $rotationResult['new_key']);
        
        // Verify new key is stored
        $currentKey = $this->securityManager->getCurrentApiKey();
        $this->assertEquals($rotationResult['new_key'], $currentKey);
        
        // Check that backup was created
        $backupFiles = glob($this->testApiKeyFile . '.backup_*');
        $this->assertCount(1, $backupFiles);
    }
    
    public function testInitializeApiKeyNoExisting(): void
    {
        $apiKey = $this->securityManager->initializeApiKey();
        
        $this->assertTrue($this->securityManager->isValidApiKey($apiKey));
        $this->assertTrue($this->securityManager->apiKeyExists());
        $this->assertEquals($apiKey, $this->securityManager->getCurrentApiKey());
    }
    
    public function testInitializeApiKeyWithExisting(): void
    {
        $firstKey = $this->securityManager->generateApiKey(false);
        $initKey = $this->securityManager->initializeApiKey();
        
        // Should return existing key, not generate new one
        $this->assertEquals($firstKey, $initKey);
    }
    
    public function testGetApiKeyInfo(): void
    {
        $info = $this->securityManager->getApiKeyInfo();
        
        // When no key exists
        $this->assertFalse($info['exists']);
        $this->assertFalse($info['readable']);
        $this->assertFalse($info['valid_format']);
        
        // After generating key
        $apiKey = $this->securityManager->generateApiKey(false);
        $info = $this->securityManager->getApiKeyInfo();
        
        $this->assertTrue($info['exists']);
        $this->assertTrue($info['readable']);
        $this->assertTrue($info['writable']);
        $this->assertTrue($info['valid_format']);
        $this->assertEquals('0600', $info['permissions']);
        $this->assertGreaterThan(0, $info['size']);
        $this->assertNotNull($info['modified']);
        $this->assertStringStartsWith('cron_api_', $info['key_preview']);
    }
    
    public function testCleanupOldBackups(): void
    {
        // Generate initial key
        $this->securityManager->generateApiKey(false);
        
        // Create some backup files with different ages
        $oldBackup = $this->testApiKeyFile . '.backup_old';
        $recentBackup = $this->testApiKeyFile . '.backup_recent';
        
        file_put_contents($oldBackup, 'old_backup_content');
        file_put_contents($recentBackup, 'recent_backup_content');
        
        // Set old backup to be older than 30 days
        $oldTime = time() - (31 * 24 * 60 * 60);
        touch($oldBackup, $oldTime);
        
        $removedCount = $this->securityManager->cleanupOldBackups(30);
        
        $this->assertEquals(1, $removedCount);
        $this->assertFileDoesNotExist($oldBackup);
        $this->assertFileExists($recentBackup);
    }
    
    public function testBackupExistingKey(): void
    {
        $firstKey = $this->securityManager->generateApiKey(false);
        
        // Generate another key with backup enabled
        $secondKey = $this->securityManager->generateApiKey(true);
        
        $this->assertNotEquals($firstKey, $secondKey);
        
        // Check that backup was created
        $backupFiles = glob($this->testApiKeyFile . '.backup_*');
        $this->assertCount(1, $backupFiles);
        
        // Verify backup contains the first key
        $backupContent = trim(file_get_contents($backupFiles[0]));
        $this->assertEquals($firstKey, $backupContent);
    }
    
    /**
     * Helper method to recursively remove test directory
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        
        rmdir($dir);
    }
}