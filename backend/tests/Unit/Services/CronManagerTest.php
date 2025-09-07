<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use HotTubController\Services\CronManager;
use DateTime;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for CronManager
 * 
 * These tests verify the cron management functionality without actually
 * modifying the system crontab. We mock the crontab operations for safety.
 */
class CronManagerTest extends TestCase
{
    private CronManager $cronManager;
    private string $testProjectRoot;
    
    protected function setUp(): void
    {
        $this->testProjectRoot = sys_get_temp_dir() . '/hot-tub-test-' . uniqid();
        mkdir($this->testProjectRoot, 0755, true);
        
        $this->cronManager = new CronManager($this->testProjectRoot);
    }
    
    protected function tearDown(): void
    {
        // Clean up test directory
        $this->removeDirectory($this->testProjectRoot);
    }
    
    public function testConstructorCreatesDirectories(): void
    {
        $binDir = $this->testProjectRoot . '/storage/bin';
        $configDir = $this->testProjectRoot . '/storage/curl-configs';
        $logDir = $this->testProjectRoot . '/storage/logs';
        
        $this->assertDirectoryExists($binDir);
        $this->assertDirectoryExists($configDir);
        $this->assertDirectoryExists($logDir);
        
        // Check config directory has restrictive permissions
        $permissions = substr(sprintf('%o', fileperms($configDir)), -4);
        $this->assertEquals('0700', $permissions);
    }
    
    public function testValidateTag(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('validateTag');
        $method->setAccessible(true);
        
        // Valid tags should not throw
        $method->invoke($this->cronManager, 'HOT_TUB_START');
        $method->invoke($this->cronManager, 'HOT_TUB_MONITOR');
        
        // Invalid tag should throw
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($this->cronManager, 'INVALID_TAG');
    }
    
    public function testValidateIdentifier(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('validateIdentifier');
        $method->setAccessible(true);
        
        // Valid identifiers should not throw
        $method->invoke($this->cronManager, 'test123');
        $method->invoke($this->cronManager, 'heating-cycle-456');
        $method->invoke($this->cronManager, 'event_789');
        
        // Invalid characters
        $this->expectException(\InvalidArgumentException::class);
        $method->invoke($this->cronManager, 'invalid@id');
    }
    
    public function testValidateIdentifierTooLong(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('validateIdentifier');
        $method->setAccessible(true);
        
        $longId = str_repeat('a', 51); // 51 chars, over the 50 limit
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Identifier too long');
        $method->invoke($this->cronManager, $longId);
    }
    
    public function testBuildCronExpression(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('buildCronExpression');
        $method->setAccessible(true);
        
        $dateTime = new DateTime('2025-09-07 14:30:00');
        $cronExpression = $method->invoke($this->cronManager, $dateTime);
        
        $this->assertEquals('30 14 7 9 *', $cronExpression);
    }
    
    public function testBuildCronCommand(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('buildCronCommand');
        $method->setAccessible(true);
        
        $cronId = 'HOT_TUB_START:test123';
        $configFile = '/path/to/config.conf';
        
        $command = $method->invoke($this->cronManager, $cronId, $configFile);
        
        $expectedWrapperPath = $this->testProjectRoot . '/storage/bin/cron-wrapper.sh';
        $expectedCommand = "'{$expectedWrapperPath}' \"'{$cronId}'\" \"'{$configFile}'\" >/dev/null 2>&1";
        
        $this->assertEquals($expectedCommand, $command);
    }
    
    public function testIsCronLine(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('isCronLine');
        $method->setAccessible(true);
        
        // Valid cron lines
        $this->assertTrue($method->invoke($this->cronManager, '0 6 * * * /usr/bin/command'));
        $this->assertTrue($method->invoke($this->cronManager, '*/15 * * * * echo test'));
        
        // Invalid cron lines
        $this->assertFalse($method->invoke($this->cronManager, '# This is a comment'));
        $this->assertFalse($method->invoke($this->cronManager, ''));
        $this->assertFalse($method->invoke($this->cronManager, '   '));
    }
    
