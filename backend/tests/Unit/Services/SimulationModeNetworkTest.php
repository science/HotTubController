<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTubController\Services\IftttWebhookClientFactory;
use HotTubController\Services\IftttWebhookClient;

/**
 * Test suite for simulation mode network behavior
 *
 * This test demonstrates that:
 * 1. Simulation mode prevents all network requests
 * 2. Non-simulation mode attempts network requests (but fails with invalid credentials)
 * 3. Environment configuration correctly controls network behavior
 */
class SimulationModeNetworkTest extends TestCase
{
    private array $originalEnv;
    private array $originalServer;
    private string $testAuditLogPath;

    protected function setUp(): void
    {
        // Save original environment variables from both $_ENV and $_SERVER
        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;

        // Use a temporary file for audit logging during tests
        $this->testAuditLogPath = sys_get_temp_dir() . '/simulation-network-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        // Clear environment variables using putenv (affects getenv())
        putenv('APP_ENV');
        putenv('SIMULATION_MODE');
        putenv('WIRELESSTAG_OAUTH_TOKEN');
        putenv('IFTTT_WEBHOOK_KEY');

        // Restore original environment variables
        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;

        // Clean up audit log file
        if (file_exists($this->testAuditLogPath)) {
            unlink($this->testAuditLogPath);
        }
    }

    /**
     * Helper method to set environment variables using all three methods
     * This ensures the factory can read them regardless of which method it uses
     */
    private function setEnvironmentVariable(string $key, string $value): void
    {
        putenv("$key=$value");      // For getenv()
        $_ENV[$key] = $value;       // For $_ENV access
        $_SERVER[$key] = $value;    // For $_SERVER access
    }

    /**
     * Helper method to unset environment variables using all three methods
     */
    private function unsetEnvironmentVariable(string $key): void
    {
        putenv($key);               // Clear from getenv()
        unset($_ENV[$key]);         // Clear from $_ENV
        unset($_SERVER[$key]);      // Clear from $_SERVER
    }

    /**
     * Test that simulation mode prevents network requests
     *
     * This test configures the environment to match .env.development-sim
     * and verifies that no network requests are attempted.
     */
    public function testSimulationModePreventNetworkRequests(): void
    {
        // Configure environment to match .env.development-sim
        $this->setEnvironmentVariable('APP_ENV', 'development');
        $this->setEnvironmentVariable('SIMULATION_MODE', 'true');
        $this->setEnvironmentVariable('WIRELESSTAG_OAUTH_TOKEN', '');  // Empty = simulation mode
        $this->setEnvironmentVariable('IFTTT_WEBHOOK_KEY', '');        // Empty = simulation mode

        // Create client using factory (should detect simulation mode)
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);

        // Verify client is in test mode (safe mode)
        $this->assertTrue($client->isTestMode(), 'Client should be in test mode when API key is empty');

        // Test connectivity - should NOT make network request
        $result = $client->testConnectivity();

        // Verify client returns simulation results without network calls
        $this->assertTrue($result['test_mode'], 'Should indicate test mode');
        $this->assertFalse($result['available'], 'Should not be available in test mode');
        $this->assertStringContainsString('Test mode', $result['error'], 'Should indicate test mode in error message');
        $this->assertEquals(0, $result['response_time_ms'], 'Should have zero response time (no network call)');

        // Check audit log to confirm no network request was attempted
        $this->assertFileExists($this->testAuditLogPath, 'Audit log should exist');
        $auditContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('TEST MODE', $auditContent, 'Audit log should show TEST MODE');
        $this->assertStringNotContainsString('HTTP', $auditContent, 'Audit log should not contain HTTP requests');

        // Verify environment info shows safe mode is active
        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();
        $this->assertTrue($envInfo['safe_mode_active'], 'Safe mode should be active');
        $this->assertFalse($envInfo['has_api_key'], 'Should not have API key');

