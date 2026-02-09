<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Controllers\TargetTemperatureController;
use HotTub\Services\TargetTemperatureService;
use PHPUnit\Framework\TestCase;

class TargetTemperatureControllerTest extends TestCase
{
    private string $stateFile;
    private TargetTemperatureService $service;

    protected function setUp(): void
    {
        $this->stateFile = sys_get_temp_dir() . '/target-temp-ctrl-test-' . uniqid() . '.json';
        $this->service = new TargetTemperatureService($this->stateFile);
    }

    public function testStartReturns200AndStartsHeatingToTarget(): void
    {
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->start(['target_temp_f' => 103.5]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['active']);
        $this->assertEquals(103.5, $response['body']['target_temp_f']);
    }

    public function testStartReturns400WhenTargetTempMissing(): void
    {
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->start([]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('target_temp_f', $response['body']['error']);
    }

    public function testStartReturns400WhenTargetTempOutOfRange(): void
    {
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->start(['target_temp_f' => 120.0]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('between', $response['body']['error']);
    }

    public function testStatusReturnsCurrentState(): void
    {
        $this->service->start(103.5);
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->status();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['active']);
        $this->assertEquals(103.5, $response['body']['target_temp_f']);
    }

    public function testStatusReturnsInactiveWhenNotHeating(): void
    {
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->status();

        $this->assertEquals(200, $response['status']);
        $this->assertFalse($response['body']['active']);
    }

    public function testCancelStopsHeatingAndReturns200(): void
    {
        $this->service->start(103.5);
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->cancel();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);

        $state = $this->service->getState();
        $this->assertFalse($state['active']);
    }

    public function testCheckCallsCheckAndAdjust(): void
    {
        $controller = new TargetTemperatureController($this->service);

        $response = $controller->check();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('active', $response['body']);
    }

    public function testStartReturns409WhenAlreadyActive(): void
    {
        $controller = new TargetTemperatureController($this->service);

        // First start succeeds
        $response1 = $controller->start(['target_temp_f' => 103.5]);
        $this->assertEquals(200, $response1['status']);

        // Second start returns 409
        $response2 = $controller->start(['target_temp_f' => 103.5]);
        $this->assertEquals(409, $response2['status']);
        $this->assertStringContainsString('already active', $response2['body']['error']);
    }

    protected function tearDown(): void
    {
        // Clean up lock file too
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }
    }
}
