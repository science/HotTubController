<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration\Actions;

use HotTubController\Domain\Proxy\HttpResponse;
use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticatedTestTrait;

class ProxyRequestActionTest extends ApiTestCase
{
    use AuthenticatedTestTrait;
    protected function configureApp(): void
    {
        // Configure routes
        $routes = require __DIR__ . '/../../../config/routes.php';
        $routes($this->app);

        // Configure middleware
        $middleware = require __DIR__ . '/../../../config/middleware.php';
        $middleware($this->app);
    }

    public function testProxyRequestRequiresAuthentication(): void
    {
        $response = $this->request('POST', '/api/v1/proxy', [
            'endpoint' => 'https://httpbin.org/get',
            'method' => 'GET'
        ]);

        $this->assertAuthenticationRequired($response);
    }

    public function testProxyRequestRequiresEndpoint(): void
    {
        // Need to create a valid token first for proper authentication
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test123',
            'tk_test123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_test123', [
            'method' => 'GET'
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonError('Missing required fields: endpoint', 400, $response);
    }

    public function testProxyRequestRequiresMethod(): void
    {
        // Need to create a valid token first for proper authentication
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test456',
            'tk_test123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_test123', [
            'endpoint' => 'https://httpbin.org/get'
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonError('Missing required fields: method', 400, $response);
    }

    public function testProxyRequestRejectsInvalidToken(): void
    {
        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_invalid123', [
            'endpoint' => 'https://httpbin.org/get',
            'method' => 'GET'
        ]);

        $this->assertAuthenticationRequired($response);
    }

    public function testProxyRequestValidatesUrl(): void
    {
        // First create a valid token via the token repository
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test123',
            'tk_validtoken123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_validtoken123', [
            'endpoint' => 'not-a-valid-url',
            'method' => 'GET'
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonError('Invalid endpoint URL', 400, $response);
    }

    public function testProxyRequestValidatesHttpMethod(): void
    {
        // Create a valid token
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test123',
            'tk_validtoken123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_validtoken123', [
            'endpoint' => 'https://httpbin.org/get',
            'method' => 'INVALID'
        ]);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertJsonError('Invalid HTTP method', 400, $response);
    }

    public function testProxyRequestSuccessfullyProxiesRequest(): void
    {
        // Create a valid token
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test123',
            'tk_validtoken123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        // Set up mock HTTP client expectation
        $expectedResponse = new HttpResponse(
            200,
            '{"success": true, "data": "test response"}',
            ['Content-Type' => 'application/json']
        );

        $this->mockHttpClient->expectRequest(
            'https://api.example.com/test',
            'GET',
            ['headers' => ['Authorization' => 'Bearer test-key']],
            $expectedResponse
        );

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_validtoken123', [
            'endpoint' => 'https://api.example.com/test',
            'method' => 'GET',
            'headers' => [
                'Authorization' => 'Bearer test-key'
            ]
        ]);

        $this->assertSame(200, $response->getStatusCode());
        
        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertSame(200, $data['http_code']);
        $this->assertSame('{"success": true, "data": "test response"}', $data['data']);
        $this->assertArrayHasKey('parsed_data', $data);
        $this->assertSame(['success' => true, 'data' => 'test response'], $data['parsed_data']);
    }

    public function testProxyRequestWithPostBody(): void
    {
        // Create a valid token
        $tokenRepo = $this->container->get(\HotTubController\Domain\Token\TokenRepositoryInterface::class);
        $token = new \HotTubController\Domain\Token\Token(
            'usr_test123',
            'tk_validtoken123',
            'Test User',
            new \DateTimeImmutable(),
            true,
            null,
            'user'
        );
        $tokenRepo->save($token);

        // Set up mock HTTP client expectation
        $expectedResponse = new HttpResponse(201, '{"created": true}');

        $this->mockHttpClient->expectRequest(
            'https://api.example.com/create',
            'POST',
            [
                'headers' => ['Content-Type' => 'application/json'],
                'body' => ['name' => 'Test Item', 'value' => 123]
            ],
            $expectedResponse
        );

        $response = $this->requestWithToken('POST', '/api/v1/proxy', 'tk_validtoken123', [
            'endpoint' => 'https://api.example.com/create',
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'body' => [
                'name' => 'Test Item',
                'value' => 123
            ]
        ]);

        $this->assertSame(200, $response->getStatusCode());
        
        $data = $this->getResponseData($response);
        $this->assertTrue($data['success']);
        $this->assertSame(201, $data['http_code']);
    }
}