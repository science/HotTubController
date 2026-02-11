<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction;

use HotTub\Tests\PreProduction\Helpers\ApiClient;
use HotTub\Tests\PreProduction\Helpers\CronSimulator;
use HotTub\Tests\PreProduction\Helpers\Esp32Simulator;
use HotTub\Tests\PreProduction\Helpers\IftttVerifier;
use PHPUnit\Framework\TestCase;

/**
 * TRUE End-to-End tests for DTDT "Ready By" scheduling.
 *
 * These tests exercise the full DTDT chain end-to-end:
 *   1. POST /api/schedule → DtdtService::createReadyBySchedule() → job file + recurring cron
 *   2. cron-runner.sh reads job file → POSTs to /api/maintenance/dtdt-wakeup
 *   3. DtdtService::handleWakeUp() → reads temp, calculates timing, creates precision cron
 *   4. cron-runner.sh fires precision cron → POSTs to /api/equipment/heat-to-target
 *   5. TargetTemperatureService starts heating → heat-target-check cron chain
 *   6. Target reached → heater off → IFTTT ground truth: [heat-on, heat-off]
 *
 * What's proven by these tests (that unit tests can't):
 * - Job file params survive cron-runner.sh round-trip (sed extraction of flat JSON)
 * - Precision cron job files have correct endpoint + params
 * - One-off crons are self-removed by cron-runner.sh
 * - Recurring crons are preserved by cron-runner.sh
 * - IFTTT ground truth: the right hardware signals fire in the right order
 *
 * @group pre-production
 * @group real-paths
 * @group dtdt
 */
class DtdtReadyByE2ETest extends TestCase
{
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 8091;
    private const SERVER_START_TIMEOUT = 5;

    private string $backendDir;
    private string $storageDir;
    private string $envFile;
    private string $envBackup;
    private ?int $serverPid = null;

    private ApiClient $api;
    private CronSimulator $cron;
    private Esp32Simulator $esp32;
    private IftttVerifier $ifttt;

    private string $cronJwt;
    private string $esp32ApiKey;

    /** @var string[] Job IDs to clean up in tearDown */
    private array $createdJobIds = [];

    protected function setUp(): void
    {
        $this->backendDir = dirname(__DIR__, 2);
        $this->storageDir = $this->backendDir . '/storage';
        $this->envFile = $this->backendDir . '/.env';
        $this->envBackup = $this->backendDir . '/.env.dtdt-e2e-backup';

        // CRITICAL: Check for orphaned backup from killed test and restore it first
        if (file_exists($this->envBackup)) {
            rename($this->envBackup, $this->envFile);
        }

        // Backup existing .env
        if (file_exists($this->envFile)) {
            copy($this->envFile, $this->envBackup);
        }

        // Create test environment
        $this->createTestEnv();

        // Clean up any leftover state
        $this->cleanupState();

        // Start the test server
        $this->startServer();

        // Initialize helpers
        $baseUrl = "http://" . self::SERVER_HOST . ":" . self::SERVER_PORT;
        $this->api = new ApiClient($baseUrl, $this->cronJwt);
        $this->cron = new CronSimulator();
        $this->esp32 = new Esp32Simulator($baseUrl, $this->esp32ApiKey);
        $this->ifttt = new IftttVerifier($this->backendDir . '/logs/events.log');
    }

    protected function tearDown(): void
    {
        // Cancel any created schedules (best-effort)
        foreach ($this->createdJobIds as $jobId) {
            try {
                $this->api->delete("/api/schedule/$jobId");
            } catch (\Throwable $e) {
                // Ignore - cleanup is best-effort
            }
        }

        // Stop server
        $this->stopServer();

        // Clean up ALL HOTTUB crontab entries (safety net)
        $this->cron->removeAllHottubEntries();

        // Restore original .env
        if (file_exists($this->envBackup)) {
            rename($this->envBackup, $this->envFile);
        } elseif (file_exists($this->envFile)) {
            unlink($this->envFile);
        }

        // Clean up state
        $this->cleanupState();
    }

    // =========================================================================
    // SETUP HELPERS
    // =========================================================================

