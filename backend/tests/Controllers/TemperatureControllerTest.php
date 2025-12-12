<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\TemperatureController;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\StubWirelessTagHttpClient;

/**
 * Unit tests for TemperatureController.
 *
 * These tests use stub WirelessTag client for fast, reliable testing.
 */
class TemperatureControllerTest extends TestCase
{
    private TemperatureController $controller;
    private StubWirelessTagHttpClient $stubHttpClient;

    protected function setUp(): void
    {
        $this->stubHttpClient = new StubWirelessTagHttpClient();
        $client = new WirelessTagClient($this->stubHttpClient);
        $this->controller = new TemperatureController($client);
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
}
