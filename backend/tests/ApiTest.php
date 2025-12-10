<?php

declare(strict_types=1);

namespace HotTub\Tests;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\EquipmentController;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Services\IftttClient;
use HotTub\Services\StubHttpClient;
use HotTub\Services\ConsoleLogger;
use HotTub\Services\EventLogger;

class ApiTest extends TestCase
{
    private string $testLogFile;
    private EquipmentController $controller;
    private IftttClientInterface $iftttClient;
    /** @var resource */
    private $consoleOutput;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/hot-tub-api-test-' . uniqid() . '.log';
        $this->consoleOutput = fopen('php://memory', 'w+');

        $this->iftttClient = new IftttClient(
            'test-api-key',
            new StubHttpClient(),
            new ConsoleLogger($this->consoleOutput),
            new EventLogger($this->testLogFile)
        );

        $this->controller = new EquipmentController(
            $this->testLogFile,
            $this->iftttClient
        );
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
        if (is_resource($this->consoleOutput)) {
            fclose($this->consoleOutput);
        }
    }

    public function testHealthEndpointReturnsOk(): void
    {
        $response = $this->controller->health();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('ok', $response['body']['status']);
    }

    public function testHeaterOnTriggersIftttAndReturnsSuccess(): void
    {
        $response = $this->controller->heaterOn();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('heater_on', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify IFTTT was triggered (stub logs to console)
        rewind($this->consoleOutput);
        $consoleContents = stream_get_contents($this->consoleOutput);
        $this->assertStringContainsString('[STUB]', $consoleContents);
        $this->assertStringContainsString('hot-tub-heat-on', $consoleContents);
    }

    public function testHeaterOffTriggersIftttAndReturnsSuccess(): void
    {
        $response = $this->controller->heaterOff();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('heater_off', $response['body']['action']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify IFTTT was triggered
        rewind($this->consoleOutput);
        $consoleContents = stream_get_contents($this->consoleOutput);
        $this->assertStringContainsString('hot-tub-heat-off', $consoleContents);
    }

    public function testPumpRunTriggersIftttAndReturnsSuccess(): void
    {
        $response = $this->controller->pumpRun();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertEquals('pump_run', $response['body']['action']);
        $this->assertEquals(7200, $response['body']['duration']);
        $this->assertArrayHasKey('timestamp', $response['body']);

        // Verify IFTTT was triggered
        rewind($this->consoleOutput);
        $consoleContents = stream_get_contents($this->consoleOutput);
        $this->assertStringContainsString('cycle_hot_tub_ionizer', $consoleContents);
    }

    public function testHeaterOnLogsToEventLog(): void
    {
        $this->controller->heaterOn();

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_on', $contents);
    }

    public function testHeaterOffLogsToEventLog(): void
    {
        $this->controller->heaterOff();

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_off', $contents);
    }

    public function testPumpRunLogsToEventLog(): void
    {
        $this->controller->pumpRun();

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('pump_run', $contents);
    }

    public function testHealthEndpointIncludesIftttMode(): void
    {
        $response = $this->controller->health();

        $this->assertArrayHasKey('ifttt_mode', $response['body']);
        $this->assertEquals('stub', $response['body']['ifttt_mode']);
    }
}
