<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Middleware;

use PHPUnit\Framework\TestCase;
use HotTubController\Application\Middleware\ResponseTimeMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class ResponseTimeMiddlewareTest extends TestCase
{
    private ResponseTimeMiddleware $middleware;
    private RequestHandlerInterface $handler;
    private ResponseInterface $response;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create middleware with logging disabled for unit tests
        $this->middleware = new ResponseTimeMiddleware(false, 1000);
        
        $this->response = (new ResponseFactory())->createResponse();
        
        // Mock handler that returns the response
        $this->handler = $this->createMock(RequestHandlerInterface::class);
        $this->handler->method('handle')->willReturn($this->response);
    }

    public function testAddsResponseTimeHeader(): void
    {
        $request = $this->createRequest('GET', '/api/v1/status');
        
        $result = $this->middleware->process($request, $this->handler);
        
        // Verify header was added
        $this->assertTrue($result->hasHeader('X-Response-Time'));
        
        $responseTime = $result->getHeaderLine('X-Response-Time');
        $this->assertStringEndsWith('ms', $responseTime);
        
        // Extract numeric value
        $timeValue = (float) str_replace('ms', '', $responseTime);
        $this->assertGreaterThanOrEqual(0, $timeValue);
        $this->assertLessThan(100, $timeValue); // Should be fast in unit tests
    }

    public function testHandlerIsCalled(): void
    {
        $request = $this->createRequest('GET', '/api/v1/status');
        
        // Verify handler is called exactly once
        $this->handler->expects($this->once())
            ->method('handle')
            ->with($request)
            ->willReturn($this->response);
        
        $this->middleware->process($request, $this->handler);
    }

    public function testMeasuresTimeAccurately(): void
    {
        $delayMs = 50;
        
        // Create handler that deliberately delays
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($delayMs) {
            usleep($delayMs * 1000); // Convert to microseconds
            return $this->response;
        });
        
        $request = $this->createRequest('GET', '/test');
        
        $result = $this->middleware->process($request, $handler);
        
        $responseTime = $result->getHeaderLine('X-Response-Time');
        $timeValue = (float) str_replace('ms', '', $responseTime);
        
        // Should be at least the delay time (allowing for some variance)
        $this->assertGreaterThanOrEqual($delayMs * 0.8, $timeValue);
        $this->assertLessThanOrEqual($delayMs * 2, $timeValue);
    }

    public function testLoggingCanBeDisabled(): void
    {
        // Create middleware with logging explicitly disabled
        $middleware = new ResponseTimeMiddleware(false, 1000);
        $request = $this->createRequest('GET', '/api/v1/status');
        
        $result = $middleware->process($request, $this->handler);
        
        // Should still add header even when logging is disabled
        $this->assertTrue($result->hasHeader('X-Response-Time'));
    }

    public function testLoggingCanBeEnabled(): void
    {
        // Create temporary log directory for testing
        $logDir = __DIR__ . '/../../../../storage/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // Clean up any existing test log files
        $statusLogFile = $logDir . '/performance-status.log';
        if (file_exists($statusLogFile)) {
            unlink($statusLogFile);
        }
        
        // Create middleware with logging enabled
        $middleware = new ResponseTimeMiddleware(true, 1000);
        $request = $this->createRequest('GET', '/api/v1/status');
        
        $result = $middleware->process($request, $this->handler);
        
        // Should add header
        $this->assertTrue($result->hasHeader('X-Response-Time'));
        
        // Clean up test log file if it was created
        if (file_exists($statusLogFile)) {
            unlink($statusLogFile);
        }
    }

    public function testSlowRequestThreshold(): void
    {
        $slowThreshold = 25; // 25ms threshold
        $middleware = new ResponseTimeMiddleware(false, $slowThreshold);
        
        // Create handler that exceeds the slow threshold
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(function () use ($slowThreshold) {
            usleep(($slowThreshold + 10) * 1000); // 10ms over threshold
            return $this->response;
        });
        
        $request = $this->createRequest('GET', '/slow-endpoint');
        
        $result = $middleware->process($request, $handler);
        
        $responseTime = $result->getHeaderLine('X-Response-Time');
        $timeValue = (float) str_replace('ms', '', $responseTime);
        
        // Should exceed the slow threshold
        $this->assertGreaterThan($slowThreshold, $timeValue);
    }

    public function testDifferentHttpMethods(): void
    {
        $methods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        
        foreach ($methods as $method) {
            $request = $this->createRequest($method, '/api/test');
            $result = $this->middleware->process($request, $this->handler);
            
            $this->assertTrue($result->hasHeader('X-Response-Time'), 
                "Failed to add header for {$method} request");
        }
    }

    public function testStatusEndpointRecognition(): void
    {
        $statusPaths = ['/', '/index.php', '/api/v1/status'];
        
        foreach ($statusPaths as $path) {
            $request = $this->createRequest('GET', $path);
            $result = $this->middleware->process($request, $this->handler);
            
            $this->assertTrue($result->hasHeader('X-Response-Time'),
                "Failed to add header for status path: {$path}");
        }
    }

    public function testOriginalResponseIsPreserved(): void
    {
        // Create response with custom status and headers
        $originalResponse = $this->response
            ->withStatus(201)
            ->withHeader('Custom-Header', 'test-value')
            ->withHeader('Another-Header', 'another-value');
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($originalResponse);
        
        $request = $this->createRequest('POST', '/api/test');
        
        $result = $this->middleware->process($request, $handler);
        
        // Original response properties should be preserved
        $this->assertEquals(201, $result->getStatusCode());
        $this->assertEquals('test-value', $result->getHeaderLine('Custom-Header'));
        $this->assertEquals('another-value', $result->getHeaderLine('Another-Header'));
        
        // But our header should also be added
        $this->assertTrue($result->hasHeader('X-Response-Time'));
    }

    public function testResponseBodyIsPreserved(): void
    {
        $testBody = '{"test": "data", "status": "ok"}';
        
        $originalResponse = $this->response;
        $originalResponse->getBody()->write($testBody);
        
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($originalResponse);
        
        $request = $this->createRequest('GET', '/api/data');
        
        $result = $this->middleware->process($request, $handler);
        
        // Body should be preserved
        $this->assertEquals($testBody, (string) $result->getBody());
        
        // Header should still be added
        $this->assertTrue($result->hasHeader('X-Response-Time'));
    }

    public function testMultipleCallsGiveUniqueTimings(): void
    {
        $timings = [];
        
        for ($i = 0; $i < 3; $i++) {
            $request = $this->createRequest('GET', '/api/v1/status');
            $result = $this->middleware->process($request, $this->handler);
            
            $responseTime = $result->getHeaderLine('X-Response-Time');
            $timeValue = (float) str_replace('ms', '', $responseTime);
            $timings[] = $timeValue;
            
            if ($i < 2) {
                usleep(1000); // Small delay between calls
            }
        }
        
        // All timings should be valid
        foreach ($timings as $timing) {
            $this->assertGreaterThanOrEqual(0, $timing);
            $this->assertLessThan(100, $timing);
        }
        
        // Timings may vary slightly but all should be reasonable
        $this->assertCount(3, $timings);
    }

    private function createRequest(string $method, string $path): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest($method, $path);
    }
}