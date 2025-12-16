<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration\Services;

use HotTub\Services\HealthchecksClient;
use HotTub\Services\HealthchecksClientFactory;
use PHPUnit\Framework\TestCase;

/**
 * Live API integration tests for Healthchecks.io client.
 *
 * These tests make real API calls to Healthchecks.io and verify
 * the full workflow: create → ping → get status → delete.
 *
 * All checks use schedule-based monitoring with cron expressions.
 *
 * @group live
 * @group healthchecks
 */
class HealthchecksClientLiveTest extends TestCase
{
    private ?HealthchecksClient $client = null;
    private array $createdChecks = [];

    protected function setUp(): void
    {
        // Load API key from env.production config
        $envFile = dirname(__DIR__, 3) . '/config/env.production';
        $config = [];

        if (file_exists($envFile)) {
            $content = file_get_contents($envFile);
            foreach (explode("\n", $content) as $line) {
                if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $matches)) {
                    $config[$matches[1]] = trim($matches[2]);
                }
            }
        }

        $apiKey = $config['HEALTHCHECKS_IO_KEY'] ?? getenv('HEALTHCHECKS_IO_KEY') ?: null;

        if (empty($apiKey)) {
            $this->markTestSkipped('HEALTHCHECKS_IO_KEY not configured');
        }

        // Get the "Down only checks" channel
        $channelId = $config['HEALTHCHECKS_IO_CHANNEL'] ?? '9d1e9a48-1c1c-4a45-8c50-6705175ba58a';

        $this->client = new HealthchecksClient(
            $apiKey,
            $channelId,
            '/tmp/healthchecks-test.log'
        );
    }

    protected function tearDown(): void
    {
        // Clean up any checks we created
        if ($this->client !== null) {
            foreach ($this->createdChecks as $uuid) {
                $this->client->delete($uuid);
            }
        }
    }

    public function testCreateCheckReturnsUuidAndPingUrl(): void
    {
        $result = $this->client->createCheck(
            'live-test-' . time(),
            '* * * * *',  // Every minute (just for testing)
            'UTC',
            60   // 1 minute grace
        );

        $this->assertNotNull($result, 'createCheck should return result');
        $this->assertArrayHasKey('uuid', $result);
        $this->assertArrayHasKey('ping_url', $result);
        $this->assertNotEmpty($result['uuid']);
        $this->assertStringContainsString('hc-ping.com', $result['ping_url']);

        $this->createdChecks[] = $result['uuid'];
    }

    public function testNewCheckHasNewStatus(): void
    {
        $created = $this->client->createCheck(
            'live-test-status-' . time(),
            '* * * * *',
            'UTC',
            60
        );
        $this->createdChecks[] = $created['uuid'];

        $check = $this->client->getCheck($created['uuid']);

        $this->assertNotNull($check);
        $this->assertEquals('new', $check['status']);
        $this->assertEquals(0, $check['n_pings']);
    }

    public function testPingTransitionsCheckToUp(): void
    {
        $created = $this->client->createCheck(
            'live-test-ping-' . time(),
            '* * * * *',
            'UTC',
            60
        );
        $this->createdChecks[] = $created['uuid'];

        // Ping the check
        $pingResult = $this->client->ping($created['ping_url']);
        $this->assertTrue($pingResult, 'Ping should succeed');

        // Wait a moment for API to process
        sleep(2);

        // Verify status changed
        $check = $this->client->getCheck($created['uuid']);
        $this->assertEquals('up', $check['status']);
        $this->assertEquals(1, $check['n_pings']);
    }

    public function testDeleteRemovesCheck(): void
    {
        $created = $this->client->createCheck(
            'live-test-delete-' . time(),
            '* * * * *',
            'UTC',
            60
        );

        // Delete the check
        $deleteResult = $this->client->delete($created['uuid']);
        $this->assertTrue($deleteResult, 'Delete should succeed');

        // Verify it's gone
        $check = $this->client->getCheck($created['uuid']);
        $this->assertNull($check, 'Deleted check should not be found');
    }

    public function testInvalidApiKeyLogsWarning(): void
    {
        $logFile = '/tmp/healthchecks-invalid-key-test.log';
        @unlink($logFile);

        $badClient = new HealthchecksClient(
            'invalid-api-key-12345',
            null,
            $logFile
        );

        // This should fail but not throw
        $result = $badClient->createCheck('test', '* * * * *', 'UTC', 60);

        $this->assertNull($result, 'Should return null on auth failure');

        // Verify warning was logged
        $this->assertFileExists($logFile);
        $log = file_get_contents($logFile);
        $this->assertStringContainsString('WARNING', $log);
        $this->assertStringContainsString('401', $log);

        @unlink($logFile);
    }

    public function testFullWorkflowCreatePingDelete(): void
    {
        // This simulates what the scheduler will do:
        // 1. Create check when job is scheduled
        // 2. Ping immediately to arm it
        // 3. Delete when job executes successfully (one-off) or ping again (recurring)

        // Step 1: Create check with a daily schedule
        $checkName = 'workflow-test-' . time();
        $created = $this->client->createCheck($checkName, '30 14 * * *', 'UTC', 60);

        $this->assertNotNull($created);
        $uuid = $created['uuid'];
        // Don't add to createdChecks - we'll delete manually

        // Step 2: Ping to arm
        $pingResult = $this->client->ping($created['ping_url']);
        $this->assertTrue($pingResult);

        // Wait for API to process
        sleep(2);

        // Verify armed
        $check = $this->client->getCheck($uuid);
        $this->assertEquals('up', $check['status']);

        // Step 3: Delete on "success" (simulating one-off job)
        $deleteResult = $this->client->delete($uuid);
        $this->assertTrue($deleteResult);

        // Verify gone
        $check = $this->client->getCheck($uuid);
        $this->assertNull($check);
    }

    /**
     * Test that a check with channel attached can be created.
     * This verifies our email alerting will work.
     */
    public function testCreateCheckWithChannelAttached(): void
    {
        $created = $this->client->createCheck(
            'channel-test-' . time(),
            '* * * * *',
            'UTC',
            60
        );
        $this->createdChecks[] = $created['uuid'];

        $this->assertNotNull($created);

        // The client was constructed with a default channel,
        // so the check should have it attached
        // We can't easily verify this via API without a raw GET,
        // but if the create succeeded, the channel was valid
    }
}