    private function createTestEnv(): void
    {
        $this->esp32ApiKey = 'e2e-test-esp32-key-' . bin2hex(random_bytes(8));

        // Create JWT
        $secret = 'e2e-test-jwt-secret-' . bin2hex(random_bytes(8));
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode(json_encode([
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => 'e2e-test-user',
            'role' => 'admin',
        ]));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
        $this->cronJwt = "$header.$payload.$signature";

        $port = self::SERVER_PORT;
        $env = <<<ENV
APP_ENV=testing
EXTERNAL_API_MODE=stub
JWT_SECRET=$secret
JWT_EXPIRY_HOURS=1
CRON_JWT={$this->cronJwt}
ESP32_API_KEY={$this->esp32ApiKey}
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=password
API_BASE_URL=http://127.0.0.1:{$port}
ENV;

        file_put_contents($this->envFile, $env);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function cleanupState(): void
    {
        // Clean ALL job files (not just heat-target-*)
        $jobsDir = $this->storageDir . '/scheduled-jobs';
        if (is_dir($jobsDir)) {
            foreach (glob("$jobsDir/*.json") as $file) {
                @unlink($file);
            }
        }

        // Clean state files
        $stateFiles = [
            $this->storageDir . '/state/target-temperature.json',
            $this->storageDir . '/state/equipment-status.json',
            $this->storageDir . '/state/esp32-temperature.json',
            $this->storageDir . '/state/heat-target-settings.json',
            $this->storageDir . '/state/heating-characteristics.json',
            $this->storageDir . '/state/esp32-sensor-config.json',
        ];
        foreach ($stateFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }

        // Clean events log
        $eventsLog = $this->backendDir . '/logs/events.log';
        if (file_exists($eventsLog)) {
            @unlink($eventsLog);
        }
    }

    private function startServer(): void
    {
        $docroot = $this->backendDir . '/public';
        $router = $docroot . '/router.php';
        $host = self::SERVER_HOST;
        $port = self::SERVER_PORT;

        $cmd = sprintf(
            'php -S %s:%d -t %s %s > /dev/null 2>&1 & echo $!',
            $host, $port,
            escapeshellarg($docroot),
            escapeshellarg($router)
        );

        $this->serverPid = (int) trim(shell_exec($cmd));

        // Wait for server
        $startTime = time();
        while (time() - $startTime < self::SERVER_START_TIMEOUT) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000);
        }

