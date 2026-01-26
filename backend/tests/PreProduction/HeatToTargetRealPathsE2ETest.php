<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction;

use HotTub\Tests\PreProduction\Helpers\ApiClient;
use HotTub\Tests\PreProduction\Helpers\CronSimulator;
use HotTub\Tests\PreProduction\Helpers\Esp32Simulator;
use HotTub\Tests\PreProduction\Helpers\IftttVerifier;
use PHPUnit\Framework\TestCase;

/**
 * TRUE End-to-End tests for heat-to-target using ACTUAL code paths.
 *
 * These tests interact ONLY via production interfaces:
 * - HTTP API (same as frontend)
 * - ESP32 temperature API (same as real sensor hardware)
 * - Real crontab (actual entries, actual commands)
 * - IFTTT stub log (ground truth for hardware triggers)
 *
 * What we simulate (timing only):
 * - Execute cron commands immediately (don't wait for daemon)
 * - ESP32 reports at accelerated rate
 *
 * What MUST be real code paths:
 * - All PHP code execution
 * - Job file creation and parsing by cron-runner.sh
 * - URL construction in cron-runner.sh
 * - JWT authentication flow
 * - API routing and dispatch
 *
 * @group pre-production
 * @group real-paths
 */
class HeatToTargetRealPathsE2ETest extends TestCase
{
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 8090;
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

    protected function setUp(): void
    {
        $this->backendDir = dirname(__DIR__, 2);
        $this->storageDir = $this->backendDir . '/storage';
        $this->envFile = $this->backendDir . '/.env';
        $this->envBackup = $this->backendDir . '/.env.e2e-backup-' . uniqid();

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
        // Stop server
        $this->stopServer();

        // Clean up crontab FIRST (critical)
        $this->cron->removeAllHeatTargetEntries();

        // Restore original .env
        if (file_exists($this->envBackup)) {
            rename($this->envBackup, $this->envFile);
        } elseif (file_exists($this->envFile)) {
            unlink($this->envFile);
        }

        // Clean up state
        $this->cleanupState();
    }

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

        $env = <<<ENV
APP_ENV=testing
EXTERNAL_API_MODE=stub
JWT_SECRET=$secret
JWT_EXPIRY_HOURS=1
CRON_JWT={$this->cronJwt}
ESP32_API_KEY={$this->esp32ApiKey}
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=password
API_BASE_URL=http://127.0.0.1:8090
ENV;

