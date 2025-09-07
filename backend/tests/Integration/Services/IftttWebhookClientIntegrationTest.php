<?php

declare(strict_types=1);

namespace Tests\Integration\Services;

use PHPUnit\Framework\TestCase;
use HotTubController\Services\IftttWebhookClient;
use HotTubController\Services\IftttWebhookClientFactory;
use VCR\VCR;

/**
 * Integration tests for IFTTT Webhook Client using VCR cassettes
 * 
 * These tests use recorded HTTP interactions to test the client behavior
 * without making actual API calls during testing.
 */
class IftttWebhookClientIntegrationTest extends TestCase
{
    private string $testAuditLogPath;
    private array $originalEnv;
    
    protected function setUp(): void
    {
        // Save original environment
        $this->originalEnv = $_ENV;
        
        // Set up test environment
        $_ENV['APP_ENV'] = 'testing';
        unset($_ENV['IFTTT_WEBHOOK_KEY']); // Ensure no API key in test mode
        
        // Use a temporary file for audit logging during tests
        $this->testAuditLogPath = sys_get_temp_dir() . '/ifttt-integration-test-audit-' . uniqid() . '.log';
        
        // Configure VCR
        VCR::configure()
            ->setCassettePath(__DIR__ . '/../../cassettes/ifttt')
            ->setMode('once') // Use existing cassettes, don't record new ones
            ->enableRequestMatchers(['method', 'url'])
            ->enableLibraryHooks(['curl']);
    }
    
    protected function tearDown(): void
    {
        // Clean up audit log file
        if (file_exists($this->testAuditLogPath)) {
            unlink($this->testAuditLogPath);
        }
        
        // Turn off VCR
        VCR::turnOff();
        
        // Restore environment
        $_ENV = $this->originalEnv;
    }
    
    /**
     * Test factory creates safe client in test environment
     */
    public function testFactoryCreatesSafeClientInTestEnvironment(): void
    {
        $client = IftttWebhookClientFactory::create();
        
        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }
    
