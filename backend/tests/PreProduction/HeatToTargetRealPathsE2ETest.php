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
}
