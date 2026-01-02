<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\TemperatureController;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\StubWirelessTagHttpClient;
use HotTub\Services\TemperatureStateService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\Esp32CalibratedTemperatureService;

/**
 * Unit tests for TemperatureController.
 *
 * These tests use stub WirelessTag client for fast, reliable testing.
 */
class TemperatureControllerTest extends TestCase
{
    private TemperatureController $controller;
    private StubWirelessTagHttpClient $stubHttpClient;
    private string $stateFilePath;
    private TemperatureStateService $stateService;

    protected function setUp(): void
    {
        $this->stubHttpClient = new StubWirelessTagHttpClient();
        $client = new WirelessTagClient($this->stubHttpClient);

        // Create temp state file for each test
        $this->stateFilePath = sys_get_temp_dir() . '/test_temp_state_' . uniqid() . '.json';
        $this->stateService = new TemperatureStateService($this->stateFilePath);

        $this->controller = new TemperatureController($client, null, $this->stateService);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->stateFilePath)) {
            unlink($this->stateFilePath);
        }
    }

    /**
     * @test
     */
    public function getReturnsTemperatureDataWithCorrectStructure(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);

        $body = $response['body'];
        $this->assertArrayHasKey('water_temp_f', $body);
        $this->assertArrayHasKey('water_temp_c', $body);
        $this->assertArrayHasKey('ambient_temp_f', $body);
        $this->assertArrayHasKey('ambient_temp_c', $body);
        $this->assertArrayHasKey('battery_voltage', $body);
        $this->assertArrayHasKey('signal_dbm', $body);
        $this->assertArrayHasKey('device_name', $body);
        $this->assertArrayHasKey('timestamp', $body);
    }

    /**
     * @test
     */
    public function getReturnsNumericTemperatureValues(): void
    {
        $response = $this->controller->get();
        $body = $response['body'];

        $this->assertIsFloat($body['water_temp_f']);
        $this->assertIsFloat($body['water_temp_c']);
        $this->assertIsFloat($body['ambient_temp_f']);
        $this->assertIsFloat($body['ambient_temp_c']);
    }

    /**
     * @test
     */
    public function getReturnsReasonableTemperatureValues(): void
    {
        // Configure stub with known values
        $this->stubHttpClient->setWaterTemperature(38.0);  // 100.4°F
        $this->stubHttpClient->setAmbientTemperature(20.0);  // 68°F

        $response = $this->controller->get();
        $body = $response['body'];

        // Water temp should be around 100.4°F
        $this->assertEqualsWithDelta(100.4, $body['water_temp_f'], 0.5);

        // Ambient temp should be around 68°F
        $this->assertEqualsWithDelta(68.0, $body['ambient_temp_f'], 0.5);
    }

    /**
     * @test
     */
    public function getIncludesTimestamp(): void
    {
        $response = $this->controller->get();
        $body = $response['body'];

        $this->assertNotEmpty($body['timestamp']);
        // Timestamp should be a valid ISO 8601 format
        $this->assertNotFalse(strtotime($body['timestamp']));
    }

    /**
     * @test
     */
    public function getReturnsDeviceName(): void
    {
        $response = $this->controller->get();
        $body = $response['body'];

        $this->assertIsString($body['device_name']);
    }

    /**
     * @test
     */
    public function getReturns500OnApiError(): void
    {
        // Create a mock that throws an exception
        $mockHttpClient = $this->createMock(\HotTub\Contracts\WirelessTagHttpClientInterface::class);
        $mockHttpClient->method('post')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $client = new WirelessTagClient($mockHttpClient);
        $controller = new TemperatureController($client);

        $response = $controller->get();

        $this->assertEquals(500, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('Connection failed', $response['body']['error']);
    }

    /**
     * @test
     * The refresh endpoint should trigger a sensor refresh and return success.
     */
    public function refreshReturns200OnSuccess(): void
    {
        $response = $this->controller->refresh();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('success', $response['body']);
        $this->assertTrue($response['body']['success']);
        $this->assertArrayHasKey('message', $response['body']);
    }

    /**
     * @test
     * The refresh endpoint should return 503 on API failure.
     */
    public function refreshReturns503OnApiFailure(): void
    {
        // Create a mock that throws an exception
        $mockHttpClient = $this->createMock(\HotTub\Contracts\WirelessTagHttpClientInterface::class);
        $mockHttpClient->method('post')
            ->willThrowException(new \RuntimeException('Connection failed'));

        $client = new WirelessTagClient($mockHttpClient);
        $controller = new TemperatureController($client, null, $this->stateService);

        $response = $controller->refresh();

        $this->assertEquals(503, $response['status']);
        $this->assertArrayHasKey('success', $response['body']);
        $this->assertFalse($response['body']['success']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    // ==================== Refresh State Tests ====================

    /**
     * @test
     * GET /temperature should include refresh_in_progress field.
     */
    public function getIncludesRefreshInProgressField(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('refresh_in_progress', $response['body']);
        $this->assertIsBool($response['body']['refresh_in_progress']);
    }

    /**
     * @test
     * When no refresh is pending, refresh_in_progress should be false.
     */
    public function getReturnsRefreshInProgressFalseWhenNoRefreshPending(): void
    {
        $response = $this->controller->get();

        $this->assertFalse($response['body']['refresh_in_progress']);
    }

    /**
     * @test
     * When refresh was just requested, refresh_in_progress should be true.
     */
    public function getReturnsRefreshInProgressTrueWhenRefreshPending(): void
    {
        // Mark refresh as requested slightly in the future so sensor timestamp
        // (which is "now") will be older than the request, simulating a pending refresh
        $this->stateService->markRefreshRequested(new \DateTimeImmutable('+5 seconds'));

        $response = $this->controller->get();

        $this->assertTrue($response['body']['refresh_in_progress']);
    }

    /**
     * @test
     * When refresh is pending, response should include refresh_requested_at.
     */
    public function getIncludesRefreshRequestedAtWhenRefreshPending(): void
    {
        // Use future time so sensor timestamp (now) is older than request
        $requestTime = new \DateTimeImmutable('+5 seconds');
        $this->stateService->markRefreshRequested($requestTime);

        $response = $this->controller->get();

        $this->assertArrayHasKey('refresh_requested_at', $response['body']);
        $this->assertEquals($requestTime->format('c'), $response['body']['refresh_requested_at']);
    }

    /**
     * @test
     * When refresh completes (sensor timestamp newer than request),
     * refresh_in_progress should be false and state should be cleared.
     */
    public function getReturnsRefreshInProgressFalseWhenRefreshCompleted(): void
    {
        // Mark refresh requested 10 seconds ago
        $requestTime = new \DateTimeImmutable('-10 seconds');
        $this->stateService->markRefreshRequested($requestTime);

        // Stub returns current timestamp (which is newer than request)
        $response = $this->controller->get();

        $this->assertFalse($response['body']['refresh_in_progress']);

        // State should be cleared after successful refresh
        $this->assertNull($this->stateService->getState());
    }

    /**
     * @test
     * POST /refresh should store the request timestamp and return it.
     */
    public function refreshStoresRequestedAtAndReturnsIt(): void
    {
        $response = $this->controller->refresh();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('requested_at', $response['body']);

        // Verify state was stored
        $storedRequestedAt = $this->stateService->getRefreshRequestedAt();
        $this->assertNotNull($storedRequestedAt);
        $this->assertEquals($storedRequestedAt->format('c'), $response['body']['requested_at']);
    }

    // ==================== ESP32 Integration Tests ====================

    /**
     * @test
     * When ESP32 service is provided and has fresh data, use ESP32 temperatures.
     */
    public function getUsesEsp32DataWhenFreshAndAvailable(): void
    {
        // Create ESP32 services
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Configure ESP32 sensors
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $ambientAddress = '28:D5:AA:87:00:23:16:34';
        $esp32ConfigService->setSensorRole($waterAddress, 'water');
        $esp32ConfigService->setSensorRole($ambientAddress, 'ambient');

        // Store ESP32 temperature data
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],  // ESP32: 39°C water
                ['address' => $ambientAddress, 'temp_c' => 18.0],  // ESP32: 18°C ambient
            ],
            'uptime_seconds' => 3600,
        ]);

        // WirelessTag stub has different values (38°C water from setUp)
        $this->stubHttpClient->setWaterTemperature(38.0);
        $this->stubHttpClient->setAmbientTemperature(20.0);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        // Should use ESP32 values, not WirelessTag
        $this->assertEqualsWithDelta(39.0, $response['body']['water_temp_c'], 0.01);
        $this->assertEqualsWithDelta(18.0, $response['body']['ambient_temp_c'], 0.01);
        $this->assertEquals('esp32', $response['body']['source']);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * When ESP32 service has no data, fall back to WirelessTag.
     */
    public function getFallsBackToWirelessTagWhenNoEsp32Data(): void
    {
        // Create empty ESP32 services (no data stored)
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // WirelessTag has valid data
        $this->stubHttpClient->setWaterTemperature(38.0);
        $this->stubHttpClient->setAmbientTemperature(20.0);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        // Should use WirelessTag values
        $this->assertEqualsWithDelta(38.0, $response['body']['water_temp_c'], 0.5);
        $this->assertEqualsWithDelta(20.0, $response['body']['ambient_temp_c'], 0.5);
        $this->assertEquals('wirelesstag', $response['body']['source']);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * When ESP32 data exists but roles aren't assigned, fall back to WirelessTag.
     */
    public function getFallsBackToWirelessTagWhenEsp32RolesNotAssigned(): void
    {
        // Create ESP32 services
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Store ESP32 data but DON'T assign roles
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => '28:F6:DD:87:00:88:1E:E8', 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        // WirelessTag has valid data
        $this->stubHttpClient->setWaterTemperature(38.0);
        $this->stubHttpClient->setAmbientTemperature(20.0);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        // Should use WirelessTag because no ESP32 roles assigned
        $this->assertEquals('wirelesstag', $response['body']['source']);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * ESP32 calibration offsets should be applied to returned temperatures.
     */
    public function getAppliesEsp32CalibrationOffsets(): void
    {
        // Create ESP32 services
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Configure ESP32 sensors with calibration
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $esp32ConfigService->setSensorRole($waterAddress, 'water');
        $esp32ConfigService->setCalibrationOffset($waterAddress, 0.5);  // +0.5°C calibration

        // Store raw ESP32 temperature
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 38.0],  // Raw: 38°C
            ],
            'uptime_seconds' => 3600,
        ]);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        // Should return calibrated value: 38.0 + 0.5 = 38.5°C
        $this->assertEqualsWithDelta(38.5, $response['body']['water_temp_c'], 0.01);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * ESP32 response should include device metadata (uptime, device_id).
     */
    public function getIncludesEsp32Metadata(): void
    {
        // Create ESP32 services
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Configure ESP32 sensors
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $esp32ConfigService->setSensorRole($waterAddress, 'water');

        // Store ESP32 temperature data
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 7200,
        ]);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $response['body']['device_id']);
        $this->assertEquals(7200, $response['body']['uptime_seconds']);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    // ==================== Dual Source Tests ====================

    /**
     * @test
     * When both ESP32 and WirelessTag are available, return both sources.
     */
    public function getReturnsBothSourcesWhenBothAvailable(): void
    {
        // Create ESP32 services with configured sensor
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Configure ESP32 sensor
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $esp32ConfigService->setSensorRole($waterAddress, 'water');

        // Store ESP32 temperature data
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        // WirelessTag stub has different values
        $this->stubHttpClient->setWaterTemperature(38.0);
        $this->stubHttpClient->setAmbientTemperature(20.0);

        // Create controller with ESP32 service
        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);

        // Should have both sources
        $this->assertArrayHasKey('esp32', $response['body']);
        $this->assertArrayHasKey('wirelesstag', $response['body']);

        // ESP32 data
        $this->assertEqualsWithDelta(39.0, $response['body']['esp32']['water_temp_c'], 0.01);

        // WirelessTag data
        $this->assertEqualsWithDelta(38.0, $response['body']['wirelesstag']['water_temp_c'], 0.5);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * When only WirelessTag is available, esp32 should be null.
     */
    public function getAllReturnsNullEsp32WhenNotConfigured(): void
    {
        // Create ESP32 services but don't configure any sensors
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // WirelessTag has data
        $this->stubHttpClient->setWaterTemperature(38.0);
        $this->stubHttpClient->setAmbientTemperature(20.0);

        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertNull($response['body']['esp32']);
        $this->assertNotNull($response['body']['wirelesstag']);

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * When WirelessTag is not configured, it should return an error object.
     */
    public function getAllReturnsWirelessTagErrorWhenNotConfigured(): void
    {
        // Create mock factory that reports not configured
        $mockFactory = $this->createMock(\HotTub\Services\WirelessTagClientFactory::class);
        $mockFactory->method('isConfigured')->willReturn(false);
        $mockFactory->method('getConfigurationError')->willReturn('WirelessTag not configured');

        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            $mockFactory,
            $this->stateService,
            null
        );

        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertNull($response['body']['esp32']);
        $this->assertArrayHasKey('error', $response['body']['wirelesstag']);
    }

    // ==================== Timestamp Format Tests (Phase 1) ====================

    /**
     * @test
     * ESP32 response in getAll should include timestamp in ISO 8601 format.
     */
    public function getAllIncludesEsp32TimestampInIso8601Format(): void
    {
        // Create ESP32 services with configured sensor
        $esp32TempFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $esp32ConfigFile = sys_get_temp_dir() . '/test_esp32_config_' . uniqid() . '.json';

        $esp32TempService = new Esp32TemperatureService($esp32TempFile);
        $esp32ConfigService = new Esp32SensorConfigService($esp32ConfigFile);
        $esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TempService, $esp32ConfigService);

        // Configure ESP32 sensor
        $waterAddress = '28:F6:DD:87:00:88:1E:E8';
        $esp32ConfigService->setSensorRole($waterAddress, 'water');

        // Store ESP32 temperature data
        $esp32TempService->store([
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'sensors' => [
                ['address' => $waterAddress, 'temp_c' => 39.0],
            ],
            'uptime_seconds' => 3600,
        ]);

        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            $esp32CalibratedService
        );

        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertNotNull($response['body']['esp32']);
        $this->assertArrayHasKey('timestamp', $response['body']['esp32']);

        // Verify it's a valid ISO 8601 format (parseable by DateTime)
        $timestamp = $response['body']['esp32']['timestamp'];
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        $this->assertNotFalse($parsed, "ESP32 timestamp '$timestamp' should be valid ISO 8601");

        // Cleanup
        @unlink($esp32TempFile);
        @unlink($esp32ConfigFile);
    }

    /**
     * @test
     * WirelessTag response in getAll should include timestamp in ISO 8601 format.
     */
    public function getAllIncludesWirelessTagTimestampInIso8601Format(): void
    {
        $this->stubHttpClient->setWaterTemperature(38.0);

        $controller = new TemperatureController(
            new WirelessTagClient($this->stubHttpClient),
            null,
            $this->stateService,
            null
        );

        $response = $controller->getAll();

        $this->assertEquals(200, $response['status']);
        $this->assertNotNull($response['body']['wirelesstag']);
        $this->assertArrayHasKey('timestamp', $response['body']['wirelesstag']);

        // Verify it's a valid ISO 8601 format (parseable by DateTime)
        $timestamp = $response['body']['wirelesstag']['timestamp'];
        $parsed = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $timestamp);
        $this->assertNotFalse($parsed, "WirelessTag timestamp '$timestamp' should be valid ISO 8601");
    }
}
