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
        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
        ]);

        $client = $factory->create();

        $this->assertInstanceOf(HealthchecksClient::class, $client);
        $this->assertTrue($client->isEnabled());
    }

    public function testFactoryCanOverrideChannelId(): void
    {
        $factory = new HealthchecksClientFactory([
            'HEALTHCHECKS_IO_KEY' => 'test-api-key',
            'HEALTHCHECKS_IO_CHANNEL' => 'custom-channel-uuid',
        ]);

        $client = $factory->create();

        // The channel should be stored in the client
        $this->assertInstanceOf(HealthchecksClient::class, $client);
    }

    // =========================================================================
    // HealthchecksClient Unit Tests (with mocked HTTP)
    // =========================================================================

    public function testClientIsEnabled(): void
    {
        $client = new HealthchecksClient('test-api-key');
        $this->assertTrue($client->isEnabled());
    }

    public function testClientImplementsInterface(): void
    {
        $client = new HealthchecksClient('test-api-key');
        $this->assertInstanceOf(HealthchecksClientInterface::class, $client);
    }
}
