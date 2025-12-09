<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\ConsoleLogger;

class ConsoleLoggerTest extends TestCase
{
    public function testStubMessageContainsStubPrefix(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->stub('test_event', 100);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[STUB]', $contents);
        fclose($output);
    }

    public function testStubMessageContainsEventName(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->stub('heater_on', 100);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('heater_on', $contents);
        fclose($output);
    }

    public function testStubMessageContainsDuration(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->stub('test_event', 150);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('150ms', $contents);
        fclose($output);
    }

    public function testStubMessageIndicatesSimulated(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->stub('test_event', 100);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('simulated', $contents);
        fclose($output);
    }

    public function testLiveMessageContainsLivePrefix(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->live('test_event', 200, 250);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[LIVE]', $contents);
        fclose($output);
    }

    public function testLiveMessageContainsEventName(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->live('heater_off', 200, 250);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('heater_off', $contents);
        fclose($output);
    }

    public function testLiveMessageContainsHttpStatus(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->live('test_event', 201, 250);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('HTTP 201', $contents);
        fclose($output);
    }

    public function testLiveMessageContainsDuration(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->live('test_event', 200, 342);

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('342ms', $contents);
        fclose($output);
    }

    public function testInitMessageContainsPrefix(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->init('stub');

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[INIT]', $contents);
        fclose($output);
    }

    public function testInitMessageContainsMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $logger = new ConsoleLogger($output);

        $logger->init('live');

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('live', $contents);
        fclose($output);
    }

    public function testDefaultsToStdoutWhenNoOutputProvided(): void
    {
        // Just verify it can be instantiated without output
        $logger = new ConsoleLogger();
        $this->assertInstanceOf(ConsoleLogger::class, $logger);
    }
}
