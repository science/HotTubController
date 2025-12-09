<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\EnvLoader;
use RuntimeException;

/**
 * TDD tests for EnvLoader - file-based environment configuration.
 *
 * This service loads configuration from .env files, enabling:
 * - Simple FTP/cPanel deployment (just copy the right .env file)
 * - Different configs per environment (dev, test, staging, production)
 * - No dependency on system environment variables
 */
class EnvLoaderTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/envloader-test-' . uniqid();
        mkdir($this->tempDir);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $files = glob($this->tempDir . '/*');
        foreach ($files as $file) {
            unlink($file);
        }
        rmdir($this->tempDir);
    }

    private function createEnvFile(string $content): string
    {
        $path = $this->tempDir . '/.env';
        file_put_contents($path, $content);
        return $path;
    }

    // ========================================
    // Basic Parsing Tests
    // ========================================

    public function testLoadParsesSimpleKeyValuePairs(): void
    {
        $path = $this->createEnvFile("APP_ENV=development\nIFTTT_MODE=stub");

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('development', $config['APP_ENV']);
        $this->assertEquals('stub', $config['IFTTT_MODE']);
    }

    public function testLoadIgnoresCommentLines(): void
    {
        $path = $this->createEnvFile("# This is a comment\nAPP_ENV=production");

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertArrayNotHasKey('# This is a comment', $config);
        $this->assertEquals('production', $config['APP_ENV']);
    }

    public function testLoadIgnoresEmptyLines(): void
    {
        $path = $this->createEnvFile("APP_ENV=test\n\n\nIFTTT_MODE=live");

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertCount(2, $config);
        $this->assertEquals('test', $config['APP_ENV']);
        $this->assertEquals('live', $config['IFTTT_MODE']);
    }

    public function testLoadHandlesQuotedValues(): void
    {
        $path = $this->createEnvFile('IFTTT_WEBHOOK_KEY="my-secret-key"');

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('my-secret-key', $config['IFTTT_WEBHOOK_KEY']);
    }

    public function testLoadHandlesSingleQuotedValues(): void
    {
        $path = $this->createEnvFile("IFTTT_WEBHOOK_KEY='my-secret-key'");

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('my-secret-key', $config['IFTTT_WEBHOOK_KEY']);
    }

    public function testLoadHandlesValuesWithEqualsSign(): void
    {
        $path = $this->createEnvFile('DATABASE_URL=mysql://user:pass@host/db?charset=utf8');

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('mysql://user:pass@host/db?charset=utf8', $config['DATABASE_URL']);
    }

    public function testLoadHandlesInlineComments(): void
    {
        $path = $this->createEnvFile('APP_ENV=development # This is dev mode');

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('development', $config['APP_ENV']);
    }

    public function testLoadTrimsWhitespace(): void
    {
        $path = $this->createEnvFile('  APP_ENV  =  production  ');

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('production', $config['APP_ENV']);
    }

    // ========================================
    // Error Handling Tests
    // ========================================

    public function testLoadThrowsExceptionForMissingFile(): void
    {
        $loader = new EnvLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment file not found');

        $loader->load('/nonexistent/path/.env');
    }

    public function testLoadReturnsEmptyArrayForEmptyFile(): void
    {
        $path = $this->createEnvFile('');

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals([], $config);
    }

    public function testLoadSkipsLinesWithoutEquals(): void
    {
        $path = $this->createEnvFile("VALID=value\nINVALID_LINE_NO_EQUALS\nANOTHER=good");

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertCount(2, $config);
        $this->assertEquals('value', $config['VALID']);
        $this->assertEquals('good', $config['ANOTHER']);
    }

    // ========================================
    // Environment Label Tests
    // ========================================

    public function testCanLoadDevelopmentEnvironment(): void
    {
        $path = $this->createEnvFile(
            "APP_ENV=development\nIFTTT_MODE=stub\n# No API key in dev"
        );

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('development', $config['APP_ENV']);
        $this->assertEquals('stub', $config['IFTTT_MODE']);
        $this->assertArrayNotHasKey('IFTTT_WEBHOOK_KEY', $config);
    }

    public function testCanLoadTestingEnvironment(): void
    {
        $path = $this->createEnvFile(
            "APP_ENV=testing\nIFTTT_MODE=stub"
        );

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('testing', $config['APP_ENV']);
        $this->assertEquals('stub', $config['IFTTT_MODE']);
    }

    public function testCanLoadStagingEnvironment(): void
    {
        $path = $this->createEnvFile(
            "APP_ENV=staging\nIFTTT_MODE=live\nIFTTT_WEBHOOK_KEY=staging-key-123"
        );

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('staging', $config['APP_ENV']);
        $this->assertEquals('live', $config['IFTTT_MODE']);
        $this->assertEquals('staging-key-123', $config['IFTTT_WEBHOOK_KEY']);
    }

    public function testCanLoadProductionEnvironment(): void
    {
        $path = $this->createEnvFile(
            "APP_ENV=production\nIFTTT_MODE=live\nIFTTT_WEBHOOK_KEY=prod-secret-key"
        );

        $loader = new EnvLoader();
        $config = $loader->load($path);

        $this->assertEquals('production', $config['APP_ENV']);
        $this->assertEquals('live', $config['IFTTT_MODE']);
        $this->assertEquals('prod-secret-key', $config['IFTTT_WEBHOOK_KEY']);
    }

    // ========================================
    // Default Path Tests
    // ========================================

    public function testGetDefaultPathReturnsBackendEnvPath(): void
    {
        $loader = new EnvLoader();
        $path = $loader->getDefaultPath();

        // Should be relative to backend root
        $this->assertStringEndsWith('.env', $path);
        $this->assertStringContainsString('backend', $path);
    }

    // ========================================
    // Environment Switching Tests (TDD proof)
    // ========================================

    /**
     * Proves we can install different environment configs and detect them.
     * This test installs a "dev" config, verifies it, then installs a "test" config.
     */
    public function testCanSwitchBetweenEnvironmentConfigs(): void
    {
        $loader = new EnvLoader();
        $envPath = $this->tempDir . '/.env';

        // Install development config
        file_put_contents($envPath, "APP_ENV=development\nIFTTT_MODE=stub");
        $devConfig = $loader->load($envPath);
        $this->assertEquals('development', $devConfig['APP_ENV']);

        // Now install testing config (simulating deployment process)
        file_put_contents($envPath, "APP_ENV=testing\nIFTTT_MODE=stub");
        $testConfig = $loader->load($envPath);
        $this->assertEquals('testing', $testConfig['APP_ENV']);

        // Verify they are different
        $this->assertNotEquals($devConfig['APP_ENV'], $testConfig['APP_ENV']);
    }

    /**
     * Proves environment config determines IFTTT mode behavior.
     */
    public function testEnvironmentConfigDeterminesIftttMode(): void
    {
        $loader = new EnvLoader();
        $envPath = $this->tempDir . '/.env';

        // Dev config - stub mode, no key
        file_put_contents($envPath, "APP_ENV=development\nIFTTT_MODE=stub");
        $devConfig = $loader->load($envPath);

        // Production config - live mode with key
        file_put_contents($envPath, "APP_ENV=production\nIFTTT_MODE=live\nIFTTT_WEBHOOK_KEY=real-key");
        $prodConfig = $loader->load($envPath);

        $this->assertEquals('stub', $devConfig['IFTTT_MODE']);
        $this->assertArrayNotHasKey('IFTTT_WEBHOOK_KEY', $devConfig);

        $this->assertEquals('live', $prodConfig['IFTTT_MODE']);
        $this->assertEquals('real-key', $prodConfig['IFTTT_WEBHOOK_KEY']);
    }
}
