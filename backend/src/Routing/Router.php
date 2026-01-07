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
     * Register a DELETE route.
     */
    public function delete(string $path, callable $handler, ?callable $middleware = null): self
    {
        return $this->addRoute('DELETE', $path, $handler, $middleware);
    }

    /**
     * Register a PUT route.
     */
    public function put(string $path, callable $handler, ?callable $middleware = null): self
    {
        return $this->addRoute('PUT', $path, $handler, $middleware);
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
        // Try exact match first
        if (isset($this->routes[$method][$path])) {
            return $this->executeRoute($this->routes[$method][$path], []);
        }

        // Try pattern matching for dynamic routes
        if (isset($this->routes[$method])) {
            foreach ($this->routes[$method] as $pattern => $route) {
                $params = $this->matchRoute($pattern, $path);
                if ($params !== null) {
                    return $this->executeRoute($route, $params);
                }
            }
        }

        return [
            'status' => 404,
            'body' => ['error' => 'Not found'],
        ];
    }

    /**
     * Execute a matched route.
     *
     * @param array{handler: callable, middleware: ?callable} $route
     * @param array<string, string> $params
     * @return array{status: int, body: array<string, mixed>}
     */
    private function executeRoute(array $route, array $params): array
    {
        // Run middleware if present
        if ($route['middleware'] !== null) {
            $middlewareResult = ($route['middleware'])();
            if ($middlewareResult !== null) {
                return $middlewareResult;
            }
        }

        // Call handler with params if any
        if (empty($params)) {
            return ($route['handler'])();
        }

        return ($route['handler'])($params);
    }

    /**
     * Match a route pattern against a path.
     * Supports {param} placeholders.
     *
     * @return array<string, string>|null Params if matched, null otherwise
     */
    private function matchRoute(string $pattern, string $path): ?array
    {
        // Skip if pattern has no placeholders
        if (!str_contains($pattern, '{')) {
            return null;
        }

        // Convert pattern to regex: /api/schedule/{id} -> /api/schedule/([^/]+)
        $regex = preg_replace('/\{([^}]+)\}/', '([^/]+)', $pattern);
        $regex = '#^' . $regex . '$#';

        if (!preg_match($regex, $path, $matches)) {
            return null;
        }

        // Extract parameter names from pattern
        preg_match_all('/\{([^}]+)\}/', $pattern, $paramNames);

        // Build params array (URL-decode values since they come from the URL)
        $params = [];
        foreach ($paramNames[1] as $index => $name) {
            $params[$name] = urldecode($matches[$index + 1]);
        }

        return $params;
    }
}
