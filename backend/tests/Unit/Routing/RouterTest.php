<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Routing;

use PHPUnit\Framework\TestCase;
use HotTub\Routing\Router;

class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testCanRegisterGetRoute(): void
    {
        $handler = fn() => ['status' => 200, 'body' => ['ok' => true]];

        $this->router->get('/api/health', $handler);

        $result = $this->router->dispatch('GET', '/api/health');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['ok']);
    }

    public function testCanRegisterPostRoute(): void
    {
        $handler = fn() => ['status' => 200, 'body' => ['created' => true]];

        $this->router->post('/api/resource', $handler);

        $result = $this->router->dispatch('POST', '/api/resource');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['created']);
    }

    public function testReturns404ForUnknownRoute(): void
    {
        $result = $this->router->dispatch('GET', '/unknown');

        $this->assertSame(404, $result['status']);
        $this->assertSame('Not found', $result['body']['error']);
    }

    public function testReturns404ForWrongMethod(): void
    {
        $handler = fn() => ['status' => 200, 'body' => []];

        $this->router->get('/api/health', $handler);

        // POST to a GET route should 404
        $result = $this->router->dispatch('POST', '/api/health');

        $this->assertSame(404, $result['status']);
    }

    public function testCanRegisterMultipleRoutes(): void
    {
        $this->router->get('/api/health', fn() => ['status' => 200, 'body' => ['route' => 'health']]);
        $this->router->post('/api/login', fn() => ['status' => 200, 'body' => ['route' => 'login']]);
        $this->router->post('/api/logout', fn() => ['status' => 200, 'body' => ['route' => 'logout']]);

        $healthResult = $this->router->dispatch('GET', '/api/health');
        $loginResult = $this->router->dispatch('POST', '/api/login');
        $logoutResult = $this->router->dispatch('POST', '/api/logout');

        $this->assertSame('health', $healthResult['body']['route']);
        $this->assertSame('login', $loginResult['body']['route']);
        $this->assertSame('logout', $logoutResult['body']['route']);
    }

    public function testHandlerCanBeCallableArray(): void
    {
        $controller = new class {
            public function index(): array
            {
                return ['status' => 200, 'body' => ['from' => 'controller']];
            }
        };

        $this->router->get('/api/test', [$controller, 'index']);

        $result = $this->router->dispatch('GET', '/api/test');

        $this->assertSame('controller', $result['body']['from']);
    }

    public function testRouteWithMiddlewareThatPasses(): void
    {
        // Middleware that passes (returns null)
        $middleware = fn() => null;
        $handler = fn() => ['status' => 200, 'body' => ['reached' => true]];

        $this->router->post('/api/protected', $handler, $middleware);

        $result = $this->router->dispatch('POST', '/api/protected');

        $this->assertSame(200, $result['status']);
        $this->assertTrue($result['body']['reached']);
    }

    public function testRouteWithMiddlewareThatBlocks(): void
    {
        // Middleware that blocks (returns a response)
        $middleware = fn() => ['status' => 401, 'body' => ['error' => 'Unauthorized']];
        $handler = fn() => ['status' => 200, 'body' => ['reached' => true]];

        $this->router->post('/api/protected', $handler, $middleware);

        $result = $this->router->dispatch('POST', '/api/protected');

        $this->assertSame(401, $result['status']);
        $this->assertSame('Unauthorized', $result['body']['error']);
    }

    public function testDynamicRouteWithParameter(): void
    {
        $handler = fn(array $params) => [
            'status' => 200,
            'body' => ['id' => $params['id']],
        ];

        $this->router->get('/api/items/{id}', $handler);

        $result = $this->router->dispatch('GET', '/api/items/123');

        $this->assertSame(200, $result['status']);
        $this->assertSame('123', $result['body']['id']);
    }

    public function testDynamicRouteDecodesUrlEncodedParameters(): void
    {
        // This tests the bug where sensor addresses like "28:F6:DD:87:00:88:1E:E8"
        // get URL-encoded to "28%3AF6%3ADD%3A87%3A00%3A88%3A1E%3AE8" in the URL
        $handler = fn(array $params) => [
            'status' => 200,
            'body' => ['address' => $params['address']],
        ];

        $this->router->put('/api/sensors/{address}', $handler);

        // Dispatch with URL-encoded address (as browser would send)
        $result = $this->router->dispatch('PUT', '/api/sensors/28%3AF6%3ADD%3A87%3A00%3A88%3A1E%3AE8');

        $this->assertSame(200, $result['status']);
        // The handler should receive the DECODED address
        $this->assertSame('28:F6:DD:87:00:88:1E:E8', $result['body']['address']);
    }

    public function testDynamicRouteWithMultipleParameters(): void
    {
        $handler = fn(array $params) => [
            'status' => 200,
            'body' => ['user' => $params['user'], 'post' => $params['post']],
        ];

        $this->router->get('/api/users/{user}/posts/{post}', $handler);

        $result = $this->router->dispatch('GET', '/api/users/john/posts/456');

        $this->assertSame(200, $result['status']);
        $this->assertSame('john', $result['body']['user']);
        $this->assertSame('456', $result['body']['post']);
    }
}
