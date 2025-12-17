<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\HealthchecksClientInterface;
use HotTub\Services\MaintenanceCronService;
use PHPUnit\Framework\TestCase;

/**
 * Mock crontab adapter for testing MaintenanceCronService.
 */
class MockCrontabAdapterForMaintenance implements CrontabAdapterInterface
{
    public array $entries = [];
    public array $addedEntries = [];
    public array $removedPatterns = [];

    public function addEntry(string $entry): void
    {
        $this->entries[] = $entry;
        $this->addedEntries[] = $entry;
    }

    public function removeByPattern(string $pattern): void
    {
        $this->removedPatterns[] = $pattern;
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
 * Mock Healthchecks client for testing.
 */
class MockHealthchecksClientForMaintenance implements HealthchecksClientInterface
{
    public bool $enabled = true;
    public array $createdChecks = [];
    public array $deletedChecks = [];
    public array $pings = [];
    public ?array $nextCheckResult = null;

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
        if ($this->nextCheckResult !== null) {
            $result = $this->nextCheckResult;
            $this->nextCheckResult = null;
            $this->createdChecks[] = [
                'name' => $name,
                'schedule' => $schedule,
                'timezone' => $timezone,
                'grace' => $grace,
                'channels' => $channels,
                'result' => $result,
            ];
            return $result;
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
            'timezone' => $timezone,
            'grace' => $grace,
            'channels' => $channels,
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
        $this->deletedChecks[] = $uuid;
        return true;
    }

    public function getCheck(string $uuid): ?array
    {
        return null;
    }
}

class MaintenanceCronServiceTest extends TestCase
{
    private MockCrontabAdapterForMaintenance $crontabAdapter;
    private MaintenanceCronService $service;
    private string $cronScriptPath;

    protected function setUp(): void
    {
        $this->crontabAdapter = new MockCrontabAdapterForMaintenance();
        $this->cronScriptPath = '/path/to/storage/bin/log-rotation-cron.sh';
        $this->service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath
        );
    }

    // ========== ensureLogRotationCronExists() Tests ==========

    public function testEnsureLogRotationCronExistsCreatesJobWhenNotExists(): void
    {
        $result = $this->service->ensureLogRotationCronExists();

        $this->assertTrue($result['created']);
        $this->assertCount(1, $this->crontabAdapter->addedEntries);
    }

    public function testEnsureLogRotationCronExistsDoesNotCreateWhenAlreadyExists(): void
    {
        // Pre-populate with existing log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST -H "Authorization: Bearer $CRON_JWT" https://example.com/tub/backend/public/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $result = $this->service->ensureLogRotationCronExists();

        $this->assertFalse($result['created']);
        $this->assertEmpty($this->crontabAdapter->addedEntries);
    }

    public function testCreatedCronJobHasCorrectMarker(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString('HOTTUB:log-rotation', $entry);
    }

    public function testCreatedCronJobRunsMonthly(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        // Cron schedule: minute hour day-of-month month day-of-week
        // "0 3 1 * *" = 3am on the 1st of every month
        $this->assertStringStartsWith('0 3 1 * *', $entry);
    }

    public function testCreatedCronJobCallsScript(): void
    {
        $this->service->ensureLogRotationCronExists();

        $entry = $this->crontabAdapter->addedEntries[0];
        $this->assertStringContainsString($this->cronScriptPath, $entry);
    }

    public function testEnsureLogRotationCronExistsReturnsStatus(): void
    {
        $result = $this->service->ensureLogRotationCronExists();

        $this->assertArrayHasKey('created', $result);
        $this->assertArrayHasKey('entry', $result);
        $this->assertIsString($result['entry']);
    }

    public function testLogRotationCronExistsReturnsTrueWhenExists(): void
    {
        // Pre-populate with existing log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $this->assertTrue($this->service->logRotationCronExists());
    }

    public function testLogRotationCronExistsReturnsFalseWhenNotExists(): void
    {
        $this->assertFalse($this->service->logRotationCronExists());
    }

    public function testLogRotationCronExistsIgnoresOtherHottubCrons(): void
    {
        // Pre-populate with other HOTTUB cron jobs, but not log-rotation
        $this->crontabAdapter->entries[] = '30 8 * * * /path/to/cron-runner.sh job-abc123 # HOTTUB:job-abc123';
        $this->crontabAdapter->entries[] = '0 9 * * * /path/to/cron-runner.sh rec-xyz789 # HOTTUB:rec-xyz789';

        $this->assertFalse($this->service->logRotationCronExists());
    }

    // ========== removeLogRotationCron() Tests ==========

    public function testRemoveLogRotationCronRemovesExistingJob(): void
    {
        // Pre-populate with log rotation cron
        $this->crontabAdapter->entries[] = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $result = $this->service->removeLogRotationCron();

        $this->assertTrue($result['removed']);
        $this->assertEmpty($this->crontabAdapter->entries);
    }

    public function testRemoveLogRotationCronDoesNothingWhenNotExists(): void
    {
        $result = $this->service->removeLogRotationCron();

        $this->assertFalse($result['removed']);
    }

    public function testRemoveLogRotationCronDoesNotAffectOtherCrons(): void
    {
        $otherCron = '30 8 * * * /path/to/cron-runner.sh job-abc123 # HOTTUB:job-abc123';
        $logRotationCron = '0 3 1 * * curl -X POST https://example.com/api/maintenance/logs/rotate # HOTTUB:log-rotation';

        $this->crontabAdapter->entries[] = $otherCron;
        $this->crontabAdapter->entries[] = $logRotationCron;

        $this->service->removeLogRotationCron();

        $this->assertCount(1, $this->crontabAdapter->entries);
        $this->assertEquals($otherCron, $this->crontabAdapter->entries[0]);
    }

    // ========== Healthchecks Integration Tests ==========

    public function testEnsureLogRotationCronExistsCreatesHealthcheckWhenEnabled(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'America/Los_Angeles' // Server timezone
        );

        $result = $service->ensureLogRotationCronExists();

        // Should have created the health check
        $this->assertCount(1, $healthchecksClient->createdChecks);
        $this->assertArrayHasKey('healthcheck', $result);
        $this->assertNotNull($result['healthcheck']);

        // Clean up
        @unlink($stateFile);
    }

