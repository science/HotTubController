<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use HotTub\Services\HealthchecksClient;
use HotTub\Services\NullHealthchecksClient;
use HotTub\Services\HealthchecksClientFactory;
use HotTub\Contracts\HealthchecksClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Healthchecks.io client classes.
 *
 * These tests verify the client behavior without making real API calls.
 */
class HealthchecksClientTest extends TestCase
{
    private ?string $originalExternalApiMode = null;

    protected function setUp(): void
    {
        $this->originalExternalApiMode = getenv('EXTERNAL_API_MODE') ?: null;
    }

    protected function tearDown(): void
    {
        // Restore original mode
        if ($this->originalExternalApiMode !== null) {
            putenv("EXTERNAL_API_MODE={$this->originalExternalApiMode}");
        } else {
            putenv('EXTERNAL_API_MODE');
        }
    }

    // =========================================================================
    // NullHealthchecksClient Tests (feature disabled)
    // =========================================================================

    public function testNullClientIsDisabled(): void
    {
        $client = new NullHealthchecksClient();
        $this->assertFalse($client->isEnabled());
    }

    public function testNullClientCreateCheckReturnsNull(): void
    {
        $client = new NullHealthchecksClient();
        $result = $client->createCheck('test-job', '* * * * *', 'UTC', 60);
        $this->assertNull($result);
    }

    public function testNullClientPingReturnsTrue(): void
    {
        $client = new NullHealthchecksClient();
        $result = $client->ping('https://hc-ping.com/fake-uuid');
        $this->assertTrue($result);
    }

    public function testNullClientDeleteReturnsTrue(): void
    {
        $client = new NullHealthchecksClient();
        $result = $client->delete('fake-uuid');
        $this->assertTrue($result);
    }

    public function testNullClientGetCheckReturnsNull(): void
    {
        $client = new NullHealthchecksClient();
        $result = $client->getCheck('fake-uuid');
        $this->assertNull($result);
    }

    // =========================================================================
    // Factory Tests
    // =========================================================================

    public function testFactoryReturnsNullClientWhenNoApiKey(): void
    {
        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => null,
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(NullHealthchecksClient::class, $client);
        $this->assertFalse($client->isEnabled());
    }

    public function testFactoryReturnsNullClientWhenEmptyApiKey(): void
    {
        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => '',
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(NullHealthchecksClient::class, $client);
    }

    public function testFactoryReturnsRealClientWhenApiKeyPresent(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(HealthchecksClient::class, $client);
        $this->assertTrue($client->isEnabled());
    }

    public function testFactoryCanOverrideChannelId(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
            'HEALTHCHECKS_IO_CHANNEL' => 'custom-channel-uuid',
        ]);

        $client = $factory->create();

        // The channel should be stored in the client
        $this->assertInstanceOf(HealthchecksClient::class, $client);
    }

    /**
     * @test
     * EXTERNAL_API_MODE=stub should force NullHealthchecksClient even with API key.
     */
    public function testFactoryReturnsNullClientWhenExternalApiModeIsStub(): void
    {
        $factory = new HealthchecksClientFactory([
            'EXTERNAL_API_MODE' => 'stub',
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(NullHealthchecksClient::class, $client);
        $this->assertFalse($client->isEnabled());
    }

    /**
     * @test
     * EXTERNAL_API_MODE=live should use real client when API key present.
     */
    public function testFactoryReturnsRealClientWhenExternalApiModeIsLive(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check

        $factory = new HealthchecksClientFactory([
            'EXTERNAL_API_MODE' => 'live',
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(HealthchecksClient::class, $client);
        $this->assertTrue($client->isEnabled());
    }

    // =========================================================================
    // HealthchecksClient Unit Tests (with mocked HTTP)
    // =========================================================================

    public function testClientIsEnabled(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check
        $client = new HealthchecksClient('test-api-key');
        $this->assertTrue($client->isEnabled());
    }

    public function testClientImplementsInterface(): void
    {
        putenv('EXTERNAL_API_MODE=live');  // Set env for tripwire check
        $client = new HealthchecksClient('test-api-key');
        $this->assertInstanceOf(HealthchecksClientInterface::class, $client);
    }
}
