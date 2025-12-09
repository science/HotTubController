<?php

declare(strict_types=1);

namespace HotTub\Tests;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\EquipmentController;

class ApiTest extends TestCase
{
    private string $testLogFile;
    private EquipmentController $controller;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/hot-tub-api-test-' . uniqid() . '.log';
        $this->controller = new EquipmentController($this->testLogFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    public function testHealthEndpointReturnsOk(): void
    {
        $response = $this->controller->health();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(['status' => 'ok'], $response['body']);
    }

    public function testHeaterOnLogsEventAndReturnsSuccess(): void
    {
        $response = $this->controller->heaterOn();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('heater_on', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify event was logged
        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_on', $contents);
    }

    public function testHeaterOffLogsEventAndReturnsSuccess(): void
    {
        $response = $this->controller->heaterOff();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('heater_off', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify event was logged
        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_off', $contents);
    }

    public function testPumpRunLogsEventWithDurationAndReturnsSuccess(): void
    {
        $response = $this->controller->pumpRun();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('pump_run', $response['body']['action']);
        $this->assertEquals(7200, $response['body']['duration']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify event was logged with duration
        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('pump_run', $contents);
        $this->assertStringContainsString('7200', $contents);
    }
}
