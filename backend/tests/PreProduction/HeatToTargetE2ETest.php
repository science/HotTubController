<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end pre-production tests for the heat-to-target feature.
 *
 * These tests run the REAL FULL CHAIN:
 * 1. Start a real PHP dev server
 * 2. Call API to start heat-to-target (like frontend would)
 * 3. Verify cron entry is added to REAL crontab
 * 4. Execute cron-runner.sh (simulating cron daemon firing)
 * 5. Verify API is called and responds correctly
 * 6. Verify next cron is scheduled
 * 7. Update temperature data (simulating ESP32 reports)
 * 8. Execute next cron
 * 9. Repeat until target reached
 * 10. Verify heater turns off and cycle ends
 *
 * The ONLY things simulated:
 * - Temperature data (instead of real ESP32 sensor)
 * - Triggering cron-runner.sh manually (instead of waiting for cron daemon)
 *
 * Everything else is REAL:
 * - Real PHP server handling real HTTP requests
 * - Real crontab entries
 * - Real cron-runner.sh execution
 * - Real job files
 * - Real state files
 * - Real JWT authentication flow
 *
 * @group pre-production
 */
class HeatToTargetE2ETest extends TestCase
{
    private const SERVER_HOST = '127.0.0.1';
    private const SERVER_PORT = 8089;
    private const SERVER_START_TIMEOUT = 5;

    private string $backendDir;
    private string $storageDir;
    private string $envFile;
    private string $envBackup;
    private ?int $serverPid = null;

    protected function setUp(): void
    {
        $this->backendDir = dirname(__DIR__, 2);
        $this->storageDir = $this->backendDir . '/storage';
        $this->envFile = $this->backendDir . '/.env';
        $this->envBackup = $this->backendDir . '/.env.e2e-backup';

        // Backup existing .env if present
        if (file_exists($this->envFile)) {
            copy($this->envFile, $this->envBackup);
        }

        // Create test .env with stub mode and test JWT
        $this->createTestEnv();

        // Clean up any leftover state from previous runs
        $this->cleanupState();

        // Start the PHP dev server
        $this->startServer();
    }

    protected function tearDown(): void
    {
        // Stop the server
        $this->stopServer();

        // Clean up crontab entries FIRST (most important)
        $this->cleanupCrontab();

        // Restore original .env
        if (file_exists($this->envBackup)) {
            rename($this->envBackup, $this->envFile);
        } elseif (file_exists($this->envFile)) {
            unlink($this->envFile);
        }

        // Clean up test state
        $this->cleanupState();
    }

    private function createTestEnv(): void
    {
        // Create a long-lived JWT for testing (expires in 1 hour)
        $secret = 'e2e-test-secret-key-for-jwt-signing';
        $header = $this->base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload = $this->base64UrlEncode(json_encode([
            'iat' => time(),
            'exp' => time() + 3600,
            'sub' => 'e2e-test',
            'role' => 'admin',
        ]));
        $signature = $this->base64UrlEncode(hash_hmac('sha256', "$header.$payload", $secret, true));
        $jwt = "$header.$payload.$signature";

        $env = <<<ENV
APP_ENV=testing
EXTERNAL_API_MODE=stub
JWT_SECRET=$secret
JWT_EXPIRY_HOURS=1
CRON_JWT=$jwt
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=password
API_BASE_URL=http://127.0.0.1:8089
ENV;

        file_put_contents($this->envFile, $env);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function cleanupState(): void
    {
        // Clean up scheduled jobs
        $jobsDir = $this->storageDir . '/scheduled-jobs';
        if (is_dir($jobsDir)) {
            foreach (glob("$jobsDir/heat-target-*.json") as $file) {
                @unlink($file);
            }
        }

        // Clean up state files
        $stateDir = $this->storageDir . '/state';
        foreach (['target-temperature.json', 'equipment-status.json', 'esp32-temperature.json'] as $file) {
            $path = "$stateDir/$file";
            if (file_exists($path)) {
                @unlink($path);
            }
        }

        // Clean up events log (IFTTT triggers are logged here)
        $eventsLog = $this->backendDir . '/logs/events.log';
        if (file_exists($eventsLog)) {
            @unlink($eventsLog);
        }
    }

    private function cleanupCrontab(): void
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        if (empty(trim($crontab))) {
            return;
        }

        $lines = explode("\n", $crontab);
        $filtered = array_filter($lines, fn($line) => !str_contains($line, 'HOTTUB:heat-target'));

        if (count($filtered) !== count($lines)) {
            $newCrontab = implode("\n", $filtered);
            $tempFile = tempnam(sys_get_temp_dir(), 'crontab');
            file_put_contents($tempFile, $newCrontab . "\n");
            shell_exec("crontab $tempFile 2>/dev/null");
            unlink($tempFile);
        }
    }

