<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Middleware;

use PHPUnit\Framework\TestCase;
use HotTub\Middleware\CorsMiddleware;

class CorsMiddlewareTest extends TestCase
{
    private array $sentHeaders = [];

    protected function setUp(): void
    {
        $this->sentHeaders = [];
    }

    private function createMiddleware(array $allowedOrigins): CorsMiddleware
    {
        // Use a header sender that captures headers for testing
        return new CorsMiddleware($allowedOrigins, function (string $header) {
            $this->sentHeaders[] = $header;
        });
    }

    public function testSetsAllowedOriginHeader(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173', 'https://example.com']);

        $middleware->handle('http://localhost:5173', 'GET');

        $this->assertContains('Access-Control-Allow-Origin: http://localhost:5173', $this->sentHeaders);
    }

    public function testSetsStandardCorsHeaders(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173']);

        $middleware->handle('http://localhost:5173', 'GET');

        $this->assertContains('Content-Type: application/json', $this->sentHeaders);
        $this->assertContains('Access-Control-Allow-Methods: GET, POST, OPTIONS', $this->sentHeaders);
        $this->assertContains('Access-Control-Allow-Headers: Content-Type, Authorization', $this->sentHeaders);
        $this->assertContains('Access-Control-Allow-Credentials: true', $this->sentHeaders);
    }

    public function testRejectsUnknownOrigin(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173']);

        $middleware->handle('http://evil.com', 'GET');

        // Should not set the evil origin
        $this->assertNotContains('Access-Control-Allow-Origin: http://evil.com', $this->sentHeaders);
    }

    public function testHandlesEmptyOriginAsSameOrigin(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173', 'https://misuse.org']);

        $middleware->handle('', 'GET');

        // Empty origin (same-origin request) should use default
        $originHeaders = array_filter($this->sentHeaders, fn($h) => str_starts_with($h, 'Access-Control-Allow-Origin'));
        $this->assertNotEmpty($originHeaders);
    }

    public function testPreflightReturns204(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173']);

        $result = $middleware->handle('http://localhost:5173', 'OPTIONS');

        // Preflight should return a response to short-circuit
        $this->assertIsArray($result);
        $this->assertSame(204, $result['status']);
        $this->assertSame('', $result['body']);
    }

    public function testNonPreflightReturnsNull(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173']);

        $result = $middleware->handle('http://localhost:5173', 'GET');

        // Non-preflight should return null to continue to handler
        $this->assertNull($result);
    }

    public function testPreflightWithDifferentOrigins(): void
    {
        $middleware = $this->createMiddleware(['http://localhost:5173', 'https://misuse.org']);

        $result = $middleware->handle('https://misuse.org', 'OPTIONS');

        $this->assertSame(204, $result['status']);
        $this->assertContains('Access-Control-Allow-Origin: https://misuse.org', $this->sentHeaders);
    }
}
