<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Integration tests for cron job execution.
 *
 * These tests verify the full flow: cron-runner.sh → API → action execution.
 * Uses the real backend with a dedicated test server.
 *
 * PREREQUISITE: CRON_JWT must be set in backend/.env
 * Run: php bin/generate-cron-jwt.php
 *
 * @group integration
 * @group slow
 */
class CronExecutionTest extends TestCase
{
    private static ?int $serverPid = null;
    private static int $serverPort;
    private static string $backendDir;
    private static string $originalEventsLog = '';

    public static function setUpBeforeClass(): void
    {
        self::$backendDir = dirname(__DIR__, 2);

        // Verify CRON_JWT exists
        $envFile = self::$backendDir . '/.env';
        if (!file_exists($envFile)) {
            self::markTestSkipped('.env file not found - run from backend directory');
        }

        $envContents = file_get_contents($envFile);
        if (!preg_match('/^CRON_JWT=.+/m', $envContents)) {
            self::markTestSkipped('CRON_JWT not set in .env - run: php bin/generate-cron-jwt.php');
        }

        // Find available port
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($socket, '127.0.0.1', 0);
        socket_getsockname($socket, $addr, $port);
        socket_close($socket);
        self::$serverPort = $port;

        // Start PHP dev server
        $publicDir = self::$backendDir . '/public';
        $logFile = sys_get_temp_dir() . '/cron-integration-server.log';
        $cmd = sprintf(
            'php -S 127.0.0.1:%d -t %s > %s 2>&1 & echo $!',
            self::$serverPort,
            escapeshellarg($publicDir),
            escapeshellarg($logFile)
        );

        $pid = trim(shell_exec($cmd));
        self::$serverPid = (int) $pid;

        // Wait for server to be ready
        $maxWait = 50; // 5 seconds max
        $ready = false;
        for ($i = 0; $i < $maxWait; $i++) {
            usleep(100000); // 100ms
            $fp = @fsockopen('127.0.0.1', self::$serverPort, $errno, $errstr, 1);
            if ($fp) {
                fclose($fp);
                $ready = true;
                break;
            }
        }

        if (!$ready) {
            $serverLog = file_exists($logFile) ? file_get_contents($logFile) : 'No log';
            self::fail("Failed to start PHP dev server on port " . self::$serverPort . "\nLog: $serverLog");
        }

        // Verify server responds to health check
        $ch = curl_init('http://127.0.0.1:' . self::$serverPort . '/api/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            self::fail("Server health check failed. HTTP $httpCode, Response: $response");
        }

        // Store original events log content
        $eventsLog = self::$backendDir . '/logs/events.log';
        if (file_exists($eventsLog)) {
            self::$originalEventsLog = file_get_contents($eventsLog);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$serverPid) {
            posix_kill(self::$serverPid, SIGTERM);
            usleep(200000);
            if (posix_kill(self::$serverPid, 0)) {
                posix_kill(self::$serverPid, SIGKILL);
            }
        }
    }

    protected function setUp(): void
    {
        // Clean up any leftover test jobs
        $jobsDir = self::$backendDir . '/storage/scheduled-jobs';
        foreach (glob($jobsDir . '/job-test-*.json') as $file) {
            unlink($file);
        }
    }

    protected function tearDown(): void
    {
        // Clean up test jobs
        $jobsDir = self::$backendDir . '/storage/scheduled-jobs';
        foreach (glob($jobsDir . '/job-test-*.json') as $file) {
            unlink($file);
        }
    }