    private function startServer(): void
    {
        $host = self::SERVER_HOST;
        $port = self::SERVER_PORT;
        $docroot = $this->backendDir . '/public';
        $router = $docroot . '/router.php';

        // Start PHP built-in server with router script
        $cmd = sprintf(
            'php -S %s:%d -t %s %s > /dev/null 2>&1 & echo $!',
            $host,
            $port,
            escapeshellarg($docroot),
            escapeshellarg($router)
        );

        $this->serverPid = (int) trim(shell_exec($cmd));

        // Wait for server to be ready
        $startTime = time();
        while (time() - $startTime < self::SERVER_START_TIMEOUT) {
            $socket = @fsockopen($host, $port, $errno, $errstr, 1);
            if ($socket) {
                fclose($socket);
                return;
            }
            usleep(100000); // 100ms
        }

        $this->fail("Failed to start PHP dev server on $host:$port");
    }

    private function stopServer(): void
    {
        if ($this->serverPid) {
            shell_exec("kill {$this->serverPid} 2>/dev/null");
            $this->serverPid = null;
        }
    }

    private function getApiBaseUrl(): string
    {
        return sprintf('http://%s:%d', self::SERVER_HOST, self::SERVER_PORT);
    }

    /**
     * Make an HTTP request to the test server.
     *
     * @return array{status: int, body: array}
     */
    private function httpRequest(string $method, string $endpoint, ?array $body = null, ?string $authToken = null): array
    {
        $url = $this->getApiBaseUrl() . $endpoint;

        $opts = [
            'http' => [
                'method' => $method,
                'header' => "Content-Type: application/json\r\n",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ];

        if ($authToken) {
            $opts['http']['header'] .= "Authorization: Bearer $authToken\r\n";
        }

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        // Parse status code from headers
        $status = 500;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
            $status = (int) ($matches[1] ?? 500);
        }

        return [
            'status' => $status,
            'body' => json_decode($response ?: '{}', true) ?? [],
        ];
    }

    private function getCronJwt(): string
    {
        $env = file_get_contents($this->envFile);
        preg_match('/CRON_JWT=(.+)/', $env, $matches);
        return trim($matches[1] ?? '');
    }

