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
}
