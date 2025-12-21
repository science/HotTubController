<?php

declare(strict_types=1);

namespace HotTub\Tests\Bin;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the bin/serve startup script.
 *
 * These tests verify that the server displays the correct mode based on
 * EXTERNAL_API_MODE configuration (not the deprecated IFTTT_MODE).
 */
class ServeScriptTest extends TestCase
{
    private string $backendDir;
    private string $envPath;
    private ?string $originalEnvContent = null;

    protected function setUp(): void
    {
        $this->backendDir = dirname(__DIR__, 2);
        $this->envPath = $this->backendDir . '/.env';

        // Backup original .env if it exists
        if (file_exists($this->envPath)) {
            $this->originalEnvContent = file_get_contents($this->envPath);
        }
    }

    protected function tearDown(): void
    {
        // Restore original .env
        if ($this->originalEnvContent !== null) {
            file_put_contents($this->envPath, $this->originalEnvContent);
        } elseif (file_exists($this->envPath)) {
            // If there was no original, remove the test one
            unlink($this->envPath);
        }
    }

    /**
     * Helper to get the banner output from serve script without starting the server.
     * We extract just the banner-display portion by running the script with a modification.
     */
    private function getServeBannerOutput(string $envContent): string
    {
        // Write the test .env file
        file_put_contents($this->envPath, $envContent);

        // Create a modified version of serve that just shows the banner
        $servePath = $this->backendDir . '/bin/serve';
        $serveContent = file_get_contents($servePath);

        // Replace the php server line with an exit to just get the banner
        $testScript = str_replace(
            'php -S localhost:8080 -t public',
            'exit 0',
            $serveContent
        );

        // Replace the cd line to use our backend directory explicitly
        $testScript = preg_replace(
            '/cd "\$\(dirname "\$0"\)\/\.\."/m',
            'cd ' . escapeshellarg($this->backendDir),
            $testScript
        );

        // Write to temp file and execute
        $tempScript = sys_get_temp_dir() . '/serve-test-' . uniqid() . '.sh';
        file_put_contents($tempScript, $testScript);
        chmod($tempScript, 0755);

        // Execute
        $output = [];
        $returnCode = 0;
        exec("bash {$tempScript} 2>&1", $output, $returnCode);

        // Clean up temp script
        unlink($tempScript);

        return implode("\n", $output);
    }

    public function testDisplaysLiveModeWhenExternalApiModeIsLive(): void
    {
        $envContent = <<<ENV
APP_ENV=production
EXTERNAL_API_MODE=live
IFTTT_WEBHOOK_KEY=test-key
ENV;

        $output = $this->getServeBannerOutput($envContent);

        $this->assertStringContainsString('LIVE', $output,
            'Should display LIVE when EXTERNAL_API_MODE=live');
        $this->assertStringContainsString('REAL HARDWARE', $output,
            'Should warn about real hardware when in live mode');
    }

    public function testDisplaysStubModeWhenExternalApiModeIsStub(): void
    {
        $envContent = <<<ENV
APP_ENV=development
EXTERNAL_API_MODE=stub
ENV;

        $output = $this->getServeBannerOutput($envContent);

        $this->assertStringContainsString('stub', $output,
            'Should display stub when EXTERNAL_API_MODE=stub');
        $this->assertStringContainsString('simulated', $output,
            'Should indicate simulated mode');
    }

    /**
     * This is the key test: EXTERNAL_API_MODE should take precedence over
     * the deprecated IFTTT_MODE variable.
     */
    public function testExternalApiModeTakesPrecedenceOverIftttMode(): void
    {
        // Set EXTERNAL_API_MODE=live but legacy IFTTT_MODE=stub
        // The script should show LIVE (from EXTERNAL_API_MODE)
        $envContent = <<<ENV
APP_ENV=production
EXTERNAL_API_MODE=live
IFTTT_MODE=stub
IFTTT_WEBHOOK_KEY=test-key
ENV;

        $output = $this->getServeBannerOutput($envContent);

        $this->assertStringContainsString('LIVE', $output,
            'EXTERNAL_API_MODE=live should show LIVE even when IFTTT_MODE=stub');
    }

    /**
     * Regression test: When only EXTERNAL_API_MODE is set (no IFTTT_MODE),
     * the script should correctly detect the mode.
     */
    public function testWorksWithOnlyExternalApiMode(): void
    {
        // Production config with only EXTERNAL_API_MODE (no IFTTT_MODE at all)
        $envContent = <<<ENV
APP_ENV=production
EXTERNAL_API_MODE=live
IFTTT_WEBHOOK_KEY=test-key
AUTH_ADMIN_USERNAME=admin
AUTH_ADMIN_PASSWORD=test
JWT_SECRET=test-secret
ENV;

        $output = $this->getServeBannerOutput($envContent);

        $this->assertStringContainsString('LIVE', $output,
            'Should detect LIVE mode from EXTERNAL_API_MODE when IFTTT_MODE is not set');
    }

    /**
     * Regression test: .env files with Windows CRLF line endings should work.
     * This was a real bug - the \r in "live\r" caused comparison to fail.
     */
    public function testHandlesCrlfLineEndings(): void
    {
        // Simulate Windows-style CRLF line endings (\r\n)
        $envContent = "APP_ENV=production\r\n" .
                      "EXTERNAL_API_MODE=live\r\n" .
                      "IFTTT_WEBHOOK_KEY=test-key\r\n";

        $output = $this->getServeBannerOutput($envContent);

        $this->assertStringContainsString('LIVE', $output,
            'Should detect LIVE mode even with CRLF line endings');
        $this->assertStringContainsString('REAL HARDWARE', $output,
            'Should warn about real hardware when in live mode with CRLF');
    }
}
