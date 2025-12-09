<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\EnvLoader;
use HotTub\Services\IftttClientFactory;

/**
 * Integration tests proving EnvLoader works with IftttClientFactory.
 *
 * These tests demonstrate the full workflow:
 * 1. Load config from .env file via EnvLoader
 * 2. Pass config to IftttClientFactory
 * 3. Factory creates appropriate IFTTT client
 */
class EnvLoaderIntegrationTest extends TestCase
{
    private string $tempDir;
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/envloader-integration-' . uniqid();
        mkdir($this->tempDir);
        $this->testLogFile = $this->tempDir . '/events.log';
    }

    protected function tearDown(): void
    {
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

    public function testEnvLoaderConfigWorksWithFactory(): void
    {
        $envPath = $this->createEnvFile(
            "APP_ENV=development\nIFTTT_MODE=stub"
        );

        $loader = new EnvLoader();
        $config = $loader->load($envPath);

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory($config, $this->testLogFile, $output);
        $client = $factory->create($config['IFTTT_MODE'] ?? 'auto');

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testProductionConfigCreatesLiveClient(): void
    {
        $envPath = $this->createEnvFile(
            "APP_ENV=production\nIFTTT_MODE=live\nIFTTT_WEBHOOK_KEY=prod-secret-key"
        );

        $loader = new EnvLoader();
        $config = $loader->load($envPath);

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory($config, $this->testLogFile, $output);
        $client = $factory->create($config['IFTTT_MODE']);

        $this->assertEquals('live', $client->getMode());
        fclose($output);
    }

    public function testTestingConfigForceStubEvenWithKey(): void
    {
        $envPath = $this->createEnvFile(
            "APP_ENV=testing\nIFTTT_MODE=auto\nIFTTT_WEBHOOK_KEY=should-not-be-used"
        );

        $loader = new EnvLoader();
        $config = $loader->load($envPath);

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory($config, $this->testLogFile, $output);
        $client = $factory->create($config['IFTTT_MODE']);

        // Even with key, testing env should use stub in auto mode
        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testStagingWithLiveModeMakesLiveClient(): void
    {
        $envPath = $this->createEnvFile(
            "APP_ENV=staging\nIFTTT_MODE=live\nIFTTT_WEBHOOK_KEY=staging-key"
        );

        $loader = new EnvLoader();
        $config = $loader->load($envPath);

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory($config, $this->testLogFile, $output);
        $client = $factory->create($config['IFTTT_MODE']);

        $this->assertEquals('live', $client->getMode());
        fclose($output);
    }
}
