<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\IotApiClientFactory;
use InvalidArgumentException;
use RuntimeException;

class IotApiClientFactoryTest extends TestCase
{
    private string $testLogFile;

    protected function setUp(): void
    {
        $this->testLogFile = sys_get_temp_dir() . '/iot-api-factory-test-' . uniqid() . '.log';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testLogFile)) {
            unlink($this->testLogFile);
        }
    }

    private function makeFactory(array $config): IotApiClientFactory
    {
        return new IotApiClientFactory($config, $this->testLogFile, fopen('php://memory', 'w+'));
    }

    public function testExplicitStubMode(): void
    {
        $client = $this->makeFactory([])->create('stub');
        $this->assertSame('stub', $client->getMode());
    }

    public function testAutoModeDefaultsToStub(): void
    {
        // phpunit.xml forces EXTERNAL_API_MODE=stub; with no config either,
        // fail-safe default is stub.
        $client = $this->makeFactory([])->create('auto');
        $this->assertSame('stub', $client->getMode());
    }

    public function testLiveModeRequiresUrlAndJwt(): void
    {
        $this->expectException(RuntimeException::class);
        $this->makeFactory(['IOT_API_URL' => 'https://misuse.org/iot'])->create('live');
    }

    public function testInvalidModeThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->makeFactory([])->create('production');
    }
}