    private function createJobFile(string $jobId, string $action, string $endpoint): string
    {
        $data = [
            'jobId' => $jobId,
            'action' => $action,
            'endpoint' => $endpoint,
            'apiBaseUrl' => 'http://127.0.0.1:' . self::$serverPort,
            'scheduledTime' => date('c'),
            'createdAt' => date('c'),
        ];
        $jobFile = self::$backendDir . '/storage/scheduled-jobs/' . $jobId . '.json';
        // JSON_UNESCAPED_SLASHES is critical - bash script parses the URLs literally
        file_put_contents($jobFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $jobFile;
    }

    private function getCronLog(): string
    {
        $logFile = self::$backendDir . '/storage/logs/cron.log';
        return file_exists($logFile) ? file_get_contents($logFile) : '';
    }

    private function getNewEventsLogEntries(): string
    {
        $eventsLog = self::$backendDir . '/logs/events.log';
        if (!file_exists($eventsLog)) {
            return '';
        }
        $fullLog = file_get_contents($eventsLog);
        return substr($fullLog, strlen(self::$originalEventsLog));
    }

    /**
     * Test that cron-runner.sh successfully calls the heater-on endpoint.
     */
    public function testCronRunnerExecutesHeaterOnAction(): void
    {
        $jobId = 'job-test-heater-' . uniqid();
        $scriptPath = self::$backendDir . '/storage/bin/cron-runner.sh';
        $jobFile = $this->createJobFile($jobId, 'heater-on', '/api/equipment/heater/on');

        // Clear cron log before test
        $cronLogFile = self::$backendDir . '/storage/logs/cron.log';
        if (file_exists($cronLogFile)) {
            $beforeLog = file_get_contents($cronLogFile);
        } else {
            $beforeLog = '';
        }

        // Verify server is still up before running cron
        $ch = curl_init('http://127.0.0.1:' . self::$serverPort . '/api/health');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $healthResponse = curl_exec($ch);
        $healthCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($healthCode !== 200) {
            $this->fail("Server died! Health check returned HTTP $healthCode before running cron");
        }

        // Run cron-runner.sh directly (simulating what cron would do)
        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($scriptPath), $jobId),
            $output,
            $exitCode
        );

        // Get only the new log entries
        $afterLog = file_exists($cronLogFile) ? file_get_contents($cronLogFile) : '';
        $newLogEntries = substr($afterLog, strlen($beforeLog));

        // Debug output if test fails
        if ($exitCode !== 0) {
            echo "\n=== DEBUG INFO ===\n";
            echo "Cron runner output: " . implode("\n", $output) . "\n";
            echo "New cron log entries:\n$newLogEntries\n";
            echo "===================\n";
        }

        $this->assertEquals(0, $exitCode, 'Cron runner should exit with code 0');
        $this->assertStringContainsString('SUCCESS', $newLogEntries, 'Cron log should contain SUCCESS');
        $this->assertStringContainsString('API returned 200', $newLogEntries, 'API should return 200');

        // Job file should be deleted
        $this->assertFileDoesNotExist($jobFile, 'Job file should be deleted after execution');

        // Events log should show heater_on action
        $newEvents = $this->getNewEventsLogEntries();
        $this->assertStringContainsString('heater_on', $newEvents, 'Events log should show heater_on action');
    }

    /**
     * Test that cron-runner.sh handles pump-run action.
     */
    public function testCronRunnerExecutesPumpRunAction(): void
    {
        $jobId = 'job-test-pump-' . uniqid();
        $scriptPath = self::$backendDir . '/storage/bin/cron-runner.sh';
        $jobFile = $this->createJobFile($jobId, 'pump-run', '/api/equipment/pump/run');

        $cronLogFile = self::$backendDir . '/storage/logs/cron.log';
        $beforeLog = file_exists($cronLogFile) ? file_get_contents($cronLogFile) : '';

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($scriptPath), $jobId),
            $output,
            $exitCode
        );

        $afterLog = file_exists($cronLogFile) ? file_get_contents($cronLogFile) : '';
        $newLogEntries = substr($afterLog, strlen($beforeLog));

        $this->assertEquals(0, $exitCode, 'Cron runner should exit with code 0');
        $this->assertStringContainsString('SUCCESS', $newLogEntries);
        $this->assertFileDoesNotExist($jobFile);
    }

    /**
     * Test that cron-runner.sh handles heater-off action.
     */
    public function testCronRunnerExecutesHeaterOffAction(): void
    {
        $jobId = 'job-test-heater-off-' . uniqid();
        $scriptPath = self::$backendDir . '/storage/bin/cron-runner.sh';
        $jobFile = $this->createJobFile($jobId, 'heater-off', '/api/equipment/heater/off');

        $cronLogFile = self::$backendDir . '/storage/logs/cron.log';
        $beforeLog = file_exists($cronLogFile) ? file_get_contents($cronLogFile) : '';

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($scriptPath), $jobId),
            $output,
            $exitCode
        );

        $afterLog = file_exists($cronLogFile) ? file_get_contents($cronLogFile) : '';
        $newLogEntries = substr($afterLog, strlen($beforeLog));

        $this->assertEquals(0, $exitCode, 'Cron runner should exit with code 0');
        $this->assertStringContainsString('SUCCESS', $newLogEntries);
        $this->assertFileDoesNotExist($jobFile);

        // Events log should show heater_off action
        $newEvents = $this->getNewEventsLogEntries();
        $this->assertStringContainsString('heater_off', $newEvents, 'Events log should show heater_off action');
    }
}
