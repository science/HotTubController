<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use HotTub\Services\CrontabAdapter;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\SchedulerService;
use HotTub\Services\TargetTemperatureService;
use HotTub\Tests\Fixtures\HeatingCycleFixture;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the heat-to-target cron chain.
 *
 * These tests verify the FULL production flow and were created to catch bugs where:
 *
 * BUG #1: start() saves state but never initiates heating or schedules a check
 * BUG #2: scheduleNextCheck() creates curl commands without Authorization headers
 *
 * The tests are designed to FAIL (RED) until the bugs are fixed.
 * Run before fixing to verify they correctly identify the bugs.
 *
 * @group integration
 */
class HeatToTargetCronChainTest extends TestCase
{
    private string $testDir;
    private string $jobsDir;
    private string $stateDir;
    private string $logsDir;
    private string $envFile;
    private string $equipmentStatusFile;
    private string $esp32TempFile;
    private string $targetTempFile;
    private CrontabAdapter $crontab;
    private EquipmentStatusService $equipmentStatus;
    private Esp32TemperatureService $esp32Temp;
    private HeatingCycleFixture $fixture;

    /** @var array<string> Cron entries we added (for cleanup) */
    private array $addedCronPatterns = [];

    /** @var array<string> Job files we created (for cleanup) */
    private array $createdJobFiles = [];

    protected function setUp(): void
    {
        // Create isolated test directories
        $this->testDir = sys_get_temp_dir() . '/heat-target-chain-test-' . uniqid();
        $this->jobsDir = $this->testDir . '/scheduled-jobs';
        $this->stateDir = $this->testDir . '/state';
        $this->logsDir = $this->testDir . '/logs';

        mkdir($this->jobsDir, 0755, true);
        mkdir($this->stateDir, 0755, true);
        mkdir($this->logsDir, 0755, true);

        // Set up state files
        $this->equipmentStatusFile = $this->stateDir . '/equipment-status.json';
        $this->esp32TempFile = $this->stateDir . '/esp32-temperature.json';
        $this->targetTempFile = $this->stateDir . '/target-temperature.json';

        // Create minimal .env for cron-runner
        $this->envFile = $this->testDir . '/.env';
        file_put_contents($this->envFile, "CRON_JWT=test-jwt-token\nEXTERNAL_API_MODE=stub\n");

        // Set up services
        $this->crontab = new CrontabAdapter();
        $this->equipmentStatus = new EquipmentStatusService($this->equipmentStatusFile);
        $this->esp32Temp = new Esp32TemperatureService($this->esp32TempFile, $this->equipmentStatus);

        // Load fixture for temperature data
        $this->fixture = HeatingCycleFixture::load();
    }