        $this->fail("Test server failed to start on $host:$port within " . self::SERVER_START_TIMEOUT . "s");
    }

    private function stopServer(): void
    {
        if ($this->serverPid !== null) {
            @posix_kill($this->serverPid, SIGTERM);
            usleep(200000);
            // Force kill if still running
            if (@posix_kill($this->serverPid, 0)) {
                @posix_kill($this->serverPid, SIGKILL);
            }
            $this->serverPid = null;
        }
    }

    // =========================================================================
    // DATA SEEDING HELPERS
    // =========================================================================

    /**
     * Write heating characteristics file.
     */
    private function seedHeatingCharacteristics(float $velocity, float $lag, float $maxCoolingK): void
    {
        $stateDir = $this->storageDir . '/state';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $data = [
            'heating_velocity_f_per_min' => $velocity,
            'startup_lag_minutes' => $lag,
            'max_cooling_k' => $maxCoolingK,
        ];

        file_put_contents(
            $stateDir . '/heating-characteristics.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Configure ESP32 sensor roles so calibrated temperature service returns water temp.
     *
     * Maps the Esp32Simulator's default sensor address to 'water' role.
     */
    private function seedSensorConfig(): void
    {
        $stateDir = $this->storageDir . '/state';
        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        // The Esp32Simulator uses address '28:E2:E2:SI:MU:LA:TE:01'
        $config = [
            'sensors' => [
                '28:E2:E2:SI:MU:LA:TE:01' => [
                    'role' => 'water',
                    'calibration_offset_c' => 0.0,
                ],
            ],
        ];

        file_put_contents(
            $stateDir . '/esp32-sensor-config.json',
            json_encode($config, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Set schedule_mode to 'ready_by' via the API.
     */
    private function setReadyByMode(): void
    {
        $response = $this->api->put('/api/settings/heat-target', [
            'enabled' => true,
            'target_temp_f' => 103,
            'schedule_mode' => 'ready_by',
        ]);

        $this->assertEquals(200, $response['status'],
            "Failed to set ready_by mode. Response: " . json_encode($response['body']));
    }

    /**
     * Build a scheduledTime string (HH:MM+offset) that's $minutes minutes from now in UTC.
     */
    private function buildScheduledTime(int $minutesFromNow): string
    {
        $dt = new \DateTime("+{$minutesFromNow} minutes", new \DateTimeZone('UTC'));
        return $dt->format('H:i') . '+00:00';
    }

    /**
     * Read a job file and return decoded content.
     */
    private function readJobFile(string $jobId): ?array
    {
        $file = $this->storageDir . "/scheduled-jobs/$jobId.json";
        if (!file_exists($file)) {
            return null;
        }
        return json_decode(file_get_contents($file), true);
    }

    /**
     * Output a step description (visible in verbose test output).
     */
    private function step(string $description): void
    {
        fwrite(STDERR, "\n    → $description\n");
    }

    // =========================================================================
    // E2E TESTS
    // =========================================================================

    /**
     * @test
     * Sanity check: Server is running and health endpoint works.
     */
    public function e2e_serverHealthCheck(): void
    {
        $response = $this->api->get('/api/health');
        $this->assertEquals(200, $response['status'], 'Health check should return 200');
    }

    /**
     * @test
     * FULL CYCLE: DTDT precision scheduling complete E2E workflow.
     *
     * This is THE test that proves the full DTDT chain works end-to-end.
     * It exercises every step from schedule creation through IFTTT ground truth.
     */
    public function e2e_fullDtdtPrecisionSchedulingCycle(): void
    {
        // =====================================================================
        // STEP 1: Seed heating characteristics
        // =====================================================================
        $this->step('STEP 1: Seed heating characteristics (velocity=0.5, lag=5, max_cooling_k=0.001)');

        $this->seedHeatingCharacteristics(0.5, 5.0, 0.001);

        $this->step('  ✓ Heating characteristics seeded');

        // =====================================================================
        // STEP 2: Configure water sensor role
        // =====================================================================
        $this->step('STEP 2: Configure water sensor role');

        $this->seedSensorConfig();

        $this->step('  ✓ Sensor config seeded');

        // =====================================================================
        // STEP 3: Set schedule_mode to 'ready_by'
        // =====================================================================
        $this->step('STEP 3: Set schedule_mode to ready_by');

        $this->setReadyByMode();

        $this->step('  ✓ Schedule mode set to ready_by');

        // =====================================================================
        // STEP 4: Report initial water temperature (85°F) via ESP32 API
        // =====================================================================
        $this->step('STEP 4: Report water temperature 85°F via ESP32 API');

        $tempResponse = $this->esp32->reportTemperature(85.0);
        $this->assertEquals(200, $tempResponse['status'],
            "ESP32 temp report failed: " . json_encode($tempResponse['body']));

        $this->step('  ✓ Temperature reported: 85°F');

        // =====================================================================
        // STEP 5: Create recurring heat-to-target schedule via POST /api/schedule
        // =====================================================================
        // readyByTime = 3 hours from now. With water=85, target=103, velocity=0.5, lag=5:
        //   heatMinutes = (103-85)/0.5 + 5 = 41 min
        //   startTimestamp = readyBy - 41min = ~2h19m from now → precision_scheduled path
        // maxHeatMinutes = (103-58)/0.5 + 5 + 15 = 110 min → wake-up is 110 min before readyBy
        $readyByTime = $this->buildScheduledTime(180); // 3 hours from now
        $scheduleCreatedAt = time(); // Capture for timing assertions later

        $this->step("STEP 5: Create recurring schedule (readyByTime=$readyByTime, target=103°F)");

        $response = $this->api->post('/api/schedule', [
            'action' => 'heat-to-target',
            'scheduledTime' => $readyByTime,
            'target_temp_f' => 103,
            'recurring' => true,
        ]);

        $this->assertEquals(201, $response['status'],
            "Failed to create schedule. Response: " . json_encode($response['body']));

        $jobId = $response['body']['jobId'];
        $this->assertNotEmpty($jobId, 'Should return a job ID');
        $this->assertTrue($response['body']['recurring'], 'Should be recurring');
        $this->createdJobIds[] = $jobId;

        $this->step("  ✓ Schedule created: jobId=$jobId");

        // =====================================================================
        // STEP 6: Verify wake-up cron in REAL crontab
        // =====================================================================
        $this->step('STEP 6: Verify wake-up cron in crontab');

        $entries = $this->cron->getEntriesByJobId($jobId);
        $this->assertNotEmpty($entries,
            "No cron entry for $jobId in crontab.\n" .
            "Crontab: " . (shell_exec('crontab -l 2>/dev/null') ?: '(empty)'));

        // Verify job file has correct endpoint and params
        $jobData = $this->readJobFile($jobId);
        $this->assertNotNull($jobData, "Job file should exist for $jobId");

        $this->assertEquals('/api/maintenance/dtdt-wakeup', $jobData['endpoint'],
            "Job file endpoint should be dtdt-wakeup.\nJob data: " . json_encode($jobData));

        $this->assertTrue($jobData['recurring'],
            "Job should be recurring.\nJob data: " . json_encode($jobData));

        $this->assertArrayHasKey('params', $jobData, 'Job should have params');
        $this->assertEquals($readyByTime, $jobData['params']['ready_by_time'],
            'Job params should include ready_by_time');
        $this->assertEquals(103, $jobData['params']['target_temp_f'],
            'Job params should include target_temp_f');

        $this->step('  ✓ Wake-up cron verified: endpoint=dtdt-wakeup, params correct');

        // =====================================================================
        // STEP 7: Fire wake-up cron via cron-runner.sh
        // =====================================================================
        $this->step("STEP 7: Fire wake-up cron (jobId=$jobId) via cron-runner.sh");

        $wakeUpResult = $this->cron->fireByJobId($jobId);

        $this->assertEquals(0, $wakeUpResult['exitCode'],
            "Wake-up cron failed!\n" .
            "Exit code: {$wakeUpResult['exitCode']}\n" .
            "Stdout: {$wakeUpResult['stdout']}\n" .
            "Stderr: {$wakeUpResult['stderr']}");

        // Recurring cron should NOT be self-removed by cron-runner.sh
        $this->assertFalse($wakeUpResult['selfRemoved'],
            "Recurring wake-up cron should NOT be removed by cron-runner.sh.\n" .
            "cron-runner.sh should preserve recurring entries.");

        $this->step('  ✓ Wake-up cron fired, recurring entry preserved');

        // =====================================================================
        // STEP 8: Verify precision one-off cron was created
        // =====================================================================
        $this->step('STEP 8: Verify precision one-off cron was created');

        // Get all HOTTUB entries, find the new one-off (not the recurring wake-up)
        $allEntries = $this->cron->getAllHottubEntries();
        $precisionEntries = array_values(array_filter($allEntries, function ($entry) use ($jobId) {
            // Not the recurring wake-up cron
            return !str_contains($entry, $jobId) && str_contains($entry, ':ONCE');
        }));

        $this->assertNotEmpty($precisionEntries,
            "No precision one-off cron created after wake-up.\n" .
            "All HOTTUB entries: " . implode("\n", $allEntries));

        $precisionEntry = $precisionEntries[0];
        $precisionJobId = $this->cron->extractJobId($precisionEntry);
        $this->assertNotNull($precisionJobId, 'Should extract precision job ID');

        // Verify precision job file
        $precisionJobData = $this->readJobFile($precisionJobId);
        $this->assertNotNull($precisionJobData, "Precision job file should exist: $precisionJobId");

        $this->assertEquals('/api/equipment/heat-to-target', $precisionJobData['endpoint'],
            "Precision job endpoint should be heat-to-target.\n" .
            "Job data: " . json_encode($precisionJobData));

        $this->assertFalse($precisionJobData['recurring'],
            "Precision job should be one-off (not recurring).\n" .
            "Job data: " . json_encode($precisionJobData));

        $this->assertEquals(103, $precisionJobData['params']['target_temp_f'] ?? null,
            "Precision job params should have target_temp_f=103.\n" .
            "Job data: " . json_encode($precisionJobData));

        // TIMING ASSERTION: Verify precision cron is scheduled at the correct time.
        //
        // This catches the class of bugs (timezone, formula) that kept breaking
        // the original heat-to-target flow. Without this, a precision cron at the
        // wrong time would pass all other assertions.
        //
        // Expected calculation (no ambient sensor → no cooling projection):
        //   projectedTempF = waterTempF = 85°F
        //   heatMinutes = (103 - 85) / 0.5 + 5 = 41 min
        //   startTime = readyBy - 41 min = (scheduleCreatedAt + 180 min) - 41 min
        //             = scheduleCreatedAt + 139 min
        $expectedHeatMinutes = (103 - 85) / 0.5 + 5; // = 41
        $expectedStartSecondsFromCreation = (180 - $expectedHeatMinutes) * 60; // 139 min = 8340s

        $precisionScheduledTime = $this->cron->parseScheduledTime($precisionEntry);
        $actualSecondsFromCreation = $precisionScheduledTime - $scheduleCreatedAt;
        $actualMinutesFromCreation = round($actualSecondsFromCreation / 60, 1);
        $expectedMinutesFromCreation = round($expectedStartSecondsFromCreation / 60, 1);

        // Tolerance: ±3 minutes covers minute-boundary rounding (up to 60s each for
        // readyBy truncation and CronSchedulingService rounding) plus test execution time.
        $this->assertEqualsWithDelta(
            $expectedStartSecondsFromCreation,
            $actualSecondsFromCreation,
            180, // ±3 minutes
            "PRECISION CRON TIMING BUG: Scheduled at wrong time!\n" .
            "Expected: ~{$expectedMinutesFromCreation} min from schedule creation (~139 min)\n" .
            "Actual: {$actualMinutesFromCreation} min from schedule creation\n" .
            "Difference: " . round(abs($actualSecondsFromCreation - $expectedStartSecondsFromCreation) / 60, 1) . " min\n" .
            "This likely indicates a timezone bug or formula error in handleWakeUp().\n" .
            "Precision cron entry: $precisionEntry\n" .
            "Scheduled for: " . date('Y-m-d H:i:s', $precisionScheduledTime) . "\n" .
            "Schedule created at: " . date('Y-m-d H:i:s', $scheduleCreatedAt)
        );

        // Also validate the precision cron is in a future minute (prevents race condition bug)
        $this->cron->assertValidScheduleTime($precisionEntry,
            'Precision cron must be in a future minute to fire correctly');

        $this->step(
            "  ✓ Precision cron created: jobId=$precisionJobId, endpoint=heat-to-target\n" .
            "    Scheduled: " . date('H:i', $precisionScheduledTime) .
            " (~{$actualMinutesFromCreation} min from now, expected ~{$expectedMinutesFromCreation} min)"
        );

        // =====================================================================
        // STEP 9: Fire precision cron via cron-runner.sh
        // =====================================================================
        $this->step("STEP 9: Fire precision cron (jobId=$precisionJobId) via cron-runner.sh");

        $precisionResult = $this->cron->fireByJobId($precisionJobId);

        $this->assertEquals(0, $precisionResult['exitCode'],
            "Precision cron failed!\n" .
            "Exit code: {$precisionResult['exitCode']}\n" .
            "Stdout: {$precisionResult['stdout']}\n" .
            "Stderr: {$precisionResult['stderr']}");

        // One-off cron SHOULD be self-removed by cron-runner.sh
        $this->assertTrue($precisionResult['selfRemoved'],
            "One-off precision cron should be self-removed by cron-runner.sh.\n" .
            "cron-runner.sh should remove one-off entries after execution.");

        $this->step('  ✓ Precision cron fired, one-off entry self-removed');

        // =====================================================================
        // STEP 10: Verify IFTTT: hot-tub-heat-on (heater turned on)
        // =====================================================================
        $this->step('STEP 10: Verify IFTTT heat-on signal');

        $this->ifttt->assertEventOccurred('hot-tub-heat-on',
            'Heater should be turned ON after precision cron fires heat-to-target');

        $this->step('  ✓ IFTTT: hot-tub-heat-on fired');

        // =====================================================================
        // STEP 11: Verify heat-target-check cron chain started
        // =====================================================================
        $this->step('STEP 11: Verify heat-target-check cron chain started');

        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($heatTargetEntries,
            "Heat-target-check cron chain should have started.\n" .
            "All HOTTUB entries: " . implode("\n", $this->cron->getAllHottubEntries()));

        $this->step('  ✓ Heat-target-check cron chain active');

        // =====================================================================
        // STEP 12: Report target reached (103.5°F) via ESP32 API
        // =====================================================================
        $this->step('STEP 12: Report temperature 103.5°F (target reached)');

        $tempResponse = $this->esp32->reportTemperature(103.5);
        $this->assertEquals(200, $tempResponse['status']);

        $this->step('  ✓ Temperature reported: 103.5°F');

        // =====================================================================
        // STEP 13: Fire heat-target-check cron
        // =====================================================================
        $this->step('STEP 13: Fire heat-target-check cron');

        $checkResult = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $checkResult['exitCode'],
            "Heat-target-check cron failed!\n" .
            "Exit code: {$checkResult['exitCode']}\n" .
            "Stdout: {$checkResult['stdout']}\n" .
            "Stderr: {$checkResult['stderr']}");

        $this->step('  ✓ Heat-target-check cron executed');

        // =====================================================================
        // STEP 14: Verify cycle complete
        // =====================================================================
        $this->step('STEP 14: Verify heating cycle complete');

        // No more heat-target crons should remain
        $remainingEntries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($remainingEntries,
            "No heat-target crons should remain after target reached.\n" .
            "Remaining: " . implode("\n", $remainingEntries));

        // IFTTT ground truth: [heat-on, heat-off]
        $this->ifttt->assertEventsInOrder(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            'Complete IFTTT signal chain for full DTDT cycle'
        );

        $this->step('  ✓ IFTTT ground truth: [hot-tub-heat-on, hot-tub-heat-off]');

        // =====================================================================
        // STEP 15: Cleanup - cancel recurring schedule
        // =====================================================================
        $this->step("STEP 15: Cancel recurring schedule (jobId=$jobId)");

        $cancelResponse = $this->api->delete("/api/schedule/$jobId");
        $this->assertEquals(200, $cancelResponse['status'],
            "Failed to cancel schedule: " . json_encode($cancelResponse['body']));

        // Remove from cleanup list since we just canceled it
        $this->createdJobIds = array_filter($this->createdJobIds, fn($id) => $id !== $jobId);

        // Verify wake-up cron removed from crontab
        $wakeUpEntries = $this->cron->getEntriesByJobId($jobId);
        $this->assertEmpty($wakeUpEntries,
            "Wake-up cron should be removed after cancel.\n" .
            "Remaining: " . implode("\n", $wakeUpEntries));

        $this->step('  ✓ Recurring schedule cancelled, cron removed');

        $this->step("\n  ══════════════════════════════════════════════");
        $this->step("  ✓ FULL DTDT PRECISION CYCLE COMPLETE - ALL SYSTEMS GO!");
        $this->step("  ══════════════════════════════════════════════\n");
    }

    /**
     * @test
     * DTDT immediate start: wake-up fires but calculated start time is already past.
     *
     * When the remaining time before ready_by is less than the heat time needed,
     * the system starts heating immediately (no precision cron).
     */
    public function e2e_dtdtImmediateStartWhenTooLate(): void
    {
        // =====================================================================
        // Setup: seed data
        // =====================================================================
        $this->step('Setup: Seed heating characteristics, sensor config, ready_by mode');

        $this->seedHeatingCharacteristics(0.5, 5.0, 0.001);
        $this->seedSensorConfig();
        $this->setReadyByMode();

        // Report 85°F water temp
        $this->esp32->reportTemperature(85.0);

        $this->step('  ✓ Environment seeded');

        // =====================================================================
        // Create schedule with readyByTime = 10 minutes from now
        // Heat time = (103-85)/0.5 + 5 = 41 min > 10 min remaining → immediate start
        // maxHeatMinutes = (103-58)/0.5 + 5 + 15 = 110 min → wake-up at -100 min (fires now)
        // =====================================================================
        $readyByTime = $this->buildScheduledTime(10);

        $this->step("Create schedule: readyByTime=$readyByTime (10 min from now), target=103°F");

        $response = $this->api->post('/api/schedule', [
            'action' => 'heat-to-target',
            'scheduledTime' => $readyByTime,
            'target_temp_f' => 103,
            'recurring' => true,
        ]);

        $this->assertEquals(201, $response['status'],
            "Failed to create schedule: " . json_encode($response['body']));

        $jobId = $response['body']['jobId'];
        $this->createdJobIds[] = $jobId;

        $this->step("  ✓ Schedule created: jobId=$jobId");

        // =====================================================================
        // Fire wake-up cron
        // =====================================================================
        $this->step('Fire wake-up cron');

        $wakeUpResult = $this->cron->fireByJobId($jobId);
        $this->assertEquals(0, $wakeUpResult['exitCode'],
            "Wake-up cron failed: " . $wakeUpResult['stderr']);

        $this->step('  ✓ Wake-up fired');

        // =====================================================================
        // Verify: NO precision cron created (start time is in past)
        // =====================================================================
        $this->step('Verify: no precision cron, immediate IFTTT heat-on');

        $allEntries = $this->cron->getAllHottubEntries();
        $oneOffEntries = array_values(array_filter($allEntries, function ($entry) use ($jobId) {
            return !str_contains($entry, $jobId) && str_contains($entry, ':ONCE')
                && !str_contains($entry, 'HEAT-TARGET:ONCE');
        }));

        $this->assertEmpty($oneOffEntries,
            "Should NOT have precision cron (start time in past).\n" .
            "One-off entries found: " . implode("\n", $oneOffEntries));

        // IFTTT: heater should be on immediately
        $this->ifttt->assertEventOccurred('hot-tub-heat-on',
            'Heater should start immediately when start time is past');

        // Heat-target-check chain should have started
        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($heatTargetEntries,
            'Heat-target-check cron chain should be active');

        $this->step('  ✓ Immediate start verified, heat-target-check chain active');

        // =====================================================================
        // Complete heating cycle
        // =====================================================================
        $this->step('Complete heating cycle: report 103.5°F, fire check cron');

        $this->esp32->reportTemperature(103.5);
        $checkResult = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $checkResult['exitCode']);

        // Verify final state
        $this->ifttt->assertEventsInOrder(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            'Complete IFTTT chain for immediate-start DTDT cycle'
        );

        $this->step('  ✓ IFTTT ground truth: [hot-tub-heat-on, hot-tub-heat-off]');

        // Cleanup
        $this->api->delete("/api/schedule/$jobId");
        $this->createdJobIds = array_filter($this->createdJobIds, fn($id) => $id !== $jobId);
    }

    /**
     * @test
     * DTDT already at target: wake-up fires but tub is already warm enough.
     *
     * No heating occurs, no precision cron created, no IFTTT events.
     */
    public function e2e_dtdtAlreadyAtTarget(): void
    {
        // =====================================================================
        // Setup
        // =====================================================================
        $this->step('Setup: Seed data with high water temperature');

        $this->seedHeatingCharacteristics(0.5, 5.0, 0.001);
        $this->seedSensorConfig();
        $this->setReadyByMode();

        // Report 104°F (above 103 target)
        $this->esp32->reportTemperature(104.0);

        $this->step('  ✓ Environment seeded, water temp = 104°F');

        // =====================================================================
        // Create schedule and fire wake-up
        // =====================================================================
        $readyByTime = $this->buildScheduledTime(180);

        $this->step("Create schedule and fire wake-up (readyByTime=$readyByTime)");

        $response = $this->api->post('/api/schedule', [
            'action' => 'heat-to-target',
            'scheduledTime' => $readyByTime,
            'target_temp_f' => 103,
            'recurring' => true,
        ]);

        $this->assertEquals(201, $response['status']);
        $jobId = $response['body']['jobId'];
        $this->createdJobIds[] = $jobId;

        $wakeUpResult = $this->cron->fireByJobId($jobId);
        $this->assertEquals(0, $wakeUpResult['exitCode'],
            "Wake-up cron failed: " . $wakeUpResult['stderr']);

        $this->step('  ✓ Wake-up fired');

        // =====================================================================
        // Verify: no IFTTT events, no precision cron
        // =====================================================================
        $this->step('Verify: no IFTTT events, no precision cron');

        $this->ifttt->assertNoEvents('No IFTTT events when already at target');

        // No precision cron should exist
        $allEntries = $this->cron->getAllHottubEntries();
        $newOneOffEntries = array_values(array_filter($allEntries, function ($entry) use ($jobId) {
            return !str_contains($entry, $jobId) && str_contains($entry, ':ONCE');
        }));

        $this->assertEmpty($newOneOffEntries,
            "No precision cron when already at target.\n" .
            "Entries found: " . implode("\n", $newOneOffEntries));

        // No heat-target-check chain either
        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($heatTargetEntries,
            "No heat-target-check crons when already at target.\n" .
            "Entries found: " . implode("\n", $heatTargetEntries));

        $this->step('  ✓ No heating occurred (already at target)');

        // Cleanup
        $this->api->delete("/api/schedule/$jobId");
        $this->createdJobIds = array_filter($this->createdJobIds, fn($id) => $id !== $jobId);
    }

    /**
     * @test
     * DTDT fallback without temperature: wake-up fires but no ESP32 data.
     *
     * When no temperature data is available, DtdtService calls startImmediately(),
     * which activates TargetTemperatureService. However, TargetTemperatureService's
     * first checkAndAdjust() can't heat without temperature data — it returns early
     * without scheduling a cron or turning on the heater.
     *
     * This is a known system limitation: the heat-target state is active but no
     * cron chain is scheduled, leaving it in a "waiting for temp data" state.
     *
     * This test verifies:
     * 1. Wake-up → startImmediately (no precision cron)
     * 2. heat-target state is active (target-temperature.json exists)
     * 3. No IFTTT events (can't heat without temp data)
     * 4. Manually triggering heat-target-check with temp data completes the cycle
     */
    public function e2e_dtdtFallbackWithoutTemperature(): void
    {
        // =====================================================================
        // Setup: NO temperature data, NO sensor config
        // =====================================================================
        $this->step('Setup: Seed heating characteristics, ready_by mode (NO temperature data)');

        $this->seedHeatingCharacteristics(0.5, 5.0, 0.001);
        // Intentionally NOT calling seedSensorConfig() or esp32->reportTemperature()
        $this->setReadyByMode();

        $this->step('  ✓ Environment seeded (no ESP32 data)');

        // =====================================================================
        // Create schedule and fire wake-up
        // =====================================================================
        $readyByTime = $this->buildScheduledTime(180);

        $this->step("Create schedule and fire wake-up (readyByTime=$readyByTime)");

        $response = $this->api->post('/api/schedule', [
            'action' => 'heat-to-target',
            'scheduledTime' => $readyByTime,
            'target_temp_f' => 103,
            'recurring' => true,
        ]);

        $this->assertEquals(201, $response['status']);
        $jobId = $response['body']['jobId'];
        $this->createdJobIds[] = $jobId;

        $wakeUpResult = $this->cron->fireByJobId($jobId);
        $this->assertEquals(0, $wakeUpResult['exitCode'],
            "Wake-up cron failed: " . $wakeUpResult['stderr']);

        $this->step('  ✓ Wake-up fired');

        // =====================================================================
        // Verify: no precision cron (fallback = immediate start attempt)
        // =====================================================================
        $this->step('Verify: no precision cron created, state active but stalled');

        $allEntries = $this->cron->getAllHottubEntries();
        $precisionEntries = array_values(array_filter($allEntries, function ($entry) use ($jobId) {
            return !str_contains($entry, $jobId) && str_contains($entry, ':ONCE')
                && !str_contains($entry, 'HEAT-TARGET:ONCE');
        }));

        $this->assertEmpty($precisionEntries,
            "No precision cron should exist (fallback mode).\n" .
            "Entries: " . implode("\n", $precisionEntries));

        // No IFTTT events — TargetTemperatureService can't heat without temp data
        $this->ifttt->assertNoEvents(
            'No IFTTT events — checkAndAdjust() has no temp data, returns early');

        // State should be active (TargetTemperatureService::start() saved state)
        $stateFile = $this->storageDir . '/state/target-temperature.json';
        $this->assertFileExists($stateFile, 'Heat-target state should be active');
        $state = json_decode(file_get_contents($stateFile), true);
        $this->assertTrue($state['active'] ?? false,
            "Heat-target state should be active.\nState: " . json_encode($state));

        $this->step('  ✓ Fallback: no precision cron, state active, waiting for temp data');

        // =====================================================================
        // Simulate recovery: provide temp data and call heat-target-check directly
        // =====================================================================
        $this->step('Recovery: seed sensor, report 85°F, call heat-target-check via API');

        $this->seedSensorConfig();
        $this->esp32->reportTemperature(85.0);

        // Call heat-target-check directly via API (simulating manual recovery or
        // external trigger, since no cron chain was scheduled)
        $checkResponse = $this->api->post('/api/maintenance/heat-target-check');
        $this->assertEquals(200, $checkResponse['status'],
            "heat-target-check failed: " . json_encode($checkResponse['body']));

        // NOW the heater should turn on (85°F < 103°F target)
        $this->ifttt->assertEventOccurred('hot-tub-heat-on',
            'Heater should turn on once temperature data is available');

        // A heat-target-check cron should now be scheduled
        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($heatTargetEntries,
            'Heat-target-check cron chain should be active now');

        $this->step('  ✓ IFTTT: hot-tub-heat-on (temp data now available)');

        // =====================================================================
        // Complete heating cycle
        // =====================================================================
        $this->step('Complete cycle: report 103.5°F, fire check cron');

        $this->esp32->reportTemperature(103.5);
        $checkResult = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $checkResult['exitCode']);

        // Verify IFTTT ground truth
        $this->ifttt->assertEventsInOrder(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            'Complete IFTTT chain for fallback DTDT cycle'
        );

        $this->step('  ✓ IFTTT ground truth: [hot-tub-heat-on, hot-tub-heat-off]');

        // Cleanup
        $this->api->delete("/api/schedule/$jobId");
        $this->createdJobIds = array_filter($this->createdJobIds, fn($id) => $id !== $jobId);
    }
}
