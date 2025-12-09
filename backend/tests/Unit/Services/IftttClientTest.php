<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\IftttClient;
use HotTub\Services\ConsoleLogger;
use HotTub\Services\EventLogger;
use HotTub\Services\StubHttpClient;
use HotTub\Services\CurlHttpClient;
use HotTub\Contracts\IftttClientInterface;
use HotTub\Contracts\HttpClientInterface;
use HotTub\Contracts\HttpResponse;

/**
 * Tests for the unified IftttClient with late-binding HTTP strategy.
 *
 * This test suite verifies that the same client code works correctly
 * with both stub and live HTTP clients - demonstrating the strategy pattern.
 */
class IftttClientTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/ifttt-client-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    public function testImplementsInterface(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $this->assertInstanceOf(IftttClientInterface::class, $client);
        fclose($output);
    }

    public function testGetModeReturnsStubWhenUsingStubHttpClient(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testGetModeReturnsLiveWhenUsingRealHttpClient(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = $this->createMock(HttpClientInterface::class);

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        // Any non-StubHttpClient is considered "live"
        $this->assertEquals('live', $client->getMode());
        fclose($output);
    }

    public function testTriggerReturnsTrueOnSuccess(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $result = $client->trigger('heater_on');

        $this->assertTrue($result);
        fclose($output);
    }

    public function testTriggerReturnsFalseOnHttpError(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(new HttpResponse(500));

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $result = $client->trigger('heater_on');

        $this->assertFalse($result);
        fclose($output);
    }

    public function testTriggerBuildsCorrectUrl(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = $this->createMock(HttpClientInterface::class);

        $httpClient->expects($this->once())
            ->method('post')
            ->with('https://maker.ifttt.com/trigger/my-event/with/key/my-secret-key')
            ->willReturn(new HttpResponse(200));

        $client = new IftttClient(
            'my-secret-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $client->trigger('my-event');
        fclose($output);
    }

    public function testTriggerLogsToConsoleInStubMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $client->trigger('heater_on');

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[STUB]', $contents);
        $this->assertStringContainsString('heater_on', $contents);
        fclose($output);
    }

    public function testTriggerLogsToConsoleInLiveMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(new HttpResponse(200));

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $client->trigger('heater_on');

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[LIVE]', $contents);
        $this->assertStringContainsString('heater_on', $contents);
        $this->assertStringContainsString('HTTP 200', $contents);
        fclose($output);
    }

    public function testTriggerLogsToEventLogInStubMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $client->trigger('pump_run');

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);

        $this->assertStringContainsString('ifttt_stub', $contents);
        $this->assertStringContainsString('pump_run', $contents);
        $this->assertStringContainsString('simulated', $contents);
        fclose($output);
    }

    public function testTriggerLogsToEventLogInLiveMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(new HttpResponse(200));

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $client->trigger('pump_run');

        $this->assertFileExists($this->testLogFile);
        $contents = file_get_contents($this->testLogFile);

        $this->assertStringContainsString('ifttt_live', $contents);
        $this->assertStringContainsString('pump_run', $contents);
        $this->assertStringContainsString('200', $contents);
        fclose($output);
    }

    public function testMultipleTriggersAllSucceedInStubMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $this->assertTrue($client->trigger('heater_on'));
        $this->assertTrue($client->trigger('heater_off'));
        $this->assertTrue($client->trigger('pump_run'));

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('heater_on', $contents);
        $this->assertStringContainsString('heater_off', $contents);
        $this->assertStringContainsString('pump_run', $contents);
        fclose($output);
    }

    /**
     * Test that stub mode simulates realistic delay.
     *
     * This ensures tests behave similarly to live mode timing-wise.
     */
    public function testStubModeSimulatesDelay(): void
    {
        $output = fopen('php://memory', 'w+');
        $httpClient = new StubHttpClient();

        $client = new IftttClient(
            'test-api-key',
            $httpClient,
            new ConsoleLogger($output),
            new EventLogger($this->testLogFile)
        );

        $start = microtime(true);
        $client->trigger('heater_on');
        $duration = (microtime(true) - $start) * 1000;

        // Should take at least 50ms (simulated delay)
        $this->assertGreaterThan(50, $duration);
        // But not too long (less than 300ms)
        $this->assertLessThan(300, $duration);
        fclose($output);
    }

    /**
     * Test that both modes execute the same code path (except HTTP).
     *
     * This verifies the late-binding strategy pattern is working correctly.
     */
    public function testBothModesProduceSimilarLogStructure(): void
    {
        // Create stub client
        $stubOutput = fopen('php://memory', 'w+');
        $stubLogFile = sys_get_temp_dir() . '/stub-' . uniqid() . '.log';
        $stubClient = new IftttClient(
            'test-key',
            new StubHttpClient(),
            new ConsoleLogger($stubOutput),
            new EventLogger($stubLogFile)
        );

        // Create mock "live" client
        $liveOutput = fopen('php://memory', 'w+');
        $liveLogFile = sys_get_temp_dir() . '/live-' . uniqid() . '.log';
        $liveHttpClient = $this->createMock(HttpClientInterface::class);
        $liveHttpClient->method('post')->willReturn(new HttpResponse(200));
        $liveClient = new IftttClient(
            'test-key',
            $liveHttpClient,
            new ConsoleLogger($liveOutput),
            new EventLogger($liveLogFile)
        );

        // Trigger same event on both
        $stubClient->trigger('heater_on');
        $liveClient->trigger('heater_on');

        // Both should have logged to their respective files
        $this->assertFileExists($stubLogFile);
        $this->assertFileExists($liveLogFile);

        // Both logs should contain the event name
        $stubLogContents = file_get_contents($stubLogFile);
        $liveLogContents = file_get_contents($liveLogFile);

        $this->assertStringContainsString('heater_on', $stubLogContents);
        $this->assertStringContainsString('heater_on', $liveLogContents);

        // Both should contain duration_ms (proving same code path)
        $this->assertStringContainsString('duration_ms', $stubLogContents);
        $this->assertStringContainsString('duration_ms', $liveLogContents);

        // Cleanup
        fclose($stubOutput);
        fclose($liveOutput);
        unlink($stubLogFile);
        unlink($liveLogFile);
    }
}