    public function testHealthcheckCreatedWithCorrectSchedule(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'America/Los_Angeles'
        );

        $service->ensureLogRotationCronExists();

        // Check should have cron schedule "0 3 1 * *" (3am on 1st of month)
        $check = $healthchecksClient->createdChecks[0];
        $this->assertEquals('0 3 1 * *', $check['schedule']);

        @unlink($stateFile);
    }

    public function testHealthcheckCreatedWithServerTimezone(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'America/New_York'
        );

        $service->ensureLogRotationCronExists();

        // Check should use server timezone
        $check = $healthchecksClient->createdChecks[0];
        $this->assertEquals('America/New_York', $check['timezone']);

        @unlink($stateFile);
    }

    public function testHealthcheckCreatedWithDescriptiveName(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->ensureLogRotationCronExists();

        // Check should have descriptive name
        $check = $healthchecksClient->createdChecks[0];
        $this->assertStringContainsString('log-rotation', $check['name']);

        @unlink($stateFile);
    }

    public function testHealthcheckIsPingedImmediatelyToArmIt(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->ensureLogRotationCronExists();

        // Check should be pinged immediately to arm it
        $this->assertCount(1, $healthchecksClient->pings);
        $this->assertStringContainsString('hc-ping.com', $healthchecksClient->pings[0]);

        @unlink($stateFile);
    }

    public function testHealthcheckStateIsSavedToFile(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->ensureLogRotationCronExists();

        // State file should contain the ping URL
        $this->assertFileExists($stateFile);
        $state = json_decode(file_get_contents($stateFile), true);
        $this->assertArrayHasKey('ping_url', $state);
        $this->assertArrayHasKey('uuid', $state);

        @unlink($stateFile);
    }

    public function testNoHealthcheckCreatedWhenCronAndStateFileExist(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        // Pre-populate with existing cron AND state file
        $this->crontabAdapter->entries[] = '0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation';
        file_put_contents($stateFile, json_encode([
            'uuid' => 'existing-uuid',
            'ping_url' => 'https://hc-ping.com/existing-uuid',
        ]));

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $result = $service->ensureLogRotationCronExists();

        // Should NOT create health check (cron exists AND state file exists)
        $this->assertEmpty($healthchecksClient->createdChecks);
        $this->assertFalse($result['created']);

        @unlink($stateFile);
    }

    public function testHealthcheckCreatedWhenCronExistsButStateFileMissing(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        // Pre-populate with existing cron but NO state file (upgrade scenario)
        $this->crontabAdapter->entries[] = '0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $result = $service->ensureLogRotationCronExists();

        // Should create health check (cron exists but state file missing - upgrade scenario)
        $this->assertCount(1, $healthchecksClient->createdChecks);
        $this->assertFalse($result['created']); // Cron wasn't created (already existed)
        $this->assertNotNull($result['healthcheck']); // But healthcheck WAS created

        @unlink($stateFile);
    }

    public function testHealthcheckStateFileSavedWhenCronExistsButStateMissing(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        // Pre-populate with existing cron but NO state file
        $this->crontabAdapter->entries[] = '0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->ensureLogRotationCronExists();

        // State file should now exist with ping URL
        $this->assertFileExists($stateFile);
        $state = json_decode(file_get_contents($stateFile), true);
        $this->assertArrayHasKey('ping_url', $state);

        @unlink($stateFile);
    }

    public function testHealthcheckNotCreatedWhenClientDisabled(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $healthchecksClient->enabled = false;
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $result = $service->ensureLogRotationCronExists();

        // Should create cron but NOT health check
        $this->assertTrue($result['created']);
        $this->assertEmpty($healthchecksClient->createdChecks);
        $this->assertNull($result['healthcheck']);

        @unlink($stateFile);
    }

    public function testRemoveLogRotationCronDeletesHealthcheck(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        // Create state file with existing health check
        file_put_contents($stateFile, json_encode([
            'uuid' => 'existing-check-uuid',
            'ping_url' => 'https://hc-ping.com/existing-check-uuid',
        ]));

        // Pre-populate cron
        $this->crontabAdapter->entries[] = '0 3 1 * * /path/to/log-rotation-cron.sh # HOTTUB:log-rotation';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->removeLogRotationCron();

        // Should delete the health check
        $this->assertContains('existing-check-uuid', $healthchecksClient->deletedChecks);
        // State file should be removed
        $this->assertFileDoesNotExist($stateFile);
    }

    public function testGetHealthcheckPingUrlReturnsUrlFromStateFile(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        // Create state file
        file_put_contents($stateFile, json_encode([
            'uuid' => 'test-uuid',
            'ping_url' => 'https://hc-ping.com/test-uuid',
        ]));

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $pingUrl = $service->getHealthcheckPingUrl();

        $this->assertEquals('https://hc-ping.com/test-uuid', $pingUrl);

        @unlink($stateFile);
    }

    public function testGetHealthcheckPingUrlReturnsNullWhenNoStateFile(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/nonexistent-state-file.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $pingUrl = $service->getHealthcheckPingUrl();

        $this->assertNull($pingUrl);
    }

    public function testHealthcheckHasGracePeriodOfOneDay(): void
    {
        $healthchecksClient = new MockHealthchecksClientForMaintenance();
        $stateFile = sys_get_temp_dir() . '/log-rotation-healthcheck-test-' . uniqid() . '.json';

        $service = new MaintenanceCronService(
            $this->crontabAdapter,
            $this->cronScriptPath,
            $healthchecksClient,
            $stateFile,
            'UTC'
        );

        $service->ensureLogRotationCronExists();

        // Grace period should be 1 day (86400 seconds)
        // This gives buffer for the log rotation to complete
        $check = $healthchecksClient->createdChecks[0];
        $this->assertEquals(86400, $check['grace']);

        @unlink($stateFile);
    }
}
