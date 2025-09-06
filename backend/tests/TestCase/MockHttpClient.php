<?php

declare(strict_types=1);

namespace HotTubController\Tests\TestCase;

use HotTubController\Domain\Proxy\HttpResponse;
use HotTubController\Infrastructure\Http\HttpClientInterface;
use PHPUnit\Framework\Assert;

class MockHttpClient implements HttpClientInterface
{
    /** @var array<array{url: string, method: string, options: array, response: HttpResponse}> */
    private array $expectations = [];
    
    /** @var int */
    private int $currentExpectation = 0;

    public function expectRequest(string $url, string $method, array $options, HttpResponse $response): void
    {
        $this->expectations[] = [
            'url' => $url,
            'method' => $method,
            'options' => $options,
            'response' => $response,
        ];
    }

    public function request(string $url, string $method, array $options = []): HttpResponse
    {
        if ($this->currentExpectation >= count($this->expectations)) {
            Assert::fail("Unexpected HTTP request: $method $url");
        }

        $expected = $this->expectations[$this->currentExpectation];
        $this->currentExpectation++;

        Assert::assertSame($expected['url'], $url, "HTTP request URL mismatch");
        Assert::assertSame($expected['method'], $method, "HTTP request method mismatch");
        
        // Validate important options but allow flexibility
        if (isset($expected['options']['headers'])) {
            Assert::assertArrayHasKey('headers', $options);
            foreach ($expected['options']['headers'] as $key => $value) {
                Assert::assertArrayHasKey($key, $options['headers'], "Missing header: $key");
                Assert::assertSame($value, $options['headers'][$key], "Header '$key' value mismatch");
            }
        }

        if (isset($expected['options']['body'])) {
            Assert::assertArrayHasKey('body', $options);
            Assert::assertSame($expected['options']['body'], $options['body'], "Request body mismatch");
        }

        return $expected['response'];
    }

    public function assertAllExpectationsMet(): void
    {
        $expectedCount = count($this->expectations);
        $actualCount = $this->currentExpectation;
        
        Assert::assertSame(
            $expectedCount,
            $actualCount,
            "Expected $expectedCount HTTP requests, but $actualCount were made"
        );
    }

    public function reset(): void
    {
        $this->expectations = [];
        $this->currentExpectation = 0;
    }

    public function getExpectationCount(): int
    {
        return count($this->expectations);
    }

    public function getRemainingExpectations(): int
    {
        return count($this->expectations) - $this->currentExpectation;
    }
}