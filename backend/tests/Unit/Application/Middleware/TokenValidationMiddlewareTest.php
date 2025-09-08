<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Middleware;

use HotTubController\Application\Middleware\TokenValidationMiddleware;
use HotTubController\Domain\Token\TokenService;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;

class TokenValidationMiddlewareTest extends TestCase
{
    private TokenValidationMiddleware $middleware;
    private TokenService $tokenService;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->tokenService = $this->createMock(TokenService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        
        $this->middleware = new TokenValidationMiddleware(
            $this->tokenService,
            $this->logger
        );
    }

    public function testValidTokenPassesThrough(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer valid-token-123');

        $expectedResponse = new Response();
        $expectedResponse->getBody()->write('{"success": true}');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('valid-token-123')
            ->willReturn(true);

        $this->tokenService->expects($this->once())
            ->method('updateTokenLastUsed')
            ->with('valid-token-123');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($expectedResponse);

        $response = $this->middleware->process($request, $handler);

        $this->assertSame($expectedResponse, $response);
    }

    public function testMissingAuthorizationHeaderReturns401(): void
    {
        $request = $this->createRequest('GET', '/api/test');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));
        
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('authentication_required', $responseBody['error']['type']);
        $this->assertStringContainsString('Missing Authorization header', $responseBody['error']['message']);
    }

    public function testInvalidAuthorizationFormatReturns401(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Basic invalid-format');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
        
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('authentication_required', $responseBody['error']['type']);
        $this->assertStringContainsString('Invalid Authorization header format', $responseBody['error']['message']);
    }

    public function testInvalidTokenReturns401(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer invalid-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('invalid-token')
            ->willReturn(false);

        $this->tokenService->expects($this->never())
            ->method('updateTokenLastUsed');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                'Invalid token used in API request',
                $this->callback(function ($context) {
                    return isset($context['token_preview']) &&
                           isset($context['request_uri']) &&
                           isset($context['request_method']) &&
                           str_starts_with($context['token_preview'], 'invalid-to');
                })
            );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
        
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('authentication_required', $responseBody['error']['type']);
        $this->assertStringContainsString('Invalid or expired token', $responseBody['error']['message']);
    }

    public function testTokenServiceExceptionReturns401(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer test-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('test-token')
            ->willThrowException(new \RuntimeException('Database connection failed'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Token validation middleware error',
                $this->callback(function ($context) {
                    return isset($context['error']) &&
                           $context['error'] === 'Database connection failed';
                })
            );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(401, $response->getStatusCode());
        
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('authentication_required', $responseBody['error']['type']);
        $this->assertStringContainsString('Authentication error', $responseBody['error']['message']);
    }

    public function testUnexpectedExceptionReturns500(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'Bearer test-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willThrowException(new \Exception('Unexpected system error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with(
                'Unexpected error in token validation middleware',
                $this->callback(function ($context) {
                    return isset($context['error']) && isset($context['trace']);
                })
            );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(500, $response->getStatusCode());
        
        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertEquals('server_error', $responseBody['error']['type']);
        $this->assertStringContainsString('Internal server error', $responseBody['error']['message']);
    }

    public function testBearerTokenIsCaseInsensitive(): void
    {
        $request = $this->createRequest('GET', '/api/test')
            ->withHeader('Authorization', 'bearer lowercase-token');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->with('lowercase-token')
            ->willReturn(true);

        $this->tokenService->expects($this->once())
            ->method('updateTokenLastUsed')
            ->with('lowercase-token');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn(new Response());

        $response = $this->middleware->process($request, $handler);

        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDebugLoggingOnSuccessfulValidation(): void
    {
        $request = $this->createRequest('POST', '/api/schedule-heating')
            ->withHeader('Authorization', 'Bearer debug-token-12345');

        $this->tokenService->expects($this->once())
            ->method('validateToken')
            ->willReturn(true);

        $this->logger->expects($this->once())
            ->method('debug')
            ->with(
                'Token validation successful',
                $this->callback(function ($context) {
                    return $context['token_preview'] === 'debug-toke...' &&
                           $context['request_method'] === 'POST';
                })
            );

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->once())
            ->method('handle')
            ->willReturn(new Response());

        $this->middleware->process($request, $handler);
    }

    public function testResponseContainsTimestamp(): void
    {
        $request = $this->createRequest('GET', '/api/test');

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->expects($this->never())->method('handle');

        $response = $this->middleware->process($request, $handler);

        $responseBody = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('timestamp', $responseBody);
        
        // Verify timestamp is a valid ISO 8601 format
        $timestamp = \DateTime::createFromFormat(\DateTime::ATOM, $responseBody['timestamp']);
        $this->assertInstanceOf(\DateTime::class, $timestamp);
    }

    private function createRequest(string $method, string $uri): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $uri);
    }
}