        file_put_contents($this->envFile, $env);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function cleanupState(): void
    {
        // Clean job files
        $jobsDir = $this->storageDir . '/scheduled-jobs';
        if (is_dir($jobsDir)) {
            foreach (glob("$jobsDir/heat-target-*.json") as $file) {
                @unlink($file);
            }
        }

        // Clean state files
        $stateFiles = [
            $this->storageDir . '/state/target-temperature.json',
            $this->storageDir . '/state/equipment-status.json',
            $this->storageDir . '/state/esp32-temperature.json',
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

        $this->fail("Failed to start server on $host:$port");
    }

    private function stopServer(): void
    {
        if ($this->serverPid) {
            shell_exec("kill {$this->serverPid} 2>/dev/null");
            $this->serverPid = null;
        }
    }

    private function step(string $msg): void
    {
        fwrite(STDERR, "\n    → $msg\n");
    }

    // =========================================================================
    // TRUE E2E TESTS - Actual Code Paths
    // =========================================================================

    /**
     * @test
     * Verify server is running and health endpoint responds.
     */
    public function e2e_serverHealth(): void
    {
        $response = $this->api->get('/api/health');
        $this->assertEquals(200, $response['status']);
    }

    /**
     * @test
     * Verify ESP32 API accepts temperature reports.
     */
    public function e2e_esp32TemperatureReport(): void
    {
        $response = $this->esp32->reportTemperature(85.0);
        $this->assertEquals(200, $response['status'],
            "ESP32 API should accept temperature. Got: " . json_encode($response));
    }

    /**
     * @test
     * FULL HEATING CYCLE using actual code paths.
     *
     * This is the definitive test. It exercises:
     * 1. Real HTTP API calls
     * 2. Real ESP32 temperature API
     * 3. Real crontab entries
     * 4. Real cron-runner.sh execution
     * 5. Real job file creation and parsing
     * 6. IFTTT stub as ground truth
     */
    public function e2e_fullHeatingCycleRealCodePaths(): void
    {
        // =====================================================================
        // STEP 1: ESP32 reports initial temperature via REAL API
        // =====================================================================
        $this->step("STEP 1: ESP32 reports 82°F via real API");

        $response = $this->esp32->reportTemperature(82.0);
        $this->assertEquals(200, $response['status'], "ESP32 report failed");

        // =====================================================================
        // STEP 2: Start heat-to-target via REAL API
        // =====================================================================
        $this->step("STEP 2: Call POST /api/equipment/heat-to-target (target=101°F)");

        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);

        $this->assertEquals(200, $response['status'],
            "heat-to-target should succeed. Got: " . json_encode($response));

        // =====================================================================
        // STEP 3: Verify IFTTT ground truth - heater ON
        // =====================================================================
        $this->step("STEP 3: Verify IFTTT received 'hot-tub-heat-on'");

        $this->ifttt->assertEventOccurred('hot-tub-heat-on',
            "Heater should have been turned ON");

        // =====================================================================
        // STEP 4: Verify REAL cron entry exists
        // =====================================================================
        $this->step("STEP 4: Verify cron scheduled in REAL crontab");

        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries,
            "No cron entry found! Crontab:\n" . shell_exec('crontab -l 2>/dev/null'));

        // =====================================================================
        // STEP 5: Verify cron scheduled for VALID future time
        // =====================================================================
        $this->step("STEP 5: Verify cron scheduled for valid future minute");

        $this->cron->assertValidScheduleTime($entries[0],
            "First cron must be scheduled correctly");

        $this->step("  ✓ Cron entry: " . $entries[0]);

        // =====================================================================
        // STEP 6: Execute ACTUAL cron command (not reconstructed!)
        // =====================================================================
        $this->step("STEP 6: Fire cron - execute ACTUAL command from crontab");

        $result = $this->cron->fireNextHeatTargetCron();

        $this->assertEquals(0, $result['exitCode'],
            "Cron command failed!\n" .
            "Command from crontab: {$result['cronEntry']}\n" .
            "Exit code: {$result['exitCode']}\n" .
            "Stdout: {$result['stdout']}\n" .
            "Stderr: {$result['stderr']}");

        $this->step("  ✓ Cron executed successfully");