        echo "\n✅ SIMULATION MODE TEST PASSED: No network requests attempted\n";
        echo "   - Client correctly entered test mode\n";
        echo "   - Webhook trigger simulated without network calls\n";
        echo "   - Audit log shows TEST MODE operation\n";
    }

    /**
     * Test that non-simulation mode attempts network requests
     *
     * This test configures the environment with invalid API credentials
     * and verifies that network requests are attempted (but fail due to invalid keys).
     */
    public function testNonSimulationModeAttemptsNetworkRequests(): void
    {
        // Configure environment for live mode with INVALID credentials
        $this->setEnvironmentVariable('APP_ENV', 'production');  // Use production to avoid test environment safety override
        $this->setEnvironmentVariable('SIMULATION_MODE', 'false');
        $this->setEnvironmentVariable('WIRELESSTAG_OAUTH_TOKEN', 'invalid_test_token_for_network_test');
        $this->setEnvironmentVariable('IFTTT_WEBHOOK_KEY', 'invalid_test_key_for_network_test');

        // Create client using factory (should detect live mode)
        $client = IftttWebhookClientFactory::create(false, $this->testAuditLogPath);

        // Check what the client thinks
        $isTestMode = $client->isTestMode();
        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();

        // Verify client is NOT in test mode (will attempt real network calls)
        $this->assertFalse($isTestMode, 'Client should NOT be in test mode when API key is present. Environment: ' .
            json_encode($envInfo));

        // Test connectivity - should attempt network request
        $result = $client->testConnectivity();

        // Verify operation attempted real network call
        $this->assertFalse($result['test_mode'], 'Should NOT be in test mode with API key present');
        $this->assertGreaterThan(0, $result['response_time_ms'], 'Should have response time from actual network attempt');

        // Check audit log to confirm network request was attempted
        $this->assertFileExists($this->testAuditLogPath, 'Audit log should exist');
        $auditContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('CONNECTIVITY_TEST_ATTEMPT', $auditContent, 'Audit log should show connectivity test attempt');
        $this->assertStringContainsString('maker.ifttt.com', $auditContent, 'Audit log should contain IFTTT URL');
        $this->assertStringContainsString('CONNECTIVITY_TEST_RESULT', $auditContent, 'Audit log should show test result');

        // Verify environment info shows live mode is active
        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();
        $this->assertFalse($envInfo['safe_mode_active'], 'Safe mode should NOT be active');
        $this->assertTrue($envInfo['has_api_key'], 'Should have API key');

        echo "\n✅ NON-SIMULATION MODE TEST PASSED: Network requests attempted\n";
        echo "   - Client correctly entered live mode\n";
        echo "   - IFTTT connectivity test made actual HTTP request\n";
        echo "   - Request completed with " . $result['response_time_ms'] . "ms response time\n";
        echo "   - Audit log shows actual network request attempt\n";
    }

    /**
     * Test environment configuration detection
     *
     * This test verifies that the factory correctly detects different
     * environment configurations and makes appropriate safety decisions.
     */
    public function testEnvironmentConfigurationDetection(): void
    {
        // Test 1: Pure simulation environment (.env.development-sim)
        $this->setEnvironmentVariable('APP_ENV', 'development');
        $this->setEnvironmentVariable('SIMULATION_MODE', 'true');
        $this->setEnvironmentVariable('WIRELESSTAG_OAUTH_TOKEN', '');
        $this->setEnvironmentVariable('IFTTT_WEBHOOK_KEY', '');

        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();
        $this->assertTrue($envInfo['safe_mode_active'], 'Simulation environment should be safe');
        $this->assertStringContainsString('Configuration looks good', implode('', $envInfo['recommendations']));

        // Test 2: Live development environment (.env.development-live)
        $this->setEnvironmentVariable('APP_ENV', 'development');
        $this->setEnvironmentVariable('SIMULATION_MODE', 'false');
        $this->setEnvironmentVariable('WIRELESSTAG_OAUTH_TOKEN', 'real_token');
        $this->setEnvironmentVariable('IFTTT_WEBHOOK_KEY', 'real_key');

        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();
        $this->assertFalse($envInfo['safe_mode_active'], 'Live environment should not be in safe mode');
        $this->assertTrue($envInfo['has_api_key'], 'Live environment should have API key');

        // Test 3: Testing environment (.env.testing)
        $this->setEnvironmentVariable('APP_ENV', 'testing');
        $this->setEnvironmentVariable('WIRELESSTAG_OAUTH_TOKEN', 'test_token');
        $this->unsetEnvironmentVariable('IFTTT_WEBHOOK_KEY'); // Intentionally omitted

        $envInfo = IftttWebhookClientFactory::getEnvironmentInfo();
        $this->assertTrue($envInfo['safe_mode_active'], 'Test environment should always be safe');
        $this->assertFalse($envInfo['has_api_key'], 'Test environment should not have IFTTT API key');

        echo "\n✅ ENVIRONMENT DETECTION TEST PASSED:\n";
        echo "   - Simulation environment correctly detected as safe\n";
        echo "   - Live environment correctly detected as non-safe\n";
        echo "   - Test environment correctly forced to safe mode\n";
    }

    /**
     * Test that createSafe() always creates safe clients
     *
     * This verifies that the createSafe() factory method always
     * creates clients that won't make network requests, regardless
     * of environment configuration.
     */
    public function testCreateSafeAlwaysCreatesSafeClients(): void
    {
        // Even with live environment settings, createSafe() should be safe
        $this->setEnvironmentVariable('APP_ENV', 'production');
        $this->setEnvironmentVariable('SIMULATION_MODE', 'false');
        $this->setEnvironmentVariable('IFTTT_WEBHOOK_KEY', 'real_production_key');

        $safeClient = IftttWebhookClientFactory::createSafe(true, $this->testAuditLogPath);

        // Verify client is in test mode despite having API key in environment
        $this->assertTrue($safeClient->isTestMode(), 'createSafe() should always create test mode clients');
        $this->assertTrue($safeClient->isDryRun(), 'createSafe() should enable dry run by default');

        // Test connectivity with safe client - should be safe
        $result = $safeClient->testConnectivity();
        $this->assertTrue($result['test_mode'], 'Safe client should always be in test mode');
        $this->assertFalse($result['available'], 'Safe client should not be available');

        // Verify no network request was attempted
        $auditContent = file_get_contents($this->testAuditLogPath);
        $this->assertStringContainsString('"dry_run":true', $auditContent, 'Should show dry run mode in JSON log');
        $this->assertStringNotContainsString('HTTP', $auditContent, 'Should not make HTTP requests');

        echo "\n✅ CREATE SAFE TEST PASSED: createSafe() always prevents network requests\n";
    }
}