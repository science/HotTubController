<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Middleware;

use HotTubController\Application\Middleware\CorsMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class CorsMiddlewareTest extends TestCase
{
    private CorsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new CorsMiddleware(
            allowedOrigins: ['https://example.com', 'http://localhost:3000'],
            allowedMethods: ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            allowedHeaders: ['Content-Type', 'Authorization']
        );
    }

    public function testOptionsRequestReturnsCorrectCorsHeaders(): void
    {
        $request = $this->createRequest('OPTIONS', '/')
            ->withHeader('Origin', 'https://example.com');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertSame('https://example.com', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertSame('Content-Type, Authorization', $response->getHeaderLine('Access-Control-Allow-Headers'));
        $this->assertSame('86400', $response->getHeaderLine('Access-Control-Max-Age'));
    }

    public function testNonOptionsRequestPassesThroughWithCorsHeaders(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Origin', 'http://localhost:3000');

        $expectedResponse = new Response();
        $expectedResponse->getBody()->write('{"test": "data"}');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame('{"test": "data"}', (string) $response->getBody());
        $this->assertSame('http://localhost:3000', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertSame('GET, POST, PUT, DELETE, OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testDisallowedOriginDoesNotReceiveCorsHeaders(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Origin', 'https://malicious-site.com');

        $expectedResponse = new Response();
        $expectedResponse->getBody()->write('{"test": "data"}');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertEmpty($response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    public function testWildcardOriginAllowsAll(): void
    {
        $middleware = new CorsMiddleware(['*']);

        $request = $this->createRequest('GET', '/')
            ->withHeader('Origin', 'https://anywhere.com');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $response = $middleware->process($request, $handler);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertEmpty($response->getHeaderLine('Access-Control-Allow-Credentials'));
    }

    public function testNoOriginHeaderAllowsWildcard(): void
    {
        $request = $this->createRequest('GET', '/');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn(new Response());

        $response = $this->middleware->process($request, $handler);

        $this->assertSame('*', $response->getHeaderLine('Access-Control-Allow-Origin'));
    }

    private function createRequest(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }
}