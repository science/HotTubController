<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HotTub\Services\WirelessTagClient;
use HotTub\Services\StubWirelessTagHttpClient;

/**
 * Tests for WirelessTagClient::requestRefresh() method.
 *
 * This method triggers a fresh temperature reading from the hardware sensor
 * via the WirelessTag API's RequestImmediatePostback endpoint.
 */
class WirelessTagClientRefreshTest extends TestCase
{
    /**
     * @test
     * requestRefresh() should call the RequestImmediatePostback endpoint
     * and return true on success.
     */
    public function requestRefreshReturnsTrueOnSuccess(): void
    {
        $httpClient = new StubWirelessTagHttpClient();
        $client = new WirelessTagClient($httpClient);

        $result = $client->requestRefresh('0');

        $this->assertTrue($result);
    }

    /**
     * @test
     * requestRefresh() should return false on API failure.
     */
    public function requestRefreshReturnsFalseOnFailure(): void
    {
        // Create a mock that throws an exception
        $httpClient = $this->createMock(StubWirelessTagHttpClient::class);
        $httpClient->method('post')
            ->willThrowException(new \RuntimeException('API error'));

        $client = new WirelessTagClient($httpClient);

        $result = $client->requestRefresh('0');

        $this->assertFalse($result);
    }
}