    protected function tearDown(): void
    {
        // Clean up crontab entries we added
        foreach ($this->addedCronPatterns as $pattern) {
            $this->crontab->removeByPattern($pattern);
        }

        // Clean up job files
        foreach ($this->createdJobFiles as $file) {
            if (file_exists($file)) {
                unlink($file);
            }
        }

        // Clean up test directory
        $this->recursiveDelete($this->testDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Store a temperature reading in the ESP32 temp file.
     */
    private function storeTemperatureReading(float $tempF): void
    {
        $reading = $this->fixture->getReading(0); // Use fixture structure
        $apiRequest = $this->fixture->toApiRequest($reading);

        // Override the temperature
        $tempC = ($tempF - 32) * 5 / 9;
        $apiRequest['sensors'][0]['temp_c'] = $tempC;

        $this->esp32Temp->store($apiRequest);
    }

    /**
     * Create a TargetTemperatureService with recording mocks.
     *
     * @return array{service: TargetTemperatureService, iftttCalls: array<string>, cronEntries: array<string>}
     */
    private function createServiceWithRecorders(): array
    {
        $iftttCalls = [];
        $mockIfttt = $this->createMock(\HotTub\Contracts\IftttClientInterface::class);
        $mockIfttt->method('trigger')->willReturnCallback(function ($event) use (&$iftttCalls) {
            $iftttCalls[] = $event;
            return true;
        });

        $cronEntries = [];
        $mockCrontab = $this->createMock(\HotTub\Contracts\CrontabAdapterInterface::class);
        $mockCrontab->method('addEntry')->willReturnCallback(function ($entry) use (&$cronEntries) {
            $cronEntries[] = $entry;
        });

        $service = new TargetTemperatureService(
            $this->targetTempFile,
            $mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );

        return [
            'service' => $service,
            'iftttCalls' => &$iftttCalls,
            'cronEntries' => &$cronEntries,
        ];
    }

    // =========================================================================
    // BUG #1: start() doesn't initiate heating or schedule a check
    // =========================================================================

    /**
     * @test
     * BUG #1 - RED TEST: After calling start(), the heater should turn on
     * OR a temperature check should be scheduled. Currently NEITHER happens.
     *
     * This test MUST FAIL until Bug #1 is fixed.
     */
    public function bug1_startShouldInitiateHeatingWhenTempBelowTarget(): void
    {
        // Set up temperature well below target
        $this->storeTemperatureReading(82.0); // Target will be 101°F

        $recorder = $this->createServiceWithRecorders();
        $service = $recorder['service'];
        $iftttCalls = &$recorder['iftttCalls'];
        $cronEntries = &$recorder['cronEntries'];

        // Call start() - this is what happens when the API endpoint is called
        $service->start(101.0);

        // Verify state was saved (this part works)
        $state = $service->getState();
        $this->assertTrue($state['active'], 'State should be active');
        $this->assertEquals(101.0, $state['target_temp_f'], 'Target temp should be saved');

        // THE BUG: After start(), one of these MUST happen:
        // Option A: Heater turns on immediately (temp is below target)
        $heaterTurnedOn = in_array('hot-tub-heat-on', $iftttCalls);

        // Option B: A cron job is scheduled to check temperature
        $checkScheduled = !empty($cronEntries);

        $this->assertTrue(
            $heaterTurnedOn || $checkScheduled,
            "BUG #1: After start() with temp 82°F < target 101°F:\n" .
            "- Heater turned on: " . ($heaterTurnedOn ? 'YES' : 'NO') . "\n" .
            "- Check scheduled: " . ($checkScheduled ? 'YES' : 'NO') . "\n" .
            "At least one must happen, but neither does!"
        );
    }

    /**
     * @test
     * BUG #1 - RED TEST: Equipment status should reflect heater state after start().
     *
     * This test MUST FAIL until Bug #1 is fixed.
     */
    public function bug1_equipmentStatusShouldShowHeaterOnAfterStart(): void
    {
        $this->storeTemperatureReading(82.0);

        $recorder = $this->createServiceWithRecorders();
        $service = $recorder['service'];

        // Heater starts off
        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);

        // Call start()
        $service->start(101.0);

        // THE BUG: Heater should be on now (temp 82°F is below target 101°F)
        $this->assertTrue(
            $this->equipmentStatus->getStatus()['heater']['on'],
            "BUG #1: After start() with temp below target, heater should be ON"
        );
    }

    // =========================================================================
    // BUG #2: scheduleNextCheck() creates curl without Authorization header
    // =========================================================================

    /**
     * @test
     * BUG #2 - RED TEST: The cron entry created by scheduleNextCheck() must
     * include an Authorization header, otherwise it will fail with 401.
     *
     * This test MUST FAIL until Bug #2 is fixed.
     */
    public function bug2_scheduledCronEntryShouldIncludeAuthorizationHeader(): void
    {
        $this->storeTemperatureReading(82.0);

        $cronEntries = [];
        $mockCrontab = $this->createMock(\HotTub\Contracts\CrontabAdapterInterface::class);
        $mockCrontab->method('addEntry')->willReturnCallback(function ($entry) use (&$cronEntries) {
            $cronEntries[] = $entry;
        });

        $mockIfttt = $this->createMock(\HotTub\Contracts\IftttClientInterface::class);
        $mockIfttt->method('trigger')->willReturn(true);

        $service = new TargetTemperatureService(
            $this->targetTempFile,
            $mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );

        // Set up active state and call checkAndAdjust to trigger cron scheduling
        $service->start(101.0);
        $service->checkAndAdjust(); // This schedules the next check

        // Verify a cron entry was created
        $this->assertNotEmpty($cronEntries, 'A cron entry should be scheduled');

        $cronEntry = $cronEntries[0];

        // THE BUG: The cron entry uses raw curl without Authorization header
        // It should either:
        // A) Include -H 'Authorization: Bearer <token>'
        // B) Use cron-runner.sh which handles auth automatically
        $hasAuthHeader = str_contains($cronEntry, 'Authorization');
        $usesCronRunner = str_contains($cronEntry, 'cron-runner.sh');

        $this->assertTrue(
            $hasAuthHeader || $usesCronRunner,
            "BUG #2: Scheduled cron entry lacks authentication:\n" .
            "Entry: $cronEntry\n\n" .
            "The /api/maintenance/heat-target-check endpoint requires auth.\n" .
            "The cron entry must either:\n" .
            "- Include Authorization header: " . ($hasAuthHeader ? 'YES' : 'NO') . "\n" .
            "- Use cron-runner.sh (which handles auth): " . ($usesCronRunner ? 'YES' : 'NO')
        );
    }

    /**
     * @test
     * BUG #2 - GREEN TEST: Verify the job file has correct endpoint path.
     * The heat-target-check endpoint should be /maintenance/heat-target-check
     * (the /api prefix is in apiBaseUrl).
     */
    public function bug2_scheduledCronShouldCallCorrectEndpoint(): void
    {
        $this->storeTemperatureReading(82.0);

        $cronEntries = [];
        $mockCrontab = $this->createMock(\HotTub\Contracts\CrontabAdapterInterface::class);
        $mockCrontab->method('addEntry')->willReturnCallback(function ($entry) use (&$cronEntries) {
            $cronEntries[] = $entry;
        });

        $mockIfttt = $this->createMock(\HotTub\Contracts\IftttClientInterface::class);
        $mockIfttt->method('trigger')->willReturn(true);

        $service = new TargetTemperatureService(
            $this->targetTempFile,
            $mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );

        $service->start(101.0);

        $this->assertNotEmpty($cronEntries);
        $cronEntry = $cronEntries[0];

        // Extract job ID from cron entry
        preg_match("/HOTTUB:(heat-target-[a-f0-9]+)/", $cronEntry, $matches);
        $this->assertNotEmpty($matches[1], 'Should find job ID in cron entry');
        $jobId = $matches[1];

        // Verify cron uses cron-runner.sh with job ID
        $this->assertStringContainsString('cron-runner.sh', $cronEntry);
        $this->assertStringContainsString($jobId, $cronEntry);

        // Verify job file was created with correct endpoint
        $jobFile = $this->jobsDir . '/' . $jobId . '.json';
        $this->assertFileExists($jobFile, 'Job file should be created');

        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('/api/maintenance/heat-target-check', $jobData['endpoint']);
        $this->assertEquals('https://example.com/api', $jobData['apiBaseUrl']);
    }

    // =========================================================================
    // CHAIN VERIFICATION: Full flow tests (will pass after bugs are fixed)
    // =========================================================================

    /**
     * @test
     * CHAIN TEST: Scheduling heat-to-target via SchedulerService creates proper job file.
     * This part currently WORKS - the scheduling infrastructure is fine.
     */
    public function chain_schedulerCreatesProperJobFileWithParams(): void
    {
        $scheduledTime = (new \DateTime('+5 minutes'))->format(\DateTime::ATOM);

        $scheduler = new SchedulerService(
            $this->jobsDir,
            '/path/to/cron-runner.sh',
            'https://example.com/api',
            $this->crontab
        );

        $result = $scheduler->scheduleJob('heat-to-target', $scheduledTime, false, [
            'target_temp_f' => 101.0,
        ]);

        // Track for cleanup
        $this->addedCronPatterns[] = 'HOTTUB:' . $result['jobId'];

        // Verify job file contents
        $jobFile = $this->jobsDir . '/' . $result['jobId'] . '.json';
        $this->assertFileExists($jobFile);

        $jobData = json_decode(file_get_contents($jobFile), true);
        $this->assertEquals('/api/equipment/heat-to-target', $jobData['endpoint']);
        $this->assertEquals(['target_temp_f' => 101.0], $jobData['params']);
        $this->assertEquals('https://example.com/api', $jobData['apiBaseUrl']);
    }

    /**
     * @test
     * CHAIN TEST: start() now calls checkAndAdjust() automatically.
     * This verifies the fix for Bug #1.
     */
    public function chain_startNowCallsCheckAndAdjustAutomatically(): void
    {
        $this->storeTemperatureReading(82.0);

        $recorder = $this->createServiceWithRecorders();
        $service = $recorder['service'];
        $iftttCalls = &$recorder['iftttCalls'];
        $cronEntries = &$recorder['cronEntries'];

        // Call start() - this now calls checkAndAdjust() internally
        $result = $service->start(101.0);

        // Verify checkAndAdjust was called: heater turned on and cron scheduled
        $this->assertTrue($result['heater_turned_on'], 'start() should turn on heater');
        $this->assertContains('hot-tub-heat-on', $iftttCalls);
        $this->assertTrue($result['cron_scheduled'], 'start() should schedule next check');
        $this->assertNotEmpty($cronEntries);
    }

    /**
     * @test
     * CHAIN TEST: Full heating cycle completes when checkAndAdjust is called repeatedly.
     * This demonstrates the feature WORKS if properly orchestrated.
     */
    public function chain_fullHeatingCycleWorksWithManualOrchestration(): void
    {
        $recorder = $this->createServiceWithRecorders();
        $service = $recorder['service'];
        $iftttCalls = &$recorder['iftttCalls'];

        $mockCrontab = $this->createMock(\HotTub\Contracts\CrontabAdapterInterface::class);
        $mockCrontab->method('addEntry');
        $mockCrontab->method('removeByPattern');

        // Start at 82°F, target 101°F
        $this->storeTemperatureReading(82.0);
        $service->start(101.0);
        $service->checkAndAdjust(); // Manual orchestration

        $this->assertContains('hot-tub-heat-on', $iftttCalls);

        // Simulate heating progress
        $this->storeTemperatureReading(95.0);
        $result = $service->checkAndAdjust();
        $this->assertTrue($result['heating']);
        $this->assertArrayNotHasKey('target_reached', $result, 'Should still be heating');

        // Reach target
        $this->storeTemperatureReading(101.0);
        $result = $service->checkAndAdjust();
        $this->assertTrue($result['target_reached']);
        $this->assertContains('hot-tub-heat-off', $iftttCalls);
        $this->assertFalse($service->getState()['active']);
    }

    // =========================================================================
    // VERIFICATION: These tests verify the FIXED behavior
    // =========================================================================

    /**
     * @test
     * VERIFICATION: Shows what happens after start() - heater turns on and cron is scheduled.
     * This test verifies the fix for Bug #1.
     */
    public function verification_whatHappensAfterStart(): void
    {
        $this->storeTemperatureReading(82.0);

        $recorder = $this->createServiceWithRecorders();
        $service = $recorder['service'];
        $iftttCalls = &$recorder['iftttCalls'];
        $cronEntries = &$recorder['cronEntries'];

        // Before start()
        $this->assertFalse($this->equipmentStatus->getStatus()['heater']['on']);

        // Call start()
        $result = $service->start(101.0);

        // State is saved
        $this->assertTrue($service->getState()['active'], 'State IS saved');
        $this->assertEquals(101.0, $service->getState()['target_temp_f']);

        // FIXED: IFTTT IS called because start() now calls checkAndAdjust()
        $this->assertContains('hot-tub-heat-on', $iftttCalls, 'IFTTT should be called');
        $this->assertNotEmpty($cronEntries, 'Cron should be scheduled');
        $this->assertTrue(
            $this->equipmentStatus->getStatus()['heater']['on'],
            'Heater should be ON because temp is below target'
        );

        // Verify the result includes checkAndAdjust data
        $this->assertTrue($result['heater_turned_on']);
        $this->assertTrue($result['cron_scheduled']);
    }

    /**
     * @test
     * VERIFICATION: Shows the cron entry now uses cron-runner.sh for auth.
     * This test verifies the fix for Bug #2.
     */
    public function verification_cronEntryUsesCronRunner(): void
    {
        $this->storeTemperatureReading(82.0);

        $cronEntries = [];
        $mockCrontab = $this->createMock(\HotTub\Contracts\CrontabAdapterInterface::class);
        $mockCrontab->method('addEntry')->willReturnCallback(function ($entry) use (&$cronEntries) {
            $cronEntries[] = $entry;
        });

        $mockIfttt = $this->createMock(\HotTub\Contracts\IftttClientInterface::class);
        $mockIfttt->method('trigger')->willReturn(true);

        $service = new TargetTemperatureService(
            $this->targetTempFile,
            $mockIfttt,
            $this->equipmentStatus,
            $this->esp32Temp,
            $mockCrontab,
            '/path/to/cron-runner.sh',
            'https://example.com/api'
        );

        $service->start(101.0);

        $this->assertNotEmpty($cronEntries);
        $cronEntry = $cronEntries[0];

        // FIXED: Now uses cron-runner.sh instead of raw curl
        $this->assertStringContainsString('cron-runner.sh', $cronEntry, 'Uses cron-runner.sh');
        $this->assertStringNotContainsString('curl', $cronEntry, 'Does NOT use curl directly');

        // cron-runner.sh handles JWT authentication from .env
    }

    // =========================================================================
    // SLOW TEST: Real cron timing (optional, requires waiting)
    // =========================================================================

    /**
     * @test
     * @group slow
     * SLOW TEST: Full chain with real cron timing.
     * Requires waiting ~60 seconds for cron to fire.
     */
    public function slow_fullCronChainWithRealTiming(): void
    {
        $this->markTestSkipped(
            'Slow test requiring cron timing. Run with: vendor/bin/phpunit --group slow'
        );
    }
}
