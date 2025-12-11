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
