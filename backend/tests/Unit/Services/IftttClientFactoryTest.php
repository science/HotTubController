<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\IftttClientFactory;
use HotTub\Services\IftttClient;
use RuntimeException;
use InvalidArgumentException;

class IftttClientFactoryTest extends TestCase
{
    private string $testLogFile;
    private ?string $originalExternalApiMode = null;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/factory-test-' . uniqid() . '.log';
        $this->originalExternalApiMode = getenv('EXTERNAL_API_MODE') ?: null;
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
        // Restore original mode
        if ($this->originalExternalApiMode !== null) {
            putenv("EXTERNAL_API_MODE={$this->originalExternalApiMode}");
        } else {
            putenv('EXTERNAL_API_MODE');
        }
    }

    public function testCreateStubModeReturnsUnifiedClientInStubMode(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory([], $this->testLogFile, $output);

        $client = $factory->create('stub');

        $this->assertInstanceOf(IftttClient::class, $client);
        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testCreateLiveModeReturnsUnifiedClientInLiveMode(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            ['IFTTT_WEBHOOK_KEY' => 'test-key'],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('live');

        $this->assertInstanceOf(IftttClient::class, $client);
        $this->assertEquals('live', $client->getMode());
        fclose($output);
    }

    public function testCreateLiveModeWithoutKeyThrowsException(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory([], $this->testLogFile, $output);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('IFTTT_WEBHOOK_KEY required');

        $factory->create('live');
        fclose($output);
    }

    public function testAutoModeInTestingEnvReturnsStub(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            [
                'APP_ENV' => 'testing',
                'IFTTT_WEBHOOK_KEY' => 'test-key',
            ],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testAutoModeWithKeyButNoExternalApiModeDefaultsToStub(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            [
                'APP_ENV' => 'production',
                'IFTTT_WEBHOOK_KEY' => 'real-key',
                // No EXTERNAL_API_MODE set - should default to stub for safety
            ],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        // New behavior: defaults to stub when EXTERNAL_API_MODE not explicitly set
        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testAutoModeWithExternalApiModeLiveReturnsLive(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            [
                'EXTERNAL_API_MODE' => 'live',
                'IFTTT_WEBHOOK_KEY' => 'real-key',
            ],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        $this->assertEquals('live', $client->getMode());
        fclose($output);
    }

    public function testAutoModeWithoutKeyReturnsStub(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            ['APP_ENV' => 'production'],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testAutoModeInDevelopmentWithoutKeyReturnsStub(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            ['APP_ENV' => 'development'],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testAutoModeInDevelopmentWithKeyButNoExternalApiModeDefaultsToStub(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            [
                'APP_ENV' => 'development',
                'IFTTT_WEBHOOK_KEY' => 'dev-key',
                // No EXTERNAL_API_MODE set - should default to stub for safety
            ],
            $this->testLogFile,
            $output
        );

        $client = $factory->create('auto');

        // New behavior: defaults to stub when EXTERNAL_API_MODE not explicitly set
        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testInvalidModeThrowsException(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory([], $this->testLogFile, $output);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid mode: invalid');

        $factory->create('invalid');
        fclose($output);
    }

    public function testDefaultModeIsAuto(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory(
            ['APP_ENV' => 'testing'],
            $this->testLogFile,
            $output
        );

        // Call without mode parameter - should default to auto
        $client = $factory->create();

        $this->assertEquals('stub', $client->getMode());
        fclose($output);
    }

    public function testFactoryLogsInitializationToConsole(): void
    {
        $output = fopen('php://memory', 'w+');
        $factory = new IftttClientFactory([], $this->testLogFile, $output);

        $factory->create('stub');

        rewind($output);
        $contents = stream_get_contents($output);

        $this->assertStringContainsString('[INIT]', $contents);
        $this->assertStringContainsString('stub', $contents);
        fclose($output);
    }

    /**
     * Test that factory works without explicit output stream.
     *
     * This is critical for web server environments where STDERR/STDOUT
     * constants are not defined (they're CLI-only).
     */
    public function testFactoryWorksWithoutOutputStreamParameter(): void
    {
        // Simulate web environment by passing null explicitly
        // (In real web environment, STDERR constant doesn't exist at all)
        $factory = new IftttClientFactory([], $this->testLogFile, null);

        // Should not throw an error
        $client = $factory->create('stub');

        $this->assertInstanceOf(IftttClient::class, $client);
        $this->assertEquals('stub', $client->getMode());
    }

    /**
     * Test that factory's default output stream is web-safe.
     *
     * The default should use fopen('php://stderr', 'w') instead of
     * the STDERR constant which is only available in CLI mode.
     */
    public function testFactoryDefaultOutputIsWebSafe(): void
    {
        // Construct without third parameter - should use web-safe default
        $factory = new IftttClientFactory([], $this->testLogFile);

        // Should not throw an error
        $client = $factory->create('stub');

        $this->assertInstanceOf(IftttClient::class, $client);
    }
}