        // =====================================================================
        // STEP 7: Verify chain continues - new cron scheduled
        // =====================================================================
        $this->step("STEP 7: Verify NEW cron scheduled (chain continues)");

        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries,
            "Cron chain broken! No new cron after check.");

        $this->cron->assertValidScheduleTime($entries[0]);

        // =====================================================================
        // STEP 8: ESP32 reports heating progress via REAL API
        // =====================================================================
        $this->step("STEP 8: ESP32 reports 95°F via real API");

        $response = $this->esp32->reportTemperature(95.0);
        $this->assertEquals(200, $response['status']);

        // =====================================================================
        // STEP 9: Fire next cron
        // =====================================================================
        $this->step("STEP 9: Fire next cron");

        $result = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $result['exitCode'], "Second cron failed");

        // =====================================================================
        // STEP 10: Another cron should be scheduled
        // =====================================================================
        $this->step("STEP 10: Verify another cron scheduled");

        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries, "Still need cron - not at target yet");

        // =====================================================================
        // STEP 11: ESP32 reports TARGET REACHED via REAL API
        // =====================================================================
        $this->step("STEP 11: ESP32 reports 101.5°F (TARGET REACHED) via real API");

        $response = $this->esp32->reportTemperature(101.5);
        $this->assertEquals(200, $response['status']);

        // =====================================================================
        // STEP 12: Fire final cron
        // =====================================================================
        $this->step("STEP 12: Fire final cron");

        $result = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $result['exitCode'], "Final cron failed");

        // =====================================================================
        // STEP 13: Verify NO more crons (cycle complete)
        // =====================================================================
        $this->step("STEP 13: Verify NO cron scheduled (cycle complete)");

        $entries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($entries,
            "Crons should be cleaned up after target reached. Found:\n" .
            implode("\n", $entries));

        // =====================================================================
        // STEP 14: GROUND TRUTH - Verify complete IFTTT signal chain
        // =====================================================================
        $this->step("STEP 14: Verify IFTTT ground truth: heat-on → heat-off");

        $this->ifttt->assertEventsInOrder(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            "IFTTT events must show heater turned ON then OFF"
        );

        $this->step("\n  ══════════════════════════════════════════════════════");
        $this->step("  ✓ FULL CYCLE COMPLETE - ALL REAL CODE PATHS VERIFIED!");
        $this->step("  ══════════════════════════════════════════════════════\n");
    }

    /**
     * @test
     * Target already reached when starting - no heating should occur.
     */
    public function e2e_targetAlreadyReached(): void
    {
        $this->step("ESP32 reports 102°F (already at target)");
        $this->esp32->reportTemperature(102.0);

        $this->step("Start heat-to-target with target=101°F");
        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['target_reached'] ?? false,
            "Should immediately detect target reached");

        // No IFTTT heat-on should occur
        $this->ifttt->assertEventNotOccurred('hot-tub-heat-on',
            "Heater should NOT turn on when already at target");

        // No cron should be scheduled
        $entries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($entries, "No cron needed when target already reached");
    }

    /**
     * @test
     * Cancel heating mid-cycle.
     */
    public function e2e_cancelMidCycle(): void
    {
        // Start heating
        $this->esp32->reportTemperature(82.0);
        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);
        $this->assertEquals(200, $response['status']);

        // Verify cron exists
        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries);

        // Cancel
        $this->step("Cancel heating mid-cycle");
        $response = $this->api->delete('/api/equipment/heat-to-target');
        $this->assertEquals(200, $response['status']);

        // Verify cron cleaned up
        $entries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($entries, "Cron should be removed after cancel");

        // Verify state cleared (via status endpoint)
        $response = $this->api->get('/api/equipment/heat-to-target');
        $this->assertFalse($response['body']['active'] ?? true,
            "Target heating should be inactive after cancel");
    }

    /**
     * @test
     * BUG REPRODUCTION: Old ESP32 reading should NOT cause past-minute scheduling.
     *
     * This test verifies the race condition fix by simulating a stale ESP32 reading.
     */
    public function e2e_oldEsp32ReadingDoesNotCausePastScheduling(): void
    {
        $this->step("Simulate ESP32 reading from 90 seconds ago");

        // Write temperature data with old timestamp (bypasses API for this edge case)
        $this->esp32->reportTemperatureWithAge(
            82.0,
            90, // 90 seconds old
            $this->storageDir . '/state/esp32-temperature.json'
        );

        $this->step("Start heat-to-target");
        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);
        $this->assertEquals(200, $response['status']);

        $this->step("Verify cron is scheduled for valid FUTURE minute");
        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries);

        // This assertion will fail if the race condition bug exists
        $this->cron->assertValidScheduleTime($entries[0],
            "With 90s old ESP32 reading, cron must still be in future minute");

        // Clean up
        $this->api->delete('/api/equipment/heat-to-target');
    }

    // =========================================================================
    // BUG REPRODUCTION TESTS - Production failure Jan 25, 2026
    // =========================================================================

    /**
     * @test
     * BUG REPRODUCTION: Scheduled job triggering heat-to-target.
     *
     * This test reproduces the exact production failure scenario:
     * 1. User schedules a heat-to-target job via the scheduler
     * 2. Cron fires the scheduled job
     * 3. The scheduled job calls POST /api/equipment/heat-to-target
     * 4. heat-to-target needs to schedule a follow-up cron
     *
     * In production on Jan 25, 2026:
     * - The initial scheduled job (job-1b29bc64) fired correctly
     * - heat-to-target turned on the heater (IFTTT event logged)
     * - BUT no heat-target follow-up cron was added to crontab
     * - Result: heater ran indefinitely, overshooting 102°F target
     *
     * Expected: This test should FAIL if the bug still exists.
     */
    public function e2e_scheduledJobTriggersHeatToTarget(): void
    {
        $this->step("STEP 1: Report initial temperature (82°F - needs heating)");
        $response = $this->esp32->reportTemperature(82.0);
        $this->assertEquals(200, $response['status']);

        $this->step("STEP 2: Schedule a heat-to-target job via scheduler API");

        // Schedule for 1 minute from now (same as production workflow)
        $scheduledTime = (new \DateTime('+1 minute'))->format(\DateTime::ATOM);

        $response = $this->api->post('/api/schedule', [
            'action' => 'heat-to-target',
            'scheduledTime' => $scheduledTime,
            'target_temp_f' => 101.0,
        ]);

        $this->assertContains($response['status'], [200, 201],
            "Schedule creation failed: " . json_encode($response));

        $jobId = $response['body']['jobId'] ?? null;
        $this->assertNotNull($jobId, "No jobId returned from schedule API");
        $this->step("  ✓ Created scheduled job: $jobId");

        $this->step("STEP 3: Verify scheduled job cron entry exists");

        // Look for the scheduled job's cron entry (uses HOTTUB:job-xxx pattern)
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        $this->assertStringContainsString("HOTTUB:$jobId",
            $crontab,
            "Scheduled job cron entry not found in crontab");

        $this->step("STEP 4: Fire the scheduled job (simulates cron daemon)");

        // Extract and execute the cron command for this job
        $result = $this->fireScheduledJobCron($jobId);

        $this->assertEquals(0, $result['exitCode'],
            "Scheduled job cron failed!\n" .
            "Command: {$result['command']}\n" .
            "Exit code: {$result['exitCode']}\n" .
            "Stdout: {$result['stdout']}\n" .
            "Stderr: {$result['stderr']}");

        $this->step("  ✓ Scheduled job executed successfully");

        $this->step("STEP 5: Verify IFTTT heater turned ON");
        $this->ifttt->assertEventOccurred('hot-tub-heat-on',
            "Heater should have been turned ON by heat-to-target");

        $this->step("STEP 6: Verify heat-target follow-up cron was scheduled");

        // THIS IS THE CRITICAL CHECK - this is what failed in production
        $heatTargetEntries = $this->cron->getHeatTargetEntries();

        $this->assertNotEmpty($heatTargetEntries,
            "BUG REPRODUCED: No heat-target follow-up cron was scheduled!\n" .
            "This is the exact production failure from Jan 25, 2026.\n" .
            "The scheduled job fired, the heater turned on, but no follow-up\n" .
            "cron was created to check temperature and turn off the heater.\n\n" .
            "Full crontab:\n" . (shell_exec('crontab -l 2>/dev/null') ?? '(empty)'));

        $this->step("  ✓ Heat-target follow-up cron found");

        // Verify the follow-up cron is scheduled for a valid future time
        $this->cron->assertValidScheduleTime($heatTargetEntries[0],
            "Heat-target follow-up cron must be in future minute");

        $this->step("STEP 7: Verify cron chain works (fire follow-up, should schedule another)");

        // Fire the heat-target cron
        $result = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $result['exitCode'], "Heat-target cron failed");

        // Should schedule another follow-up since we're still below target
        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($heatTargetEntries,
            "Cron chain broken - no new heat-target cron after check");

        $this->step("STEP 8: Complete the cycle - reach target temperature");

        // Report target temperature reached
        $response = $this->esp32->reportTemperature(101.5);
        $this->assertEquals(200, $response['status']);

        // Fire final cron
        $result = $this->cron->fireNextHeatTargetCron();
        $this->assertEquals(0, $result['exitCode']);

        // Verify cleanup
        $heatTargetEntries = $this->cron->getHeatTargetEntries();
        $this->assertEmpty($heatTargetEntries, "Crons should be cleaned up after target reached");

        $this->ifttt->assertEventsInOrder(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            "IFTTT should show heater ON then OFF"
        );

        $this->step("\n  ══════════════════════════════════════════════════════");
        $this->step("  ✓ SCHEDULED JOB → HEAT-TO-TARGET → FOLLOW-UP VERIFIED!");
        $this->step("  ══════════════════════════════════════════════════════\n");
    }

    /**
     * Fire a specific scheduled job's cron command.
     *
     * @param string $jobId The job ID to fire
     * @return array{exitCode: int, stdout: string, stderr: string, command: string}
     */
    private function fireScheduledJobCron(string $jobId): array
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        $lines = explode("\n", $crontab);

        foreach ($lines as $line) {
            if (str_contains($line, "HOTTUB:$jobId")) {
                // Extract command from cron entry
                $withoutComment = preg_replace('/#.*$/', '', $line);
                $parts = preg_split('/\s+/', trim($withoutComment), 6);

                if (count($parts) >= 6) {
                    $command = trim($parts[5]);

                    // Execute the command
                    $descriptors = [
                        0 => ['pipe', 'r'],
                        1 => ['pipe', 'w'],
                        2 => ['pipe', 'w'],
                    ];

                    $process = proc_open($command, $descriptors, $pipes, null, null);

                    if (!is_resource($process)) {
                        return [
                            'exitCode' => -1,
                            'stdout' => '',
                            'stderr' => 'Failed to start process',
                            'command' => $command,
                        ];
                    }

                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    $stderr = stream_get_contents($pipes[2]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    $exitCode = proc_close($process);

                    return [
                        'exitCode' => $exitCode,
                        'stdout' => $stdout,
                        'stderr' => $stderr,
                        'command' => $command,
                    ];
                }
            }
        }

        throw new \RuntimeException("No cron entry found for job: $jobId");
    }

    /**
     * @test
     * BUG ANALYSIS: Compare timezone handling between SchedulerService and TargetTemperatureService.
     *
     * This test documents the DRY violation:
     * - SchedulerService uses TimeConverter::getSystemTimezone() (correct)
     * - TargetTemperatureService uses date_default_timezone_get() (potentially wrong)
     *
     * On hosts where PHP timezone differs from system timezone, this causes
     * heat-target crons to be scheduled at wrong times.
     */
    public function e2e_timezoneConsistencyCheck(): void
    {
        $this->step("Checking timezone configuration...");

        // Get PHP timezone
        $phpTimezone = date_default_timezone_get();
        $this->step("  PHP timezone (date_default_timezone_get): $phpTimezone");

        // Get system timezone (what cron uses)
        $systemTimezone = $this->getSystemTimezone();
        $this->step("  System timezone (what cron uses): $systemTimezone");

        // If they differ, this test documents the mismatch
        if ($phpTimezone !== $systemTimezone) {
            $this->step("\n  ⚠️  WARNING: TIMEZONE MISMATCH DETECTED!");
            $this->step("  This means TargetTemperatureService will schedule crons");
            $this->step("  at wrong times because it uses date_default_timezone_get()");
            $this->step("  instead of TimeConverter::getSystemTimezone().");

            // Calculate the offset difference
            $phpTz = new \DateTimeZone($phpTimezone);
            $sysTz = new \DateTimeZone($systemTimezone);
            $now = new \DateTime();
            $phpOffset = $phpTz->getOffset($now) / 3600;
            $sysOffset = $sysTz->getOffset($now) / 3600;
            $diff = abs($phpOffset - $sysOffset);

            $this->step("  Offset difference: {$diff} hours");
            $this->step("  Crons will fire {$diff} hours late/early!\n");
        } else {
            $this->step("  ✓ Timezones match - no mismatch on this system");
        }

        // Verify cron scheduling uses correct times by testing the full flow
        $this->step("\nVerifying cron scheduling precision...");

        $response = $this->esp32->reportTemperature(82.0);
        $this->assertEquals(200, $response['status']);

        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);
        $this->assertEquals(200, $response['status']);

        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries);

        // Parse the scheduled time from cron
        $scheduledTime = $this->cron->parseScheduledTime($entries[0]);
        $scheduledDateTime = new \DateTime('@' . $scheduledTime);
        $scheduledDateTime->setTimezone(new \DateTimeZone($systemTimezone));

        $this->step("  Cron scheduled for: " . $scheduledDateTime->format('Y-m-d H:i:s T'));
        $this->step("  Current time:       " . date('Y-m-d H:i:s T'));

        $secondsUntilFire = $scheduledTime - time();
        $this->step("  Seconds until fire: $secondsUntilFire");

        $this->assertGreaterThan(0, $secondsUntilFire,
            "Cron should be scheduled for the future");

        // Clean up
        $this->api->delete('/api/equipment/heat-to-target');

        $this->step("  ✓ Cron scheduling verified");
    }

    /**
     * Get the system timezone (where cron runs).
     * This mirrors TimeConverter::getSystemTimezone() logic.
     */
    private function getSystemTimezone(): string
    {
        // Method 1: /etc/timezone (Debian/Ubuntu)
        if (is_readable('/etc/timezone')) {
            $tz = trim(file_get_contents('/etc/timezone'));
            if ($tz && $this->isValidTimezone($tz)) {
                return $tz;
            }
        }

        // Method 2: /etc/localtime symlink (RHEL/CentOS/macOS)
        if (is_link('/etc/localtime')) {
            $link = readlink('/etc/localtime');
            if (preg_match('#zoneinfo/(.+)$#', $link, $matches)) {
                $tz = $matches[1];
                if ($this->isValidTimezone($tz)) {
                    return $tz;
                }
            }
        }

        // Method 3: TZ environment variable
        $envTz = getenv('TZ');
        if ($envTz && $this->isValidTimezone($envTz)) {
            return $envTz;
        }

        // Fallback: PHP's timezone
        return date_default_timezone_get();
    }

    private function isValidTimezone(string $tz): bool
    {
        try {
            new \DateTimeZone($tz);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @test
     * BUG REPRODUCTION: Heat-target cron scheduled with wrong timezone.
     *
     * This test reproduces the EXACT production bug:
     * - TargetTemperatureService uses date_default_timezone_get() for cron scheduling
     * - SchedulerService uses TimeConverter::getSystemTimezone()
     * - When PHP timezone != system timezone, heat-target crons fire at wrong times
     *
     * Production failure (Jan 25, 2026):
     * - PHP timezone was likely UTC
     * - System timezone was likely America/New_York (EST, UTC-5)
     * - Heat-target cron was scheduled for 14:30 UTC
     * - But cron interpreted 14:30 as EST, so it would fire at 14:30 EST = 19:30 UTC
     * - Result: heater ran 5+ hours longer than intended
     *
     * This test SHOULD FAIL until the bug is fixed.
     */
    public function e2e_heatTargetCronUsesCorrectTimezone(): void
    {
        $phpTimezone = date_default_timezone_get();
        $systemTimezone = $this->getSystemTimezone();

        $this->step("PHP timezone: $phpTimezone");
        $this->step("System timezone: $systemTimezone");

        // Report temperature below target
        $response = $this->esp32->reportTemperature(82.0);
        $this->assertEquals(200, $response['status']);

        // Start heat-to-target
        $response = $this->api->post('/api/equipment/heat-to-target', [
            'target_temp_f' => 101.0,
        ]);
        $this->assertEquals(200, $response['status']);

        // Get the heat-target cron entry
        $entries = $this->cron->getHeatTargetEntries();
        $this->assertNotEmpty($entries, "Heat-target cron should be scheduled");

        $cronEntry = $entries[0];
        $this->step("Cron entry: $cronEntry");

        // Parse the scheduled time from cron
        $scheduledTimestamp = $this->cron->parseScheduledTime($cronEntry);

        // Calculate what time the cron SHOULD fire (based on current time + expected delay)
        // The expected delay is roughly: ESP32 interval + buffer + rounding to minute boundary
        $now = time();
        $expectedMinimum = $now + 5; // At least 5 seconds safety margin
        $expectedMaximum = $now + 120; // At most ~2 minutes (interval + buffer + rounding)

        $this->step("Current time (UTC): " . gmdate('Y-m-d H:i:s', $now));
        $this->step("Cron scheduled for (Unix): $scheduledTimestamp");

        // The cron entry contains minute/hour/day/month which CronSimulator parses
        // using date_default_timezone_get(). But cron daemon interprets those values
        // in the SYSTEM timezone.
        //
        // If the two timezones differ, the parsed timestamp will be wrong.

        // Calculate what time cron will ACTUALLY fire (interpreting cron's hour/minute in system tz)
        preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+\*/', $cronEntry, $matches);
        $cronMinute = (int) $matches[1];
        $cronHour = (int) $matches[2];
        $cronDay = (int) $matches[3];
        $cronMonth = (int) $matches[4];

        $this->step("Cron time fields: minute=$cronMinute, hour=$cronHour, day=$cronDay, month=$cronMonth");

        // When cron daemon fires this job (interpreting time in system timezone)
        $cronFire = new \DateTime();
        $cronFire->setTimezone(new \DateTimeZone($systemTimezone));
        $cronFire->setDate((int)date('Y'), $cronMonth, $cronDay);
        $cronFire->setTime($cronHour, $cronMinute, 0);
        $actualFireTimestamp = $cronFire->getTimestamp();

        $this->step("Actual cron fire time (UTC): " . gmdate('Y-m-d H:i:s', $actualFireTimestamp));

        // The bug: if PHP tz != system tz, actualFireTimestamp will be hours off
        $diffSeconds = abs($actualFireTimestamp - $scheduledTimestamp);
        $diffHours = $diffSeconds / 3600;

        if ($diffSeconds > 300) { // More than 5 minutes difference indicates timezone bug
            $this->step("\n  BUG DETECTED: Cron time mismatch!");
            $this->step("  CronSimulator parsed time: " . gmdate('Y-m-d H:i:s', $scheduledTimestamp) . " UTC");
            $this->step("  Actual cron fire time:     " . gmdate('Y-m-d H:i:s', $actualFireTimestamp) . " UTC");
            $this->step("  Difference: " . round($diffHours, 2) . " hours");
            $this->step("\n  This is the production bug from Jan 25, 2026!");
            $this->step("  TargetTemperatureService uses date_default_timezone_get() (=$phpTimezone)");
            $this->step("  But cron runs in system timezone ($systemTimezone)");
        }

        // This assertion SHOULD FAIL until the bug is fixed
        $this->assertLessThan(
            300, // 5 minutes tolerance
            $diffSeconds,
            "BUG: Heat-target cron scheduled at wrong time due to timezone mismatch!\n" .
            "PHP timezone: $phpTimezone\n" .
            "System timezone: $systemTimezone\n" .
            "CronSimulator parsed: " . gmdate('Y-m-d H:i:s', $scheduledTimestamp) . " UTC\n" .
            "Actual fire time:     " . gmdate('Y-m-d H:i:s', $actualFireTimestamp) . " UTC\n" .
            "Difference: " . round($diffHours, 2) . " hours\n\n" .
            "ROOT CAUSE: TargetTemperatureService::scheduleNextCheck() uses\n" .
            "date_default_timezone_get() instead of TimeConverter::getSystemTimezone().\n" .
            "This is a DRY violation - SchedulerService does this correctly."
        );

        // Clean up
        $this->api->delete('/api/equipment/heat-to-target');
    }
}
