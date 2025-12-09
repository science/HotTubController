<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\StubHttpClient;
use HotTub\Contracts\HttpClientInterface;
use HotTub\Contracts\HttpResponse;

/**
 * Tests for StubHttpClient - the mock HTTP layer for testing.
 *
 * This client simulates HTTP responses without making network calls,
 * enabling safe testing of the IFTTT integration.
 */
class StubHttpClientTest extends TestCase
{
    public function testImplementsInterface(): void
    {
        $client = new StubHttpClient();

        $this->assertInstanceOf(HttpClientInterface::class, $client);
    }

    public function testPostReturnsHttpResponse(): void
    {
        $client = new StubHttpClient();

        $response = $client->post('https://example.com/test');

        $this->assertInstanceOf(HttpResponse::class, $response);
    }

    public function testPostReturnsSuccessStatusCode(): void
    {
        $client = new StubHttpClient();

        $response = $client->post('https://example.com/test');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->isSuccess());
    }

    public function testPostReturnsSimulatedBody(): void
    {
        $client = new StubHttpClient();

        $response = $client->post('https://example.com/test');

        $this->assertStringContainsString('Congratulations', $response->getBody());
    }

    public function testPostSimulatesNetworkDelay(): void
    {
        $client = new StubHttpClient();

        $start = microtime(true);
        $client->post('https://example.com/test');
        $duration = (microtime(true) - $start) * 1000;

        // Should take at least 50ms (simulated delay)
        $this->assertGreaterThan(50, $duration);
        // But not too long (less than 200ms)
        $this->assertLessThan(200, $duration);
    }

    public function testMultiplePostsAllSucceed(): void
    {
        $client = new StubHttpClient();

        $response1 = $client->post('https://example.com/test1');
        $response2 = $client->post('https://example.com/test2');
        $response3 = $client->post('https://example.com/test3');

        $this->assertTrue($response1->isSuccess());
        $this->assertTrue($response2->isSuccess());
        $this->assertTrue($response3->isSuccess());
    }

    /**
     * Verify that different URLs don't affect the response.
     *
     * The stub ignores the URL and always returns success.
     */
    public function testPostIgnoresUrlContent(): void
    {
        $client = new StubHttpClient();

        $response1 = $client->post('https://maker.ifttt.com/trigger/heater_on/with/key/test');
        $response2 = $client->post('https://invalid-domain.example.com/should-fail');

        // Both succeed because it's a stub
        $this->assertEquals(200, $response1->getStatusCode());
        $this->assertEquals(200, $response2->getStatusCode());
    }
}
