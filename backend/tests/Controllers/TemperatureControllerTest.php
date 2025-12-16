<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\TemperatureController;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\StubWirelessTagHttpClient;
use HotTub\Services\TemperatureStateService;

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
}
