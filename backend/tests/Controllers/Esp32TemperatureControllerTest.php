<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\Esp32TemperatureController;
use HotTub\Services\Esp32TemperatureService;

/**
 * Unit tests for Esp32TemperatureController.
 *
 * Tests the ESP32 temperature data ingestion endpoint.
 */
class Esp32TemperatureControllerTest extends TestCase
{
    private string $storageFile;
    private Esp32TemperatureService $service;
    private Esp32TemperatureController $controller;
    private string $validApiKey = 'test-esp32-api-key-12345';

    protected function setUp(): void
    {
        $this->storageFile = sys_get_temp_dir() . '/test_esp32_temp_' . uniqid() . '.json';
        $this->service = new Esp32TemperatureService($this->storageFile);
        $this->controller = new Esp32TemperatureController($this->service, $this->validApiKey);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->storageFile)) {
            unlink($this->storageFile);
        }
    }

    // ==================== Authentication Tests ====================

    /**
     * @test
     */
    public function receiveReturns401WithoutApiKey(): void
    {
        $response = $this->controller->receive([], null);

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function receiveReturns401WithInvalidApiKey(): void
    {
        $response = $this->controller->receive([], 'wrong-api-key');

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function receiveReturns200WithValidApiKey(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 20.5,
            'temp_f' => 68.9,
            'uptime_seconds' => 3600,
        ];

        $response = $this->controller->receive($data, $this->validApiKey);

        $this->assertEquals(200, $response['status']);
    }

    // ==================== Data Validation Tests ====================

    /**
     * @test
     */
    public function receiveReturns400WithMissingRequiredFields(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            // missing temp_c and temp_f
        ];

        $response = $this->controller->receive($data, $this->validApiKey);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function receiveAcceptsValidTemperatureData(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 38.5,
            'temp_f' => 101.3,
            'uptime_seconds' => 7200,
        ];

        $response = $this->controller->receive($data, $this->validApiKey);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('ok', $response['body']['status']);
    }

    // ==================== Response Format Tests ====================

    /**
     * @test
     */
    public function receiveReturnsIntervalSecondsInResponse(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 20.5,
            'temp_f' => 68.9,
            'uptime_seconds' => 3600,
        ];

        $response = $this->controller->receive($data, $this->validApiKey);

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('interval_seconds', $response['body']);
        $this->assertIsInt($response['body']['interval_seconds']);
    }

    /**
     * @test
     */
    public function receiveReturnsDefaultIntervalOf300Seconds(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 20.5,
            'temp_f' => 68.9,
            'uptime_seconds' => 3600,
        ];

        $response = $this->controller->receive($data, $this->validApiKey);

        $this->assertEquals(300, $response['body']['interval_seconds']);
    }

    // ==================== Storage Tests ====================

    /**
     * @test
     */
    public function receiveStoresTemperatureData(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 25.5,
            'temp_f' => 77.9,
            'uptime_seconds' => 1800,
        ];

        $this->controller->receive($data, $this->validApiKey);

        $stored = $this->service->getLatest();
        $this->assertNotNull($stored);
        $this->assertEquals('AA:BB:CC:DD:EE:FF', $stored['device_id']);
        $this->assertEquals(25.5, $stored['temp_c']);
        $this->assertEquals(77.9, $stored['temp_f']);
    }

    /**
     * @test
     */
    public function receiveStoresTimestamp(): void
    {
        $data = [
            'device_id' => 'AA:BB:CC:DD:EE:FF',
            'temp_c' => 20.0,
            'temp_f' => 68.0,
            'uptime_seconds' => 100,
        ];

        $beforeTime = time();
        $this->controller->receive($data, $this->validApiKey);
        $afterTime = time();

        $stored = $this->service->getLatest();
        $this->assertArrayHasKey('timestamp', $stored);

        $storedTime = strtotime($stored['timestamp']);
        $this->assertGreaterThanOrEqual($beforeTime, $storedTime);
        $this->assertLessThanOrEqual($afterTime, $storedTime);
    }
}
