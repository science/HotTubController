<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Controllers\MaintenanceSetupController;
use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Services\MaintenanceCronService;
use PHPUnit\Framework\TestCase;

/**
 * Mock crontab adapter for testing MaintenanceSetupController.
 */
class MockCrontabAdapterForSetup implements CrontabAdapterInterface
{
    public array $entries = [];
    public array $addedEntries = [];

    public function addEntry(string $entry): void
    {
        $this->entries[] = $entry;
        $this->addedEntries[] = $entry;
    }

    public function removeByPattern(string $pattern): void
    {
        $this->entries = array_values(array_filter(
            $this->entries,
            fn($entry) => strpos($entry, $pattern) === false
        ));
    }

    public function listEntries(): array
    {
        return $this->entries;
    }
}

/**
 * Mock Healthchecks client for testing MaintenanceSetupController.
 */
class MockHealthchecksClientForSetup implements HealthchecksClientInterface
{
    public bool $enabled = true;
    public array $createdChecks = [];
    public array $pings = [];
    public bool $createCheckFails = false;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array {
        if ($this->createCheckFails) {
            return null;
        }

        $uuid = 'test-uuid-' . count($this->createdChecks);
        $result = [
            'uuid' => $uuid,
            'ping_url' => 'https://hc-ping.com/' . $uuid,
            'status' => 'new',
        ];

        $this->createdChecks[] = [
            'name' => $name,
            'schedule' => $schedule,
            'result' => $result,
        ];

        return $result;
    }

    public function ping(string $pingUrl): bool
    {
        $this->pings[] = $pingUrl;
        return true;
    }

    public function delete(string $uuid): bool
    {
        return true;
    }

    public function getCheck(string $uuid): ?array
    {
        return null;
    }
}

class MaintenanceSetupControllerTest extends TestCase
{
    private string $tempDir;
    private MockCrontabAdapterForSetup $crontabAdapter;
    private MockHealthchecksClientForSetup $healthchecksClient;
    private MaintenanceCronService $maintenanceCronService;
    private MaintenanceSetupController $controller;
    private string $stateFile;
    private string $cronScriptPath;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/maintenance-setup-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/state', 0755, true);

        $this->crontabAdapter = new MockCrontabAdapterForSetup();
        $this->healthchecksClient = new MockHealthchecksClientForSetup();
        $this->stateFile = $this->tempDir . '/state/log-rotation-healthcheck.json';
        $this->cronScriptPath = $this->tempDir . '/bin/log-rotation-cron.sh';

        $this->maintenanceCronService = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $this->healthchecksClient,
            $this->stateFile,
            'America/New_York'
        );

        $this->controller = new MaintenanceSetupController($this->maintenanceCronService);
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

    // ========== setup() Tests ==========

    public function testSetupReturns200OnSuccess(): void
    {
        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
    }

    public function testSetupCreatesCronJobWhenNotExists(): void
    {
        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['cron_created']);
        $this->assertCount(1, $this->crontabAdapter->addedEntries);
    }

    public function testSetupCreatesHealthcheckWhenNotExists(): void
    {
        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['healthcheck_created']);
        $this->assertCount(1, $this->healthchecksClient->createdChecks);
    }

    public function testSetupPingsHealthcheckToArmIt(): void
    {
        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
        $this->assertCount(1, $this->healthchecksClient->pings);
    }

    public function testSetupSavesHealthcheckStateFile(): void
    {
        $this->controller->setup();

        $this->assertFileExists($this->stateFile);
        $state = json_decode(file_get_contents($this->stateFile), true);
        $this->assertArrayHasKey('uuid', $state);
        $this->assertArrayHasKey('ping_url', $state);
    }

    public function testSetupIsIdempotent(): void
    {
        // First call - creates everything
        $response1 = $this->controller->setup();
        $this->assertTrue($response1['body']['cron_created']);
        $this->assertTrue($response1['body']['healthcheck_created']);

        // Second call - should not create anything new
        $response2 = $this->controller->setup();
        $this->assertFalse($response2['body']['cron_created']);
        $this->assertFalse($response2['body']['healthcheck_created']);

        // Should still only have one cron entry
        $this->assertCount(1, $this->crontabAdapter->entries);
    }

    public function testSetupReturnsCronEntry(): void
    {
        $response = $this->controller->setup();

        $this->assertArrayHasKey('cron_entry', $response['body']);
        $this->assertStringContainsString('HOTTUB:log-rotation', $response['body']['cron_entry']);
    }

    public function testSetupReturnsHealthcheckPingUrl(): void
    {
        $response = $this->controller->setup();

        $this->assertArrayHasKey('healthcheck_ping_url', $response['body']);
        $this->assertStringContainsString('hc-ping.com', $response['body']['healthcheck_ping_url']);
    }

    public function testSetupReturnsTimestamp(): void
    {
        $response = $this->controller->setup();

        $this->assertArrayHasKey('timestamp', $response['body']);
        $this->assertNotFalse(strtotime($response['body']['timestamp']));
    }

    public function testSetupWorksWhenHealthchecksDisabled(): void
    {
        $this->healthchecksClient->enabled = false;

        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['cron_created']);
        $this->assertFalse($response['body']['healthcheck_created']);
        $this->assertNull($response['body']['healthcheck_ping_url']);
    }

    public function testSetupCreatesHealthcheckOnUpgrade(): void
    {
        // Simulate upgrade scenario: cron exists but no healthcheck state
        $this->crontabAdapter->entries[] = '0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation';

        $response = $this->controller->setup();

        $this->assertEquals(200, $response['status']);
        $this->assertFalse($response['body']['cron_created']); // Cron already existed
        $this->assertTrue($response['body']['healthcheck_created']); // But healthcheck was created
    }

    public function testSetupReturnsServerTimezone(): void
    {
        $response = $this->controller->setup();

        $this->assertArrayHasKey('server_timezone', $response['body']);
        $this->assertEquals('America/New_York', $response['body']['server_timezone']);
    }

    public function testSetupCronEntryContainsCorrectSchedule(): void
    {
        $response = $this->controller->setup();

        // Should run at 3am on 1st of every month
        $this->assertStringStartsWith('0 3 1 * *', $response['body']['cron_entry']);
    }

    public function testSetupHandlesHealthcheckCreationFailure(): void
    {
        $this->healthchecksClient->createCheckFails = true;

        $response = $this->controller->setup();

        // Should still succeed (cron is more important than monitoring)
        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['cron_created']);
        $this->assertFalse($response['body']['healthcheck_created']);
    }
}