    public function testIsApplicationCron(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('isApplicationCron');
        $method->setAccessible(true);
        
        // Application crons
        $this->assertTrue($method->invoke(
            $this->cronManager, 
            '0 6 * * * /command # HOT_TUB_START:test123'
        ));
        $this->assertTrue($method->invoke(
            $this->cronManager, 
            '*/5 * * * * /monitor # HOT_TUB_MONITOR:monitor456'
        ));
        
        // Non-application crons
        $this->assertFalse($method->invoke(
            $this->cronManager, 
            '0 3 * * * certbot renew'
        ));
        $this->assertFalse($method->invoke(
            $this->cronManager, 
            '0 6 * * * /usr/bin/backup # Daily backup'
        ));
    }
    
    public function testMatchesTagPattern(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('matchesTagPattern');
        $method->setAccessible(true);
        
        $cronLine = '0 6 * * * /command # HOT_TUB_START:test123';
        
        // Should match exact patterns
        $this->assertTrue($method->invoke($this->cronManager, $cronLine, 'HOT_TUB_START'));
        $this->assertTrue($method->invoke($this->cronManager, $cronLine, 'HOT_TUB_START:test123'));
        
        // Should not match different patterns
        $this->assertFalse($method->invoke($this->cronManager, $cronLine, 'HOT_TUB_MONITOR'));
        $this->assertFalse($method->invoke($this->cronManager, $cronLine, 'HOT_TUB_START:different'));
    }
    
    public function testParseCronLine(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('parseCronLine');
        $method->setAccessible(true);
        
        $cronLine = '30 14 7 9 * /usr/bin/wrapper "HOT_TUB_START:test123" "/config.conf" # HOT_TUB_START:test123';
        
        $result = $method->invoke($this->cronManager, $cronLine);
        
        $this->assertIsArray($result);
        $this->assertEquals('HOT_TUB_START:test123', $result['tag']);
        $this->assertEquals('30 14 7 9 *', $result['cron_expression']);
        $this->assertStringContainsString('/usr/bin/wrapper', $result['command']);
        $this->assertEquals($cronLine, $result['full_line']);
    }
    
    public function testParseCronLineInvalid(): void
    {
        $reflection = new \ReflectionClass($this->cronManager);
        $method = $reflection->getMethod('parseCronLine');
        $method->setAccessible(true);
        
        // Line without proper comment
        $invalidLine = '30 14 7 9 * /usr/bin/command';
        
        $result = $method->invoke($this->cronManager, $invalidLine);
        $this->assertNull($result);
    }
    
    public function testAddSelfDeletingCronValidation(): void
    {
        // Test with non-existent config file
        $executionTime = new DateTime('+1 hour');
        $nonExistentConfig = '/path/to/nonexistent/config.conf';
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Curl config file does not exist');
        
        $this->cronManager->addSelfDeletingCron(
            $executionTime,
            $nonExistentConfig,
            'HOT_TUB_START',
            'test123'
        );
    }
    
    public function testAddSelfDeletingCronValidatesInputs(): void
    {
        // Create a temporary config file
        $configFile = $this->testProjectRoot . '/storage/curl-configs/test-config.conf';
        file_put_contents($configFile, "test config content\n");
        
        $executionTime = new DateTime('+1 hour');
        
        // Test that the method properly validates inputs and constructs the cron ID
        // We'll use reflection to test just the validation and ID generation parts
        $reflection = new \ReflectionClass($this->cronManager);
        $validateTagMethod = $reflection->getMethod('validateTag');
        $validateIdMethod = $reflection->getMethod('validateIdentifier');
        $buildExpressionMethod = $reflection->getMethod('buildCronExpression');
        $buildCommandMethod = $reflection->getMethod('buildCronCommand');
        
        $validateTagMethod->setAccessible(true);
        $validateIdMethod->setAccessible(true);
        $buildExpressionMethod->setAccessible(true);
        $buildCommandMethod->setAccessible(true);
        
        // These should not throw exceptions
        $validateTagMethod->invoke($this->cronManager, 'HOT_TUB_START');
        $validateIdMethod->invoke($this->cronManager, 'test123');
        
        $cronExpression = $buildExpressionMethod->invoke($this->cronManager, $executionTime);
        $this->assertNotEmpty($cronExpression);
        
        $cronId = 'HOT_TUB_START:test123';
        $command = $buildCommandMethod->invoke($this->cronManager, $cronId, $configFile);
        $this->assertStringContainsString('cron-wrapper.sh', $command);
        $this->assertStringContainsString($cronId, $command);
        $this->assertStringContainsString($configFile, $command);
        
        // Verify the config file exists and is readable
        $this->assertFileExists($configFile);
        $this->assertFileIsReadable($configFile);
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