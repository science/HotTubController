<?php

declare(strict_types=1);

namespace HotTub\Tests\Integration;

use PHPUnit\Framework\TestCase;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\WirelessTagClientFactory;
use HotTub\Services\CurlWirelessTagHttpClient;
use RuntimeException;

/**
 * Live integration tests for WirelessTagClient.
 *
 * These tests make REAL API calls to the WirelessTag cloud service.
 * They require valid credentials and network connectivity.
 *
 * @group live
 * @group wirelesstag
 */
class WirelessTagClientLiveTest extends TestCase
{
    private ?string $oauthToken = null;
    private string $deviceId = '0';

    protected function setUp(): void
    {
        $this->oauthToken = $this->loadOAuthToken();
        $this->deviceId = $this->loadDeviceId();

        if ($this->oauthToken === null) {
            $this->markTestSkipped(
                'WirelessTag OAuth token not configured. ' .
                'Set WIRELESSTAG_OAUTH_TOKEN in backend/.env or environment.'
            );
        }
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
                $value = trim($matches[1]);
                $value = trim($value, '"\'');
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
                $value = trim($matches[1]);
                $value = trim($value, '"\'');
                if (!empty($value)) {
                    return $value;
                }
            }
        }

        return '0';
    }

    private function createLiveClient(): WirelessTagClient
    {
        $config = [
            'WIRELESSTAG_OAUTH_TOKEN' => $this->oauthToken,
            'WIRELESSTAG_DEVICE_ID' => $this->deviceId,
        ];

        $factory = new WirelessTagClientFactory($config);
        return $factory->create('live');
    }

    /**
     * @test
     */
    public function canConnectToWirelessTagApi(): void
    {
        $client = $this->createLiveClient();

        $result = $client->testConnectivity();

        $this->assertTrue($result['connected'], 'Should connect to WirelessTag API');
        $this->assertTrue($result['authenticated'], 'Should authenticate with OAuth token');
        $this->assertNull($result['error'], 'Should have no error');
        $this->assertIsInt($result['response_time_ms']);

        echo "\n  Connectivity test passed - Response time: {$result['response_time_ms']}ms\n";
    }

    /**
     * @test
     */
    public function canReadTemperatureFromSensor(): void
    {
        $client = $this->createLiveClient();

        $temp = $client->getTemperature($this->deviceId);

        // Verify structure
        $this->assertArrayHasKey('water_temp_c', $temp);
        $this->assertArrayHasKey('water_temp_f', $temp);
        $this->assertArrayHasKey('ambient_temp_c', $temp);
        $this->assertArrayHasKey('ambient_temp_f', $temp);
        $this->assertArrayHasKey('battery_voltage', $temp);
        $this->assertArrayHasKey('signal_dbm', $temp);
        $this->assertArrayHasKey('device_uuid', $temp);
        $this->assertArrayHasKey('timestamp', $temp);

        // Verify we got actual temperature values
        $this->assertNotNull($temp['water_temp_c'], 'Should have water temperature in Celsius');
        $this->assertNotNull($temp['water_temp_f'], 'Should have water temperature in Fahrenheit');

        // Basic sanity check on temperature values
        $this->assertGreaterThan(-40, $temp['water_temp_c'], 'Temperature should be reasonable');
        $this->assertLessThan(60, $temp['water_temp_c'], 'Temperature should be reasonable');

        // Output the readings for manual verification
        echo "\n  Temperature Reading:\n";
        echo "    Water: {$temp['water_temp_f']}°F ({$temp['water_temp_c']}°C)\n";
        if ($temp['ambient_temp_f'] !== null) {
            echo "    Ambient: {$temp['ambient_temp_f']}°F ({$temp['ambient_temp_c']}°C)\n";
        }
        echo "    Battery: {$temp['battery_voltage']}V\n";
        echo "    Signal: {$temp['signal_dbm']} dBm\n";
        echo "    Device: {$temp['device_uuid']}\n";
        if (isset($temp['device_name'])) {
            echo "    Name: {$temp['device_name']}\n";
        }
    }

    /**
     * @test
     */
    public function throwsExceptionWithInvalidToken(): void
    {
        $httpClient = new CurlWirelessTagHttpClient('invalid-token-12345', 30);
        $client = new WirelessTagClient($httpClient);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');

        $client->getTemperature($this->deviceId);
    }

    /**
     * @test
     */
    public function throwsExceptionWithEmptyToken(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be empty');

        new CurlWirelessTagHttpClient('');
    }

    /**
     * @test
     */
    public function clientReportsLiveMode(): void
    {
        $client = $this->createLiveClient();

        $this->assertEquals('live', $client->getMode());
    }
}
