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

    // ── Per-job heat mode pass-through ────────────────────────────────────────
    //
    // The job's stored params land here verbatim as the request body (cron-runner.sh
    // POSTs the params object), so `dynamic` must survive as a TRI-state: present →
    // decide for this heat, absent → inherit the global default.

    public function testStartPassesDynamicTrueToTheService(): void
    {
        $service = $this->createMock(TargetTemperatureService::class);
        $service->expects($this->once())
            ->method('start')
            ->with(103.5, true)
            ->willReturn(['active' => true]);

        (new TargetTemperatureController($service))->start([
            'target_temp_f' => 103.5,
            'dynamic' => true,
        ]);
    }

    public function testStartPassesDynamicFalseToTheService(): void
    {
        $service = $this->createMock(TargetTemperatureService::class);
        $service->expects($this->once())
            ->method('start')
            ->with(103.5, false)
            ->willReturn(['active' => true]);

        (new TargetTemperatureController($service))->start([
            'target_temp_f' => 103.5,
            'dynamic' => false,
        ]);
    }

    public function testStartPassesNullWhenDynamicIsAbsent(): void
    {
        $service = $this->createMock(TargetTemperatureService::class);
        $service->expects($this->once())
            ->method('start')
            ->with(103.5, null)
            ->willReturn(['active' => true]);

        (new TargetTemperatureController($service))->start(['target_temp_f' => 103.5]);
    }

    /** A null value must collapse to "inherit", not to false (isset() would get this wrong). */
    public function testStartPassesNullWhenDynamicIsExplicitlyNull(): void
    {
        $service = $this->createMock(TargetTemperatureService::class);
        $service->expects($this->once())
            ->method('start')
            ->with(103.5, null)
            ->willReturn(['active' => true]);

        (new TargetTemperatureController($service))->start([
            'target_temp_f' => 103.5,
            'dynamic' => null,
        ]);
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
