<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTubController\Services\IftttWebhookClient;

/**
 * Comprehensive test suite for IftttWebhookClient
 *
 * These tests validate the client behavior, safety features,
 * test mode detection, and audit logging capabilities.
 */
class IftttWebhookClientTest extends TestCase
{
    private string $testAuditLogPath;

    protected function setUp(): void
    {
        // Use a temporary file for audit logging during tests
        $this->testAuditLogPath = sys_get_temp_dir() . '/ifttt-test-audit-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        // Clean up audit log file
        if (file_exists($this->testAuditLogPath)) {
            unlink($this->testAuditLogPath);
        }
    }

    /**
     * Test client initialization with valid API key
     */
    public function testClientInitializationWithValidApiKey(): void
    {
        $client = new IftttWebhookClient('valid-key-123', 30, false, $this->testAuditLogPath);

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertFalse($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test client initialization with null API key triggers test mode
     */
    public function testClientInitializationWithNullApiKeyTriggersTestMode(): void
    {
        $client = new IftttWebhookClient(null, 30, false, $this->testAuditLogPath);

        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test client initialization with empty API key triggers test mode
     */
    public function testClientInitializationWithEmptyApiKeyTriggersTestMode(): void
    {
        $client = new IftttWebhookClient('', 30, false, $this->testAuditLogPath);

        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test client initialization with dry run mode
     */
    public function testClientInitializationWithDryRunMode(): void
    {
        $client = new IftttWebhookClient('valid-key-123', 30, true, $this->testAuditLogPath);

        $this->assertFalse($client->isTestMode());
        $this->assertTrue($client->isDryRun());
    }

    /**
     * Test client initialization with both test mode and dry run
     */
    public function testClientInitializationWithTestModeAndDryRun(): void
    {
        $client = new IftttWebhookClient(null, 30, true, $this->testAuditLogPath);

        $this->assertTrue($client->isTestMode());
        $this->assertTrue($client->isDryRun());
    }

    /**
     * Test audit logging during initialization
     */
    public function testAuditLoggingDuringInitialization(): void
    {
        new IftttWebhookClient('test-key', 30, false, $this->testAuditLogPath);

        $this->assertFileExists($this->testAuditLogPath);

        $logContent = file_get_contents($this->testAuditLogPath);
        $logLines = explode("\n", trim($logContent));

        // Should have at least one log entry
        $this->assertGreaterThan(0, count($logLines));

        // First line should be initialization log
        $firstLog = json_decode($logLines[0], true);
        $this->assertEquals('INIT', $firstLog['action']);
        $this->assertEquals(false, $firstLog['context']['test_mode']);
        $this->assertEquals(false, $firstLog['context']['dry_run']);
        $this->assertEquals(true, $firstLog['context']['has_api_key']);
    }

    /**
     * Test trigger method in test mode returns simulated success
     */
    public function testTriggerInTestModeReturnsSimulatedSuccess(): void
    {
        $client = new IftttWebhookClient(null, 30, false, $this->testAuditLogPath);

        $result = $client->trigger('test-event');

        $this->assertTrue($result);

        // Check audit log for simulation
        $logContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $logContent);
        $this->assertStringContainsString('test-event', $logContent);
    }

    /**
     * Test trigger method in dry run mode returns simulated success
     */
    public function testTriggerInDryRunModeReturnsSimulatedSuccess(): void
    {
        $client = new IftttWebhookClient('valid-key', 30, true, $this->testAuditLogPath);

        $result = $client->trigger('test-event');

        $this->assertTrue($result);

        // Check audit log for simulation
        $logContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $logContent);
        $this->assertStringContainsString('test-event', $logContent);
    }

    /**
     * Test hot tub control methods in test mode
     */
    public function testHotTubControlMethodsInTestMode(): void
    {
        $client = new IftttWebhookClient(null, 30, false, $this->testAuditLogPath);

        $this->assertTrue($client->startHeating());
        $this->assertTrue($client->stopHeating());
        $this->assertTrue($client->startIonizer());
        $this->assertTrue($client->stopIonizer());

        // Check that all operations were logged
        $logContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('hot-tub-heat-on', $logContent);
        $this->assertStringContainsString('hot-tub-heat-off', $logContent);
        $this->assertStringContainsString('turn-on-hot-tub-ionizer', $logContent);
        $this->assertStringContainsString('turn-off-hot-tub-ionizer', $logContent);
    }

    /**
     * Test connectivity test in test mode
     */
    public function testConnectivityTestInTestMode(): void
    {
        $client = new IftttWebhookClient(null, 30, false, $this->testAuditLogPath);

        $result = $client->testConnectivity();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('available', $result);
        $this->assertArrayHasKey('tested_at', $result);
        $this->assertArrayHasKey('test_mode', $result);
        $this->assertArrayHasKey('dry_run', $result);

        $this->assertFalse($result['available']);
        $this->assertTrue($result['test_mode']);
        $this->assertEquals('Test mode - IFTTT API key not available', $result['error']);
    }

    /**
     * Test connectivity test in dry run mode
     */
    public function testConnectivityTestInDryRunMode(): void
    {
        $client = new IftttWebhookClient('valid-key', 30, true, $this->testAuditLogPath);

        $result = $client->testConnectivity();

        $this->assertIsArray($result);
        $this->assertTrue($result['available']);
        $this->assertFalse($result['test_mode']);
        $this->assertTrue($result['dry_run']);
        $this->assertEquals(150, $result['response_time_ms']);
    }

    /**
     * Test audit logging creates directory if it doesn't exist
     */
    public function testAuditLoggingCreatesDirectoryIfNotExists(): void
    {
        $nonExistentDir = sys_get_temp_dir() . '/non-existent-' . uniqid();
        $auditPath = $nonExistentDir . '/audit.log';

        new IftttWebhookClient('test-key', 30, false, $auditPath);

        $this->assertDirectoryExists($nonExistentDir);
        $this->assertFileExists($auditPath);

        // Clean up
        unlink($auditPath);
        rmdir($nonExistentDir);
    }

    /**
     * Test that audit log contains proper JSON structure
     */
    public function testAuditLogContainsProperJsonStructure(): void
    {
        $client = new IftttWebhookClient('test-key', 30, false, $this->testAuditLogPath);
        $client->trigger('test-event');

        $logContent = file_get_contents($this->testAuditLogPath);
        $logLines = explode("\n", trim($logContent));

        foreach ($logLines as $line) {
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);

            $this->assertNotNull($decoded, "Log line should be valid JSON: {$line}");
            $this->assertArrayHasKey('timestamp', $decoded);
            $this->assertArrayHasKey('action', $decoded);
            $this->assertArrayHasKey('context', $decoded);
            $this->assertArrayHasKey('environment', $decoded);
        }
    }

    /**
     * Test default timeout value
     */
    public function testDefaultTimeoutValue(): void
    {
        // This test verifies the constructor accepts the timeout parameter correctly
        // We can't directly test the timeout without making actual HTTP calls,
        // but we can verify the client initializes without errors
        $client = new IftttWebhookClient('test-key', 60, false, $this->testAuditLogPath);

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
    }

    /**
     * Test that simulated triggers have realistic timing
     */
    public function testSimulatedTriggersHaveRealisticTiming(): void
    {
        $client = new IftttWebhookClient(null, 30, false, $this->testAuditLogPath);

        $startTime = microtime(true);
        $result = $client->trigger('test-event');
        $endTime = microtime(true);

        $this->assertTrue($result);

        // Simulated trigger should take at least 100ms (as per the implementation)
        $duration = ($endTime - $startTime) * 1000;
        $this->assertGreaterThan(90, $duration, 'Simulated trigger should take realistic time');
        $this->assertLessThan(200, $duration, 'Simulated trigger should not take too long');
    }
}
