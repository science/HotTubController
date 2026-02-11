<?php

declare(strict_types=1);

namespace HotTub\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the cron-runner.sh shell script.
 */
class CronRunnerTest extends TestCase
{
    private string $testDir;
    private string $scriptPath;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/cron-runner-test-' . uniqid();
        mkdir($this->testDir, 0755, true);
        mkdir($this->testDir . '/storage/bin', 0755, true);
        mkdir($this->testDir . '/storage/scheduled-jobs', 0755, true);
        mkdir($this->testDir . '/storage/logs', 0755, true);
        mkdir($this->testDir . '/storage/state', 0755, true);

        // Copy the actual script
        $this->scriptPath = $this->testDir . '/storage/bin/cron-runner.sh';
        copy(
            __DIR__ . '/../../storage/bin/cron-runner.sh',
            $this->scriptPath
        );
        chmod($this->scriptPath, 0755);
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->testDir);
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

    private function createEnvFile(array $vars): void
    {
        $content = '';
        foreach ($vars as $key => $value) {
            $content .= "$key=$value\n";
        }
        file_put_contents($this->testDir . '/.env', $content);
    }

    private function createJobFile(string $jobId, array $data): void
    {
        file_put_contents(
            $this->testDir . '/storage/scheduled-jobs/' . $jobId . '.json',
            json_encode($data, JSON_PRETTY_PRINT)
        );
    }

    private function getLogContents(): string
    {
        $logFile = $this->testDir . '/storage/logs/cron.log';
        return file_exists($logFile) ? file_get_contents($logFile) : '';
    }

    public function testMissingCronJwtLogsError(): void
    {
        // Create .env WITHOUT CRON_JWT
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'IFTTT_MODE' => 'stub',
        ]);

        // Create a job file
        $this->createJobFile('job-test123', [
            'jobId' => 'job-test123',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
        ]);

        // Run the script
        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'job-test123'),
            $output,
            $exitCode
        );

        // Should have failed
        $this->assertNotEquals(0, $exitCode, 'Script should exit with non-zero when CRON_JWT missing');

        // Should have logged the error
        $log = $this->getLogContents();
        $this->assertStringContainsString('ERROR', $log, 'Log should contain ERROR');
        $this->assertStringContainsString('CRON_JWT', $log, 'Log should mention CRON_JWT');
    }

    public function testMissingEnvFileLogsError(): void
    {
        // Don't create .env file at all

        // Create a job file
        $this->createJobFile('job-test456', [
            'jobId' => 'job-test456',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
        ]);

        // Run the script
        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'job-test456'),
            $output,
            $exitCode
        );

        // Should have failed
        $this->assertNotEquals(0, $exitCode, 'Script should exit with non-zero when .env missing');

        // Should have logged the error
        $log = $this->getLogContents();
        $this->assertStringContainsString('ERROR', $log, 'Log should contain ERROR');
        $this->assertStringContainsString('.env', $log, 'Log should mention .env');
    }

    // ========== Skip File Tests ==========

    public function testRecurringJobSkippedWhenSkipFileDateMatchesToday(): void
    {
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'CRON_JWT' => 'test-jwt-token',
        ]);

        $this->createJobFile('rec-skip123', [
            'jobId' => 'rec-skip123',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
            'recurring' => true,
        ]);

        // Create skip file with today's date in SYSTEM timezone (not PHP's timezone)
        // cron-runner.sh uses `date +%Y-%m-%d` which is system timezone
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();
        $now = new \DateTime('now', new \DateTimeZone($systemTz));
        $today = $now->format('Y-m-d');
        file_put_contents(
            $this->testDir . '/storage/state/skip-rec-skip123.json',
            json_encode(['skip_date' => $today, 'created_at' => date('c')])
        );

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'rec-skip123'),
            $output,
            $exitCode
        );

        // Should exit 0 (skip is not an error)
        $this->assertEquals(0, $exitCode, 'Script should exit 0 when skip date matches today');

        // Log should mention SKIPPED
        $log = $this->getLogContents();
        $this->assertStringContainsString('SKIPPED', $log, 'Log should mention SKIPPED');

        // Skip file should be consumed (deleted)
        $this->assertFileDoesNotExist(
            $this->testDir . '/storage/state/skip-rec-skip123.json',
            'Skip file should be deleted after consumption'
        );
    }

    public function testStaleSkipFileDeletedButJobExecutesNormally(): void
    {
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'CRON_JWT' => 'test-jwt-token',
        ]);

        $this->createJobFile('rec-stale456', [
            'jobId' => 'rec-stale456',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
            'recurring' => true,
        ]);

        // Create skip file with yesterday's date in SYSTEM timezone (stale)
        $systemTz = \HotTub\Services\TimeConverter::getSystemTimezone();
        $yesterdayDt = new \DateTime('now', new \DateTimeZone($systemTz));
        $yesterdayDt->modify('-1 day');
        $yesterday = $yesterdayDt->format('Y-m-d');
        file_put_contents(
            $this->testDir . '/storage/state/skip-rec-stale456.json',
            json_encode(['skip_date' => $yesterday, 'created_at' => date('c')])
        );

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'rec-stale456'),
            $output,
            $exitCode
        );

        // Log should mention SKIP EXPIRED (stale skip file)
        $log = $this->getLogContents();
        $this->assertStringContainsString('SKIP EXPIRED', $log, 'Log should mention SKIP EXPIRED for stale skip file');

        // Skip file should still be consumed (deleted)
        $this->assertFileDoesNotExist(
            $this->testDir . '/storage/state/skip-rec-stale456.json',
            'Stale skip file should be deleted'
        );

        // Job should continue to execute (try calling API, which will fail - but the point is it tried)
        $this->assertStringContainsString('Calling API', $log, 'Job should continue executing after stale skip');
    }

    public function testRecurringJobWithoutSkipFileExecutesNormally(): void
    {
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'CRON_JWT' => 'test-jwt-token',
        ]);

        $this->createJobFile('rec-normal789', [
            'jobId' => 'rec-normal789',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
            'recurring' => true,
        ]);

        // No skip file exists

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'rec-normal789'),
            $output,
            $exitCode
        );

        // Log should NOT mention SKIPPED or SKIP EXPIRED
        $log = $this->getLogContents();
        $this->assertStringNotContainsString('SKIPPED', $log, 'Log should not mention SKIPPED for normal execution');
        $this->assertStringNotContainsString('SKIP EXPIRED', $log, 'Log should not mention SKIP EXPIRED');

        // Should attempt to call the API
        $this->assertStringContainsString('Calling API', $log, 'Should attempt API call');
    }

    public function testOneOffJobIgnoresSkipFile(): void
    {
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'CRON_JWT' => 'test-jwt-token',
        ]);

        $this->createJobFile('job-oneoff111', [
            'jobId' => 'job-oneoff111',
            'endpoint' => '/api/equipment/heater/on',
            'apiBaseUrl' => 'http://localhost:8080',
            'recurring' => false,
        ]);

        // Create a skip file (shouldn't be checked for one-off jobs)
        $today = date('Y-m-d');
        file_put_contents(
            $this->testDir . '/storage/state/skip-job-oneoff111.json',
            json_encode(['skip_date' => $today, 'created_at' => date('c')])
        );

        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'job-oneoff111'),
            $output,
            $exitCode
        );

        // Log should NOT mention SKIPPED (one-off jobs ignore skip files)
        $log = $this->getLogContents();
        $this->assertStringNotContainsString('SKIPPED', $log, 'One-off jobs should not check skip files');

        // Should attempt API call (normal execution)
        $this->assertStringContainsString('Calling API', $log, 'One-off job should execute normally');

        // Skip file should still exist (not consumed by one-off job logic)
        $this->assertFileExists(
            $this->testDir . '/storage/state/skip-job-oneoff111.json',
            'Skip file should not be consumed by one-off job'
        );
    }

    // ========== Original Tests ==========

    public function testMissingJobFileLogsError(): void
    {
        // Create .env with CRON_JWT
        $this->createEnvFile([
            'APP_ENV' => 'testing',
            'CRON_JWT' => 'test-jwt-token',
        ]);

        // Don't create job file

        // Run the script
        $output = [];
        $exitCode = 0;
        exec(
            sprintf('bash %s %s 2>&1', escapeshellarg($this->scriptPath), 'job-missing'),
            $output,
            $exitCode
        );

        // Should have failed
        $this->assertNotEquals(0, $exitCode, 'Script should exit with non-zero when job file missing');

        // Should have logged the error
        $log = $this->getLogContents();
        $this->assertStringContainsString('ERROR', $log, 'Log should contain ERROR');
        $this->assertStringContainsString('Job file not found', $log, 'Log should mention job file');
    }
}