    /**
     * Test heating sequence in test mode (simulated)
     */
    public function testHeatingSequenceInTestMode(): void
    {
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Test starting heating
        $startResult = $client->startHeating();
        $this->assertTrue($startResult);
        
        // Test stopping heating
        $stopResult = $client->stopHeating();
        $this->assertTrue($stopResult);
        
        // Verify operations were logged as simulated
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-on', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-off', $auditLog);
    }
    
    /**
     * Test ionizer control in test mode (simulated)
     */
    public function testIonizerControlInTestMode(): void
    {
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Test starting ionizer
        $startResult = $client->startIonizer();
        $this->assertTrue($startResult);
        
        // Test stopping ionizer
        $stopResult = $client->stopIonizer();
        $this->assertTrue($stopResult);
        
        // Verify operations were logged as simulated
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('turn-on-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('turn-off-hot-tub-ionizer', $auditLog);
    }
    
    /**
     * Test heating sequence with simulated behavior (test mode)
     */
    public function testHeatingSequenceWithTestMode(): void
    {
        // In test environment, client should automatically enter test mode
        // regardless of API key provided, for safety
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Verify client is in safe mode
        $this->assertTrue($client->isTestMode());
        
        $result = $client->startHeating();
        $this->assertTrue($result);
        
        $result = $client->stopHeating();
        $this->assertTrue($result);
        
        // Verify simulated requests were logged (not real ones)
        $this->assertTrue(file_exists($this->testAuditLogPath), 'Audit log file should exist');
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertNotFalse($auditLog, 'Should be able to read audit log');
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-on', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-off', $auditLog);
        $this->assertStringContainsString('test_mode', $auditLog);
    }
    
    /**
     * Test ionizer control with simulated behavior (test mode)
     */
    public function testIonizerControlWithTestMode(): void
    {
        // In test environment, client should automatically enter test mode
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Verify client is in safe mode
        $this->assertTrue($client->isTestMode());
        
        $result = $client->startIonizer();
        $this->assertTrue($result);
        
        $result = $client->stopIonizer();
        $this->assertTrue($result);
        
        // Verify simulated requests were logged
        $this->assertTrue(file_exists($this->testAuditLogPath), 'Audit log file should exist');
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertNotFalse($auditLog, 'Should be able to read audit log');
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
        $this->assertStringContainsString('turn-on-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('turn-off-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('test_mode', $auditLog);
    }
    
    /**
     * Test complete hot tub cycle with simulated behavior (test mode)
     */
    public function testCompleteHotTubCycleWithTestMode(): void
    {
        // In test environment, client should automatically enter test mode
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Verify client is in safe mode
        $this->assertTrue($client->isTestMode());
        
        // Step 1: Start heating
        $this->assertTrue($client->startHeating());
        
        // Step 2: Start ionizer
        $this->assertTrue($client->startIonizer());
        
        // Step 3: Stop heating
        $this->assertTrue($client->stopHeating());
        
        // Step 4: Stop ionizer
        $this->assertTrue($client->stopIonizer());
        
        // Verify all simulated operations were logged
        $this->assertTrue(file_exists($this->testAuditLogPath), 'Audit log file should exist');
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertNotFalse($auditLog, 'Should be able to read audit log');
        $this->assertStringContainsString('hot-tub-heat-on', $auditLog);
        $this->assertStringContainsString('turn-on-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-off', $auditLog);
        $this->assertStringContainsString('turn-off-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
        
        // Count simulated triggers
        $triggerSimulatedCount = substr_count($auditLog, 'TRIGGER_SIMULATED');
        $this->assertEquals(4, $triggerSimulatedCount, 'Should have 4 simulated triggers');
    }
    
    /**
     * Test dry run mode prevents actual HTTP calls
     */
    public function testDryRunModePreventsActualHttpCalls(): void
    {
        // This client has an API key but is in dry run mode
        $client = new IftttWebhookClient('test-api-key', 30, true, $this->testAuditLogPath);
        
        // No VCR cassette needed - dry run should not make HTTP calls
        $result = $client->startHeating();
        
        $this->assertTrue($result);
        
        // Verify operation was simulated, not real
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
        $this->assertStringContainsString('"dry_run":true', $auditLog);
    }
    
    /**
     * Test connectivity test with different modes
     */
    public function testConnectivityTestWithDifferentModes(): void
    {
        // Test mode - no connectivity (in test environment, factory always creates test mode client)
        $testModeClient = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        $testResult = $testModeClient->testConnectivity();
        
        $this->assertFalse($testResult['available']);
        $this->assertTrue($testResult['test_mode']);
        $this->assertStringContainsString('Test mode', $testResult['error']);
        
        // Test createSafe (which creates test mode client due to null API key)
        $safeClient = IftttWebhookClientFactory::createSafe(true);
        $safeResult = $safeClient->testConnectivity();
        
        // createSafe always enters test mode because API key is null, regardless of dry run setting
        $this->assertFalse($safeResult['available']);
        $this->assertTrue($safeResult['test_mode']);
        $this->assertTrue($safeResult['dry_run']); // Still reports dry_run=true in results
        $this->assertEquals(0, $safeResult['response_time_ms']); // Test mode response time
    }
    
    /**
     * Test audit logging contains environment information
     */
    public function testAuditLoggingContainsEnvironmentInformation(): void
    {
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        $client->startHeating();
        
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('"environment":"testing"', $auditLog);
        $this->assertStringContainsString('TRIGGER_SIMULATED', $auditLog);
    }
    
    /**
     * Test that multiple operations create proper audit trail
     */
    public function testMultipleOperationsCreateProperAuditTrail(): void
    {
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);
        
        // Perform multiple operations
        $client->startHeating();
        $client->startIonizer();
        $client->testConnectivity();
        $client->stopHeating();
        $client->stopIonizer();
        
        $auditLog = file_get_contents($this->testAuditLogPath);
        $logLines = explode("\n", trim($auditLog));
        
        // Should have multiple log entries
        $this->assertGreaterThan(5, count($logLines));
        
        // Each line should be valid JSON
        foreach ($logLines as $line) {
            if (empty($line)) continue;
            
            $decoded = json_decode($line, true);
            $this->assertNotNull($decoded);
            $this->assertArrayHasKey('timestamp', $decoded);
            $this->assertArrayHasKey('action', $decoded);
        }
    }
}