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
     * Test heating sequence with VCR playback (production-like scenario)
     */
    public function testHeatingSequenceWithVcrPlayback(): void
    {
        VCR::turnOn();
        VCR::insertCassette('heat-on.yml');
        
        // Create client with explicit API key for VCR playback
        // Note: This is safe because we're in test mode and using VCR
        $client = new IftttWebhookClient('test-api-key-for-vcr', 30, false, $this->testAuditLogPath);
        
        $result = $client->startHeating();
        $this->assertTrue($result);
        
        VCR::eject();
        VCR::insertCassette('heat-off.yml');
        
        $result = $client->stopHeating();
        $this->assertTrue($result);
        
        VCR::turnOff();
        
        // Verify real requests were logged (not simulated)
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TRIGGER_SUCCESS', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-on', $auditLog);
        $this->assertStringContainsString('hot-tub-heat-off', $auditLog);
    }
    
    /**
     * Test ionizer control with VCR playback
     */
    public function testIonizerControlWithVcrPlayback(): void
    {
        VCR::turnOn();
        VCR::insertCassette('ionizer-on.yml');
        
        $client = new IftttWebhookClient('test-api-key-for-vcr', 30, false, $this->testAuditLogPath);
        
        $result = $client->startIonizer();
        $this->assertTrue($result);
        
        VCR::eject();
        VCR::insertCassette('ionizer-off.yml');
        
        $result = $client->stopIonizer();
        $this->assertTrue($result);
        
        VCR::turnOff();
        
        // Verify real requests were logged
        $auditLog = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('turn-on-hot-tub-ionizer', $auditLog);
        $this->assertStringContainsString('turn-off-hot-tub-ionizer', $auditLog);
    }
    
    /**
     * Test complete hot tub cycle with VCR
     */
    public function testCompleteHotTubCycleWithVcr(): void
    {
        $client = new IftttWebhookClient('test-api-key-for-vcr', 30, false, $this->testAuditLogPath);
        
        VCR::turnOn();
        
        // Step 1: Start heating
        VCR::insertCassette('heat-on.yml');
        $this->assertTrue($client->startHeating());
        
        // Step 2: Start ionizer
        VCR::eject();
        VCR::insertCassette('ionizer-on.yml');
        $this->assertTrue($client->startIonizer());
        
        // Step 3: Stop heating
        VCR::eject();
        VCR::insertCassette('heat-off.yml');
        $this->assertTrue($client->stopHeating());
        
        // Step 4: Stop ionizer
        VCR::eject();
        VCR::insertCassette('ionizer-off.yml');
        $this->assertTrue($client->stopIonizer());
        
        VCR::turnOff();
        
        // Verify all operations were logged
        $auditLog = file_get_contents($this->testAuditLogPath);
        $logLines = explode("\n", $auditLog);
        
        $triggerSuccessCount = 0;
        foreach ($logLines as $line) {
            if (strpos($line, 'TRIGGER_SUCCESS') !== false) {
                $triggerSuccessCount++;
            }
        }
        
        $this->assertEquals(4, $triggerSuccessCount, 'Should have 4 successful triggers');
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
        // Test mode - no connectivity
        $testModeClient = IftttWebhookClientFactory::create();
        $testResult = $testModeClient->testConnectivity();
        
        $this->assertFalse($testResult['available']);
        $this->assertTrue($testResult['test_mode']);
        $this->assertStringContainsString('Test mode', $testResult['error']);
        
        // Dry run mode - simulated connectivity
        $dryRunClient = IftttWebhookClientFactory::create(true);
        $dryRunResult = $dryRunClient->testConnectivity();
        
        $this->assertTrue($dryRunResult['available']);
        $this->assertTrue($dryRunResult['dry_run']);
        $this->assertEquals(150, $dryRunResult['response_time_ms']);
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