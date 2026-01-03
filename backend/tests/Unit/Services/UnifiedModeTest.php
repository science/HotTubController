<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\IftttClientFactory;
use HotTub\Services\WirelessTagClientFactory;

/**
 * Tests that EXTERNAL_API_MODE provides unified control over all external API calls.
 *
 * The mode priority for all factories should be:
 * 1. Explicit $mode parameter ('stub' | 'live') - for tests
 * 2. $config['EXTERNAL_API_MODE'] - unified system mode
 * 3. Legacy fallback (IFTTT_MODE for IFTTT factory)
 * 4. Default: 'stub' (fail-safe)
 */
class UnifiedModeTest extends TestCase
{
    private string $tempLogFile;
    private ?string $originalExternalApiMode = null;

    protected function setUp(): void
    {
        $this->tempLogFile = sys_get_temp_dir() . '/test-ifttt.log';
        // Capture original mode to restore in tearDown
        $this->originalExternalApiMode = getenv('EXTERNAL_API_MODE') ?: null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempLogFile)) {
            unlink($this->tempLogFile);
        }
        // Restore original mode
        if ($this->originalExternalApiMode !== null) {
            putenv("EXTERNAL_API_MODE={$this->originalExternalApiMode}");
        } else {
            putenv('EXTERNAL_API_MODE');
        }
    }

    /**
     * @test
     * EXTERNAL_API_MODE=stub should force stub mode even with valid API key.
     */
    public function iftttFactoryUsesExternalApiModeStub(): void
    {
        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create(); // No explicit mode - should use EXTERNAL_API_MODE

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * EXTERNAL_API_MODE=live should use live mode when API key present.
     */
    public function iftttFactoryUsesExternalApiModeLive(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $config = [
            'EXTERNAL_API_MODE' => 'live',
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create(); // No explicit mode - should use EXTERNAL_API_MODE

        $this->assertSame('live', $client->getMode());
    }

    /**
     * @test
     * EXTERNAL_API_MODE=stub should force stub mode for WirelessTag even with valid OAuth token.
     */
    public function wirelessTagFactoryUsesExternalApiModeStub(): void
    {
        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'WIRELESSTAG_OAUTH_TOKEN' => 'real-oauth-token-present',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create(); // No explicit mode - should use EXTERNAL_API_MODE

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * EXTERNAL_API_MODE=live should use live mode for WirelessTag when token present.
     */
    public function wirelessTagFactoryUsesExternalApiModeLive(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $config = [
            'EXTERNAL_API_MODE' => 'live',
            'WIRELESSTAG_OAUTH_TOKEN' => 'real-oauth-token-present',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create(); // No explicit mode - should use EXTERNAL_API_MODE

        $this->assertSame('live', $client->getMode());
    }

    /**
     * @test
     * Explicit mode parameter should take precedence over EXTERNAL_API_MODE.
     * This is critical for live tests that need to override config.
     */
    public function explicitModeParamOverridesExternalApiMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create('live'); // Explicit mode should override

        $this->assertSame('live', $client->getMode());
    }

    /**
     * @test
     * Explicit mode parameter for WirelessTag should take precedence over EXTERNAL_API_MODE.
     */
    public function explicitModeParamOverridesExternalApiModeWirelessTag(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'WIRELESSTAG_OAUTH_TOKEN' => 'real-oauth-token-present',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create('live'); // Explicit mode should override

        $this->assertSame('live', $client->getMode());
    }

    /**
     * @test
     * Legacy IFTTT_MODE should work if EXTERNAL_API_MODE is not set.
     * This provides backward compatibility.
     */
    public function legacyIftttModeWorksAsFallback(): void
    {
        $config = [
            // EXTERNAL_API_MODE not set
            'IFTTT_MODE' => 'stub',
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create(); // Should fall back to IFTTT_MODE

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * EXTERNAL_API_MODE should take precedence over legacy IFTTT_MODE.
     */
    public function externalApiModeTakesPrecedenceOverLegacyIftttMode(): void
    {
        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'IFTTT_MODE' => 'live', // Legacy says live
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create(); // EXTERNAL_API_MODE should win

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * When neither EXTERNAL_API_MODE nor IFTTT_MODE is set, default to stub (fail-safe).
     */
    public function defaultsToStubWhenNoModeConfigured(): void
    {
        $config = [
            // No EXTERNAL_API_MODE
            // No IFTTT_MODE
            'IFTTT_WEBHOOK_KEY' => 'real-api-key-present',
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);
        $client = $factory->create(); // Should default to stub

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * When no mode is configured for WirelessTag, default to stub (fail-safe).
     */
    public function wirelessTagDefaultsToStubWhenNoModeConfigured(): void
    {
        $config = [
            // No EXTERNAL_API_MODE
            'WIRELESSTAG_OAUTH_TOKEN' => 'real-oauth-token-present',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create(); // Should default to stub

        $this->assertSame('stub', $client->getMode());
    }

    /**
     * @test
     * EXTERNAL_API_MODE=live without API key should throw for IFTTT.
     */
    public function externalApiModeLiveWithoutIftttKeyThrows(): void
    {
        // Clear environment variable so config takes effect
        putenv('EXTERNAL_API_MODE');
        unset($_ENV['EXTERNAL_API_MODE']);

        $config = [
            'EXTERNAL_API_MODE' => 'live',
            // No IFTTT_WEBHOOK_KEY
        ];

        $factory = new IftttClientFactory($config, $this->tempLogFile);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('IFTTT_WEBHOOK_KEY required for live mode');

        $factory->create();
    }

    /**
     * @test
     * EXTERNAL_API_MODE=live without OAuth token should throw for WirelessTag.
     */
    public function externalApiModeLiveWithoutWirelessTagTokenThrows(): void
    {
        // Clear environment variable so config takes effect
        putenv('EXTERNAL_API_MODE');
        unset($_ENV['EXTERNAL_API_MODE']);

        $config = [
            'EXTERNAL_API_MODE' => 'live',
            // No WIRELESSTAG_OAUTH_TOKEN
        ];

        $factory = new WirelessTagClientFactory($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('WIRELESSTAG_OAUTH_TOKEN required for live mode');

        $factory->create();
    }
}
