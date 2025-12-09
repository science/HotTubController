<?php

declare(strict_types=1);

namespace HotTub\Tests;

use PHPUnit\Framework\TestCase;
use HotTub\Services\EventLogger;

class EventLoggerTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/hot-tub-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    public function testLogWritesEventToFile(): void
    {
        $logger = new EventLogger($this->testLogFile);

        $logger->log('heater_on');

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_on', $contents);
    }

    public function testLogIncludesTimestamp(): void
    {
        $logger = new EventLogger($this->testLogFile);

        $logger->log('heater_off');

        $contents = file_get_contents($this->testLogFile);
        // Should contain ISO8601-ish timestamp (YYYY-MM-DD)
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $contents);
    }

    public function testLogAppendsMultipleEvents(): void
    {
        $logger = new EventLogger($this->testLogFile);

        $logger->log('heater_on');
        $logger->log('pump_run');

        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('heater_on', $contents);
        $this->assertStringContainsString('pump_run', $contents);
    }

    public function testLogIncludesOptionalData(): void
    {
        $logger = new EventLogger($this->testLogFile);

        $logger->log('pump_run', ['duration' => 7200]);

        $contents = file_get_contents($this->testLogFile);
        $this->assertStringContainsString('7200', $contents);
    }
}
