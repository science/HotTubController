<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\WirelessTagClientFactory;

/**
 * Tests that stub and live modes produce equivalent behavior.
 *
 * This test validates that:
 * 1. Live mode returns real data from the WirelessTag API
 * 2. Stub mode returns simulated data with identical structure
 * 3. Both modes can be used interchangeably for testing/development
 *
 * Note: Only tests that make actual API calls are tagged @group live.
 * Stub-only tests run in the default test suite.
 *
 * @group wirelesstag
 */
class WirelessTagClientModeTest extends TestCase
{
    private ?string $oauthToken = null;
    private string $deviceId = '0';
    private ?array $liveSnapshot = null;

    protected function setUp(): void
    {
        $this->oauthToken = $this->loadOAuthToken();
        $this->deviceId = $this->loadDeviceId();
    }

    private function loadOAuthToken(): ?string
    {
        $token = getenv('WIRELESSTAG_OAUTH_TOKEN');
        if ($token !== false && !empty($token)) {
            return $token;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            if (preg_match('/^WIRELESSTAG_OAUTH_TOKEN=(.+)$/m', $content, $matches)) {
                $value = trim(trim($matches[1]), '"\'');
                if (!empty($value) && $value !== 'your-wirelesstag-oauth-token-here') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function loadDeviceId(): string
    {
        $deviceId = getenv('WIRELESSTAG_DEVICE_ID');
        if ($deviceId !== false && !empty($deviceId)) {
            return $deviceId;
        }

        $envPath = dirname(__DIR__, 2) . '/.env';
        if (file_exists($envPath)) {
            $content = file_get_contents($envPath);
            if (preg_match('/^WIRELESSTAG_DEVICE_ID=(.+)$/m', $content, $matches)) {
                return trim(trim($matches[1]), '"\'');
            }
        }

        return '0';
    }

    /**
     * @test
     * @group live
     * First, capture live data for comparison.
     */
    public function liveModeCapturesRealTemperatureData(): void
    {
        if ($this->oauthToken === null) {
            $this->markTestSkipped('WirelessTag OAuth token not configured');
        }

        $config = [
            'WIRELESSTAG_OAUTH_TOKEN' => $this->oauthToken,
            'WIRELESSTAG_DEVICE_ID' => $this->deviceId,
            'WIRELESSTAG_MODE' => 'live',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create('live');

        $this->assertEquals('live', $client->getMode());

        $temp = $client->getTemperature($this->deviceId);

        // Store snapshot for stub comparison
        $this->liveSnapshot = $temp;

        // Validate structure
        $this->assertTemperatureStructure($temp);

        // Validate reasonable temperature ranges
        $this->assertTemperatureRanges($temp);

        echo "\n  LIVE Mode Temperature:\n";
        echo "    Water: {$temp['water_temp_f']}°F\n";
        echo "    Mode: {$client->getMode()}\n";
    }

    /**
     * @test
     * Then, validate stub produces equivalent structure.
     */
    public function stubModeProducesEquivalentStructure(): void
    {
        $config = [
            'WIRELESSTAG_OAUTH_TOKEN' => 'stub-token-not-used',
            'WIRELESSTAG_DEVICE_ID' => $this->deviceId,
            'WIRELESSTAG_MODE' => 'stub',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create('stub');

        $this->assertEquals('stub', $client->getMode());

        $temp = $client->getTemperature($this->deviceId);

        // Validate identical structure to live mode
        $this->assertTemperatureStructure($temp);

        // Validate reasonable temperature ranges (stub should return realistic data)
        $this->assertTemperatureRanges($temp);

        echo "\n  STUB Mode Temperature:\n";
        echo "    Water: {$temp['water_temp_f']}°F\n";
        echo "    Mode: {$client->getMode()}\n";
    }

    /**
     * @test
     * Auto mode should use stub when in testing environment.
     */
    public function autoModeUsesStubInTestingEnvironment(): void
    {
        $config = [
            'APP_ENV' => 'testing',
            'WIRELESSTAG_OAUTH_TOKEN' => $this->oauthToken ?? 'some-token',
            'WIRELESSTAG_DEVICE_ID' => $this->deviceId,
            'WIRELESSTAG_MODE' => 'auto',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create('auto');

        $this->assertEquals('stub', $client->getMode());
    }

    /**
     * @test
     * Auto mode should use live when token available and not testing.
     */
    public function autoModeUsesLiveWhenTokenAvailable(): void
    {
        if ($this->oauthToken === null) {
            $this->markTestSkipped('WirelessTag OAuth token not configured');
        }

        $config = [
            'APP_ENV' => 'development',
            'WIRELESSTAG_OAUTH_TOKEN' => $this->oauthToken,
            'WIRELESSTAG_DEVICE_ID' => $this->deviceId,
            'WIRELESSTAG_MODE' => 'auto',
        ];

        $factory = new WirelessTagClientFactory($config);
        $client = $factory->create('auto');

        $this->assertEquals('live', $client->getMode());
    }

    /**
     * @test
     * Factory should throw for invalid mode.
     */
    public function factoryThrowsForInvalidMode(): void
    {
        $config = [
            'WIRELESSTAG_OAUTH_TOKEN' => 'test',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode');

        $factory->create('invalid');
    }

    /**
     * @test
     * Live mode without token should throw.
     */
    public function liveModeWithoutTokenThrows(): void
    {
        $config = [
            'WIRELESSTAG_OAUTH_TOKEN' => '',
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('required for live mode');

        $factory->create('live');
    }

    /**
     * @test
     * In stub mode, isConfigured should return true even without an OAuth token.
     *
     * Stub mode doesn't require credentials because it uses simulated data.
     * The controller should not block temperature reads in stub mode just
     * because the token is missing.
     */
    public function isConfiguredReturnsTrueInStubModeWithoutToken(): void
    {
        $config = [
            'EXTERNAL_API_MODE' => 'stub',
            'WIRELESSTAG_OAUTH_TOKEN' => '',  // No token
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);

        // In stub mode, we don't need credentials
        $this->assertTrue(
            $factory->isConfigured(),
            'Factory should report as configured in stub mode even without OAuth token'
        );
    }

    /**
     * @test
     * In live mode without token, isConfigured should return false.
     */
    public function isConfiguredReturnsFalseInLiveModeWithoutToken(): void
    {
        $config = [
            'EXTERNAL_API_MODE' => 'live',
            'WIRELESSTAG_OAUTH_TOKEN' => '',  // No token
            'WIRELESSTAG_DEVICE_ID' => '0',
        ];

        $factory = new WirelessTagClientFactory($config);

        // In live mode, we need credentials
        $this->assertFalse(
            $factory->isConfigured(),
            'Factory should report as not configured in live mode without OAuth token'
        );
    }

    /**
     * Assert temperature response has expected structure.
     */
    private function assertTemperatureStructure(array $temp): void
    {
        $requiredKeys = [
            'water_temp_c',
            'water_temp_f',
            'ambient_temp_c',
            'ambient_temp_f',
            'battery_voltage',
            'signal_dbm',
            'device_uuid',
            'device_name',
            'timestamp',
        ];

        foreach ($requiredKeys as $key) {
            $this->assertArrayHasKey($key, $temp, "Missing required key: {$key}");
        }

        // Water temperature must be present
        $this->assertNotNull($temp['water_temp_c'], 'Water temp (C) should not be null');
        $this->assertNotNull($temp['water_temp_f'], 'Water temp (F) should not be null');
    }

    /**
     * Assert temperature values are within realistic ranges.
     */
    private function assertTemperatureRanges(array $temp): void
    {
        // Water temp: 32°F to 120°F is reasonable for hot tub
        $this->assertGreaterThanOrEqual(32, $temp['water_temp_f'], 'Water temp too low');
        $this->assertLessThanOrEqual(120, $temp['water_temp_f'], 'Water temp too high');

        // Battery voltage: 2.5V to 4.0V typical for CR2032 or similar
        if ($temp['battery_voltage'] !== null) {
            $this->assertGreaterThanOrEqual(2.5, $temp['battery_voltage'], 'Battery too low');
            $this->assertLessThanOrEqual(4.0, $temp['battery_voltage'], 'Battery too high');
        }

        // Signal strength: -100 to -30 dBm typical for WiFi
        if ($temp['signal_dbm'] !== null) {
            $this->assertGreaterThanOrEqual(-100, $temp['signal_dbm'], 'Signal too weak');
            $this->assertLessThanOrEqual(-30, $temp['signal_dbm'], 'Signal unrealistically strong');
        }
    }
}
