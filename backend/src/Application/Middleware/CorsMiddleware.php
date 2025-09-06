<?php

declare(strict_types=1);

namespace HotTubController\Application\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class CorsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private array $allowedOrigins = ['*'],
        private array $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
        private array $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'],
        private int $maxAge = 86400
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // Handle preflight OPTIONS request
        if ($request->getMethod() === 'OPTIONS') {
            $response = new Response();
            return $this->addCorsHeaders($response, $request);
        }

        // Process the request
        $response = $handler->handle($request);

        // Add CORS headers to the response
        return $this->addCorsHeaders($response, $request);
    }

    private function addCorsHeaders(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        
        // Determine allowed origin
        $allowedOrigin = $this->determineAllowedOrigin($origin);
        
        if ($allowedOrigin !== null) {
            $response = $response->withHeader('Access-Control-Allow-Origin', $allowedOrigin);
        }

        $response = $response
            ->withHeader('Access-Control-Allow-Methods', implode(', ', $this->allowedMethods))
            ->withHeader('Access-Control-Allow-Headers', implode(', ', $this->allowedHeaders))
            ->withHeader('Access-Control-Max-Age', (string) $this->maxAge);

        // Allow credentials if not using wildcard origin
        if ($allowedOrigin !== '*') {
            $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        }

        return $response;
    }

    private function determineAllowedOrigin(string $origin): ?string
    {
        // If no origin header, allow (this happens in non-browser environments)
        if (empty($origin)) {
            return '*';
        }

        // If wildcard is allowed, return it
        if (in_array('*', $this->allowedOrigins)) {
            return '*';
        }

        // Check if the origin is explicitly allowed
        if (in_array($origin, $this->allowedOrigins)) {
            return $origin;
        }

        // Check for pattern matches (e.g., https://*.github.io)
        foreach ($this->allowedOrigins as $allowedOrigin) {
            if (str_contains($allowedOrigin, '*')) {
                $pattern = '/^' . str_replace(['*', '.'], ['.*', '\.'], $allowedOrigin) . '$/';
                if (preg_match($pattern, $origin)) {
                    return $origin;
                }
            }
        }

        // Origin not allowed
        return null;
    }
}