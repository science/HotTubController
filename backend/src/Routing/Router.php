<?php

declare(strict_types=1);

namespace HotTub\Routing;

/**
 * Simple router for registering and dispatching routes.
 */
class Router
{
    /** @var array<string, array<string, array{handler: callable, middleware: ?callable}>> Routes indexed by method then path */
    private array $routes = [];

    /**
     * Register a GET route.
     */
    public function get(string $path, callable $handler, ?callable $middleware = null): self
    {
        return $this->addRoute('GET', $path, $handler, $middleware);
    }

    /**
     * Register a POST route.
     */
    public function post(string $path, callable $handler, ?callable $middleware = null): self
    {
        return $this->addRoute('POST', $path, $handler, $middleware);
    }

    /**
     * Add a route for any HTTP method.
     */
    public function addRoute(string $method, string $path, callable $handler, ?callable $middleware = null): self
    {
        $this->routes[$method][$path] = [
            'handler' => $handler,
            'middleware' => $middleware,
        ];
        return $this;
    }

    /**
     * Dispatch a request to the appropriate handler.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function dispatch(string $method, string $path): array
    {
        if (!isset($this->routes[$method][$path])) {
            return [
                'status' => 404,
                'body' => ['error' => 'Not found'],
            ];
        }

        $route = $this->routes[$method][$path];

        // Run middleware if present
        if ($route['middleware'] !== null) {
            $middlewareResult = ($route['middleware'])();
            if ($middlewareResult !== null) {
                return $middlewareResult;
            }
        }

        return ($route['handler'])();
    }
}