    /**
     * Store temperature data (simulating ESP32 report).
     */
    private function storeTemperature(float $tempF): void
    {
        $tempC = ($tempF - 32) * 5 / 9;
        $stateFile = $this->storageDir . '/state/esp32-temperature.json';
        $stateDir = dirname($stateFile);

        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $data = [
            'device_id' => 'E2E:TEST:DEVICE',
            'sensors' => [
                [
                    'address' => '28:E2:E2:TE:ST:00:00:01',
                    'temp_c' => $tempC,
                    'temp_f' => $tempF,
                ],
            ],
            'uptime_seconds' => 3600,
            'timestamp' => (new \DateTime())->format('c'),
            'received_at' => time(),
            'temp_c' => $tempC,
            'temp_f' => $tempF,
        ];

        file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    private function getEquipmentStatus(): array
    {
        $file = $this->storageDir . '/state/equipment-status.json';
        if (!file_exists($file)) {
            return ['heater' => ['on' => false], 'pump' => ['on' => false]];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }

    private function getTargetTempState(): array
    {
        $file = $this->storageDir . '/state/target-temperature.json';
        if (!file_exists($file)) {
            return ['active' => false];
        }
        return json_decode(file_get_contents($file), true) ?? [];
    }

    /**
     * Get all heat-target cron entries from crontab.
     */
    private function getHeatTargetCronEntries(): array
    {
        $crontab = shell_exec('crontab -l 2>/dev/null') ?? '';
        $lines = explode("\n", $crontab);
        return array_values(array_filter($lines, fn($line) => str_contains($line, 'HOTTUB:heat-target')));
    }

    /**
     * Extract job ID from a cron entry.
     */
    private function extractJobId(string $cronEntry): string
    {
        if (preg_match("/HOTTUB:(heat-target-[a-f0-9]+)/", $cronEntry, $matches)) {
            return $matches[1];
        }
        return '';
    }

    /**
     * Parse the scheduled time from a cron entry and return Unix timestamp.
     *
     * Cron format: "minute hour day month dow command # comment"
     * Heat-target format: "23 11 24 01 * /path/to/cron-runner.sh 'heat-target-xxx' # HOTTUB:..."
     *
     * @return int|null Unix timestamp, or null if parsing fails
     */
    private function parseCronScheduledTime(string $cronEntry): ?int
    {
        // Extract: minute hour day month
        if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+\*/', $cronEntry, $matches)) {
            return null;
        }

        $minute = (int) $matches[1];
        $hour = (int) $matches[2];
        $day = (int) $matches[3];
        $month = (int) $matches[4];
        $year = (int) date('Y');

        // Create DateTime in server's timezone (same as what scheduleNextCheck uses)
        $dt = new \DateTime();
        $dt->setDate($year, $month, $day);
        $dt->setTime($hour, $minute, 0);

        return $dt->getTimestamp();
    }

    /**
     * Verify the cron is scheduled within expected time window.
     *
     * Expected: 60-80 seconds from now (ESP32 interval 60s + 5s buffer + tolerance)
     */
    private function assertCronScheduledWithinWindow(string $cronEntry, int $minSecondsFromNow, int $maxSecondsFromNow, string $message = ''): void
    {
        $scheduledTime = $this->parseCronScheduledTime($cronEntry);
        $this->assertNotNull($scheduledTime, "Failed to parse cron entry: $cronEntry");

        $now = time();
        $secondsFromNow = $scheduledTime - $now;

        $this->assertGreaterThanOrEqual(
            $minSecondsFromNow,
            $secondsFromNow,
            ($message ? "$message\n" : '') .
            "Cron scheduled too soon! Scheduled for $secondsFromNow seconds from now.\n" .
            "Expected: at least $minSecondsFromNow seconds.\n" .
            "Cron entry: $cronEntry\n" .
            "Scheduled time: " . date('Y-m-d H:i:s', $scheduledTime) . "\n" .
            "Current time: " . date('Y-m-d H:i:s', $now)
        );

        $this->assertLessThanOrEqual(
            $maxSecondsFromNow,
            $secondsFromNow,
            ($message ? "$message\n" : '') .
            "Cron scheduled too far in future! Scheduled for $secondsFromNow seconds from now.\n" .
            "Expected: at most $maxSecondsFromNow seconds.\n" .
            "Cron entry: $cronEntry\n" .
            "Scheduled time: " . date('Y-m-d H:i:s', $scheduledTime) . "\n" .
            "Current time: " . date('Y-m-d H:i:s', $now)
        );

        // CRITICAL: Cron must be in a FUTURE minute, not the current minute!
        // Cron daemon fires at :00 of each minute. If we add a cron entry for the current
        // minute (e.g., at 11:22:03 for minute 22), the daemon already fired at 11:22:00
        // and won't fire again until 11:23:00. The cron for minute 22 will NEVER fire!
        $currentMinute = (int) date('i', $now);
        $scheduledMinute = (int) date('i', $scheduledTime);
        $currentHour = (int) date('H', $now);
        $scheduledHour = (int) date('H', $scheduledTime);

        // Same hour? Check minute is in the future
        if ($scheduledHour === $currentHour && $scheduledMinute <= $currentMinute) {
            $this->fail(
                ($message ? "$message\n" : '') .
                "RACE CONDITION BUG: Cron scheduled for current or past minute!\n" .
                "Cron daemon fires at :00 of each minute. Entry added after this point will never fire.\n" .
                "Current: " . date('H:i:s', $now) . " (minute $currentMinute)\n" .
                "Scheduled: " . date('H:i:s', $scheduledTime) . " (minute $scheduledMinute)\n" .
                "The cron daemon already fired for minute $scheduledMinute. This cron will NEVER run!\n" .
                "Cron entry: $cronEntry"
            );
        }
    }

    /**
     * Execute cron-runner.sh with a job ID (simulating cron daemon firing).
     *
     * @return array{returnCode: int, output: string, logOutput: string}
     */
    private function executeCronRunner(string $jobId): array
    {
        $cronRunner = $this->storageDir . '/bin/cron-runner.sh';
        $logFile = $this->storageDir . '/logs/cron.log';

        // Get log size before execution
        $logSizeBefore = file_exists($logFile) ? filesize($logFile) : 0;

        $output = [];
        $returnCode = 0;
        exec("bash " . escapeshellarg($cronRunner) . " " . escapeshellarg($jobId) . " 2>&1", $output, $returnCode);

        // Get new log entries
        $logOutput = '';
        if (file_exists($logFile) && filesize($logFile) > $logSizeBefore) {
            $fh = fopen($logFile, 'r');
            fseek($fh, $logSizeBefore);
            $logOutput = fread($fh, filesize($logFile) - $logSizeBefore);
            fclose($fh);
        }

        return [
            'returnCode' => $returnCode,
            'output' => implode("\n", $output),
            'logOutput' => $logOutput,
        ];
    }

    /**
     * Get IFTTT events from the events log.
     *
     * @return array List of event names that were triggered (e.g., 'hot-tub-heat-on')
     */
    private function getIftttEvents(): array
    {
        $eventsLog = $this->backendDir . '/logs/events.log';
        if (!file_exists($eventsLog)) {
            return [];
        }

        $events = [];
        $lines = file($eventsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (isset($entry['action']) && str_starts_with($entry['action'], 'ifttt_')) {
                $events[] = $entry['data']['event'] ?? 'unknown';
            }
        }
        return $events;
    }

    /**
     * Output a step description (visible in --testdox output).
     */
    private function step(string $description): void
    {
        // This will show up in verbose test output
        fwrite(STDERR, "\n    → $description\n");
    }

    // =========================================================================
    // REAL E2E TESTS - Full cycle with real cron chain
    // =========================================================================

    /**
     * @test
     * Sanity check: Server is running and health endpoint works.
     */
    public function e2e_serverHealthCheck(): void
    {
        $response = $this->httpRequest('GET', '/api/health');
        $this->assertEquals(200, $response['status'], 'Health check should return 200');
    }

    /**
     * @test
     * FULL CYCLE: Heat-to-target complete E2E workflow.
     *
     * This is THE test that catches integration bugs. It runs the REAL flow:
     *
     * 1. API call to start heating (like frontend would do)
     * 2. System turns on heater (IFTTT: hot-tub-heat-on) and schedules cron
     * 3. Execute cron-runner.sh (simulating cron daemon)
     * 4. System checks temp, still below target, schedules next cron
     * 5. Update temp (simulating ESP32 report showing heating progress)
     * 6. Execute next cron
     * 7. Repeat until target reached
     * 8. System turns off heater (IFTTT: hot-tub-heat-off), clears state
     * 9. Verify no more crons scheduled
     * 10. Verify IFTTT received both heat-on and heat-off signals
     */
    public function e2e_fullHeatingCycleRealCronChain(): void
    {
        $cronJwt = $this->getCronJwt();
        $this->assertNotEmpty($cronJwt, 'CRON_JWT must be configured');

        // =====================================================================
        // STEP 1: FE calls API to start heating (temp 82°F, target 101°F)
        // =====================================================================
        $this->step("STEP 1: Frontend calls POST /api/equipment/heat-to-target (temp=82°F, target=101°F)");

        $this->storeTemperature(82.0);

        $response = $this->httpRequest(
            'POST',
            '/api/equipment/heat-to-target',
            ['target_temp_f' => 101.0],
            $cronJwt
        );

        $this->assertEquals(200, $response['status'],
            "heat-to-target API should return 200.\n" .
            "Response: " . json_encode($response['body']));

        // Verify heater turned ON
        $equipment = $this->getEquipmentStatus();
        $this->assertTrue(
            $equipment['heater']['on'] ?? false,
            "Step 1 FAILED: Heater should be ON after heat-to-target starts.\n" .
            "Equipment status: " . json_encode($equipment)
        );

        // Verify IFTTT received heat-on signal
        $iftttEvents = $this->getIftttEvents();
        $this->assertContains(
            'hot-tub-heat-on',
            $iftttEvents,
            "Step 1 FAILED: IFTTT should receive 'hot-tub-heat-on' signal.\n" .
            "IFTTT events: " . json_encode($iftttEvents)
        );

        $this->step("  ✓ Heater ON, IFTTT signaled, target state active");

        // =====================================================================
        // STEP 2: Verify cron entry was added to REAL crontab
        // =====================================================================
        $this->step("STEP 2: Verify cron scheduled in crontab");

        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertNotEmpty(
            $cronEntries,
            "Step 2 FAILED: No cron entry in crontab after heat-to-target!\n" .
            "This means the cron chain never starts.\n" .
            "Crontab: " . (shell_exec('crontab -l 2>/dev/null') ?: '(empty)')
        );

        $jobId1 = $this->extractJobId($cronEntries[0]);
        $this->assertNotEmpty($jobId1, 'Should extract job ID from cron entry');

        // Verify job file exists with correct content
        $jobFile1 = $this->storageDir . "/scheduled-jobs/$jobId1.json";
        $this->assertFileExists($jobFile1, "Job file should exist: $jobFile1");

        $jobData1 = json_decode(file_get_contents($jobFile1), true);
        $this->assertEquals(
            '/api/maintenance/heat-target-check',
            $jobData1['endpoint'] ?? '',
            "Step 2 FAILED: Job file has wrong endpoint!\n" .
            "Expected: /api/maintenance/heat-target-check\n" .
            "Actual: " . ($jobData1['endpoint'] ?? 'null') . "\n" .
            "This will cause 404 when cron-runner.sh executes."
        );

        // CRITICAL: Verify cron is scheduled for the near future (not in the past!)
        // Expected: 10-120 seconds from now
        // - ESP32 interval when heating: 60 seconds
        // - Buffer: 5 seconds
        // - Minimum: 10 seconds (cron granularity check in code)
        // - Maximum: 120 seconds (allows for some timing variance)
        $this->assertCronScheduledWithinWindow(
            $cronEntries[0],
            10,  // minimum seconds from now
            120, // maximum seconds from now
            "Step 2 FAILED: Cron scheduled for wrong time!\n" .
            "This is why production fails - cron daemon never fires."
        );

        $this->step("  ✓ Cron entry added, job file created with correct endpoint, scheduled within 2 minutes");

        // =====================================================================
        // STEP 3: Execute cron-runner.sh (simulating cron daemon firing)
        // =====================================================================
        $this->step("STEP 3: Execute cron-runner.sh '$jobId1' (simulating cron fire, temp still 82°F)");

        $result1 = $this->executeCronRunner($jobId1);

        $this->assertEquals(
            0,
            $result1['returnCode'],
            "Step 3 FAILED: cron-runner.sh failed!\n" .
            "Return code: {$result1['returnCode']}\n" .
            "Output: {$result1['output']}\n" .
            "Cron log: {$result1['logOutput']}"
        );

        // Verify still heating (temp 82°F < target 101°F)
        $targetState = $this->getTargetTempState();
        $this->assertTrue(
            $targetState['active'] ?? false,
            "Step 3 FAILED: Target heating should still be active (temp below target)"
        );

        $this->step("  ✓ cron-runner.sh executed, API returned success, still heating");

        // =====================================================================
        // STEP 4: Verify NEW cron was scheduled (chain continues)
        // =====================================================================
        $this->step("STEP 4: Verify NEW cron scheduled (chain continues)");

        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertNotEmpty(
            $cronEntries,
            "Step 4 FAILED: No new cron scheduled after check!\n" .
            "The cron chain is broken - heater will run forever."
        );

        $jobId2 = $this->extractJobId($cronEntries[0]);
        $this->assertNotEquals(
            $jobId1,
            $jobId2,
            "Step 4 FAILED: New job ID should be different (old job should be cleaned up)"
        );

        $this->step("  ✓ New cron scheduled: $jobId2");

        // =====================================================================
        // STEP 5: Simulate heating progress (temp rises to 95°F)
        // =====================================================================
        $this->step("STEP 5: ESP32 reports temp=95°F, execute cron '$jobId2'");

        $this->storeTemperature(95.0);

        $result2 = $this->executeCronRunner($jobId2);
        $this->assertEquals(0, $result2['returnCode'],
            "Step 5 FAILED: Second cron execution failed.\n" .
            "Cron log: {$result2['logOutput']}");

        // Still heating
        $this->assertTrue(
            $this->getTargetTempState()['active'] ?? false,
            "Should still be heating at 95°F"
        );

        $this->step("  ✓ Still heating at 95°F");

        // =====================================================================
        // STEP 6: Another cron should be scheduled
        // =====================================================================
        $this->step("STEP 6: Verify another cron scheduled");

        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertNotEmpty($cronEntries, 'Another cron should be scheduled');
        $jobId3 = $this->extractJobId($cronEntries[0]);

        $this->step("  ✓ Cron scheduled: $jobId3");

        // =====================================================================
        // STEP 7: Simulate reaching target (temp rises to 101.5°F)
        // =====================================================================
        $this->step("STEP 7: ESP32 reports temp=101.5°F (TARGET REACHED), execute cron '$jobId3'");

        $this->storeTemperature(101.5);

        $result3 = $this->executeCronRunner($jobId3);
        $this->assertEquals(0, $result3['returnCode'],
            "Step 7 FAILED: Final cron execution failed.\n" .
            "Cron log: {$result3['logOutput']}");

        $this->step("  ✓ Cron executed, target reached!");

        // =====================================================================
        // STEP 8: Verify heater turned OFF and cycle ended
        // =====================================================================
        $this->step("STEP 8: Verify heater OFF and IFTTT signaled");

        $equipment = $this->getEquipmentStatus();
        $this->assertFalse(
            $equipment['heater']['on'] ?? true,
            "Step 8 FAILED: Heater should be OFF after reaching target.\n" .
            "Equipment status: " . json_encode($equipment)
        );

        $targetState = $this->getTargetTempState();
        $this->assertFalse(
            $targetState['active'] ?? true,
            "Step 8 FAILED: Target heating should be inactive after reaching target.\n" .
            "Target state: " . json_encode($targetState)
        );

        // Verify IFTTT received heat-off signal
        $iftttEvents = $this->getIftttEvents();
        $this->assertContains(
            'hot-tub-heat-off',
            $iftttEvents,
            "Step 8 FAILED: IFTTT should receive 'hot-tub-heat-off' signal.\n" .
            "IFTTT events: " . json_encode($iftttEvents)
        );

        $this->step("  ✓ Heater OFF, IFTTT signaled 'hot-tub-heat-off'");

        // =====================================================================
        // STEP 9: Verify NO new cron scheduled (cycle complete)
        // =====================================================================
        $this->step("STEP 9: Verify NO new cron scheduled (cycle complete)");

        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertEmpty(
            $cronEntries,
            "Step 9 FAILED: No cron should be scheduled after target reached.\n" .
            "Found entries: " . implode("\n", $cronEntries)
        );

        $this->step("  ✓ No more crons - cycle complete!");

        // =====================================================================
        // STEP 10: Summary - verify complete IFTTT signal chain
        // =====================================================================
        $this->step("STEP 10: Verify complete IFTTT signal chain");

        $iftttEvents = $this->getIftttEvents();
        $this->assertEquals(
            ['hot-tub-heat-on', 'hot-tub-heat-off'],
            $iftttEvents,
            "Complete IFTTT signal chain should be: heat-on → heat-off\n" .
            "Actual: " . json_encode($iftttEvents)
        );

        $this->step("  ✓ IFTTT signals: hot-tub-heat-on → hot-tub-heat-off");
        $this->step("\n  ══════════════════════════════════════════════");
        $this->step("  ✓ FULL HEATING CYCLE COMPLETE - ALL SYSTEMS GO!");
        $this->step("  ══════════════════════════════════════════════\n");
    }

    /**
     * @test
     * Edge case: What happens if target is already reached when starting?
     */
    public function e2e_targetAlreadyReachedOnStart(): void
    {
        $cronJwt = $this->getCronJwt();

        // Temperature already at target
        $this->storeTemperature(102.0);

        $response = $this->httpRequest(
            'POST',
            '/api/equipment/heat-to-target',
            ['target_temp_f' => 101.0],
            $cronJwt
        );

        $this->assertEquals(200, $response['status']);

        // Should immediately declare target reached
        $this->assertTrue(
            $response['body']['target_reached'] ?? false,
            "Should immediately detect target reached when temp >= target"
        );

        // Heater should NOT be turned on
        $equipment = $this->getEquipmentStatus();
        $this->assertFalse(
            $equipment['heater']['on'] ?? true,
            "Heater should stay OFF when target already reached"
        );

        // No cron should be scheduled
        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertEmpty($cronEntries, 'No cron should be scheduled when target already reached');
    }

    /**
     * @test
     * Edge case: Cancel heating mid-cycle.
     */
    public function e2e_cancelHeatingMidCycle(): void
    {
        $cronJwt = $this->getCronJwt();

        // Start heating
        $this->storeTemperature(82.0);
        $response = $this->httpRequest(
            'POST',
            '/api/equipment/heat-to-target',
            ['target_temp_f' => 101.0],
            $cronJwt
        );
        $this->assertEquals(200, $response['status']);

        // Verify cron was scheduled
        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertNotEmpty($cronEntries, 'Cron should be scheduled');

        // Cancel heating
        $response = $this->httpRequest('DELETE', '/api/equipment/heat-to-target', null, $cronJwt);
        $this->assertEquals(200, $response['status']);

        // Verify state cleared
        $targetState = $this->getTargetTempState();
        $this->assertFalse($targetState['active'] ?? true, 'Target should be inactive after cancel');

        // Verify cron cleaned up
        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertEmpty($cronEntries, 'Cron should be cleaned up after cancel');
    }

    /**
     * @test
     * BUG REPRODUCTION: Cron race condition with old ESP32 reading.
     *
     * This reproduces the production bug where:
     * 1. ESP32 last reported 90 seconds ago
     * 2. heat-to-target is called at minute X, second 10
     * 3. calculateNextCheckTime() returns a time in the CURRENT minute
     * 4. Cron daemon already fired at minute X, second 0
     * 5. The cron entry for minute X will NEVER fire!
     *
     * Expected: This test should FAIL until the bug is fixed.
     */
    public function e2e_cronRaceConditionWithOldEsp32Reading(): void
    {
        $cronJwt = $this->getCronJwt();

        // Store temperature with an OLD received_at (90 seconds ago)
        // This simulates production where ESP32 reports every 60s but the reading
        // might be up to 60s old when heat-to-target is called
        $this->storeTemperatureWithAge(82.0, 90);

        $beforeRequest = time();

        $response = $this->httpRequest(
            'POST',
            '/api/equipment/heat-to-target',
            ['target_temp_f' => 101.0],
            $cronJwt
        );

        $this->assertEquals(200, $response['status']);

        // Get the cron entry
        $cronEntries = $this->getHeatTargetCronEntries();
        $this->assertNotEmpty($cronEntries, 'Cron entry should be created');

        // This is the critical check: the cron MUST be in a future minute!
        // With a 90s old reading:
        // - receivedAt = now - 90
        // - nextReport = receivedAt + 60 = now - 30
        // - checkTime = nextReport + 5 = now - 25 (past!)
        // - checkTime += 60 = now + 35 (future, but possibly same minute!)
        //
        // If we're at 11:22:30, checkTime = 11:23:05 = minute 23 (OK!)
        // If we're at 11:22:10, checkTime = 11:22:45 = minute 22 (RACE CONDITION!)
        $this->assertCronScheduledWithinWindow(
            $cronEntries[0],
            10,  // at least 10 seconds
            120, // at most 2 minutes
            "Cron race condition test"
        );

        // Clean up
        $this->httpRequest('DELETE', '/api/equipment/heat-to-target', null, $cronJwt);
    }

    /**
     * Store a temperature reading with a specific age (seconds ago).
     */
    private function storeTemperatureWithAge(float $tempF, int $secondsAgo): void
    {
        $tempC = ($tempF - 32) * 5 / 9;
        $stateFile = $this->storageDir . '/state/esp32-temperature.json';
        $stateDir = dirname($stateFile);

        if (!is_dir($stateDir)) {
            mkdir($stateDir, 0755, true);
        }

        $receivedAt = time() - $secondsAgo;
        $timestamp = (new \DateTime("@$receivedAt"))->format('c');

        $data = [
            'device_id' => 'E2E:TEST:DEVICE',
            'sensors' => [
                [
                    'address' => '28:E2:E2:TE:ST:00:00:01',
                    'temp_c' => $tempC,
                    'temp_f' => $tempF,
                ],
            ],
            'uptime_seconds' => 3600,
            'timestamp' => $timestamp,
            'received_at' => $receivedAt,
            'temp_c' => $tempC,
            'temp_f' => $tempF,
        ];

        file_put_contents($stateFile, json_encode($data, JSON_PRETTY_PRINT));
    }
}
