<?php

declare(strict_types=1);

namespace HotTubController\Application\Middleware;

use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;
use RuntimeException;

class TokenValidationMiddleware implements MiddlewareInterface
{
    private TokenService $tokenService;
    private LoggerInterface $logger;
    
    public function __construct(
        TokenService $tokenService,
        LoggerInterface $logger
    ) {
        $this->tokenService = $tokenService;
        $this->logger = $logger;
    }
    
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            // Extract Authorization header
            $authHeader = $request->getHeaderLine('Authorization');
            
            if (empty($authHeader)) {
                return $this->createUnauthorizedResponse('Missing Authorization header');
            }
            
            // Parse Bearer token
            if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
                return $this->createUnauthorizedResponse('Invalid Authorization header format. Expected: Bearer <token>');
            }
            
            $token = $matches[1];
            
            // Validate token
            if (!$this->tokenService->validateToken($token)) {
                $this->logger->warning('Invalid token used in API request', [
                    'token_preview' => substr($token, 0, 10) . '...',
                    'request_uri' => (string) $request->getUri(),
                    'request_method' => $request->getMethod(),
                    'user_agent' => $request->getHeaderLine('User-Agent'),
                    'ip_address' => $this->getClientIpAddress($request),
                ]);
                
                return $this->createUnauthorizedResponse('Invalid or expired token');
            }
            
            // Update token last used timestamp
            $this->tokenService->updateTokenLastUsed($token);
            
            $this->logger->debug('Token validation successful', [
                'token_preview' => substr($token, 0, 10) . '...',
                'request_uri' => (string) $request->getUri(),
                'request_method' => $request->getMethod(),
            ]);
            
            // Continue to the next middleware/handler
            return $handler->handle($request);
            
        } catch (RuntimeException $e) {
            $this->logger->error('Token validation middleware error', [
                'error' => $e->getMessage(),
                'request_uri' => (string) $request->getUri(),
                'request_method' => $request->getMethod(),
            ]);
            
            return $this->createUnauthorizedResponse('Authentication error');
            
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error in token validation middleware', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_uri' => (string) $request->getUri(),
                'request_method' => $request->getMethod(),
            ]);
            
            return $this->createErrorResponse('Internal server error', 500);
        }
    }
    
    private function createUnauthorizedResponse(string $message): ResponseInterface
    {
        $response = new Response(401);
        $response->getBody()->write(json_encode([
            'error' => [
                'type' => 'authentication_required',
                'message' => $message,
                'status_code' => 401,
            ],
            'timestamp' => (new \DateTime())->format('c'),
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    private function createErrorResponse(string $message, int $statusCode): ResponseInterface
    {
        $response = new Response($statusCode);
        $response->getBody()->write(json_encode([
            'error' => [
                'type' => 'server_error',
                'message' => $message,
                'status_code' => $statusCode,
            ],
            'timestamp' => (new \DateTime())->format('c'),
        ], JSON_PRETTY_PRINT));
        
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    private function getClientIpAddress(ServerRequestInterface $request): string
    {
        // Check for IP address from various headers (proxy-aware)
        $headers = [
            'HTTP_CF_CONNECTING_IP',     // Cloudflare
            'HTTP_X_REAL_IP',            // Nginx proxy
            'HTTP_X_FORWARDED_FOR',      // Standard forwarded header
            'HTTP_CLIENT_IP',            // Proxy header
            'REMOTE_ADDR',               // Standard CGI variable
        ];
        
        $serverParams = $request->getServerParams();
        
        foreach ($headers as $header) {
            if (!empty($serverParams[$header])) {
                $ips = explode(',', $serverParams[$header]);
                $ip = trim($ips[0]);
                
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        
        // Fallback to REMOTE_ADDR even if it's private
        return $serverParams['REMOTE_ADDR'] ?? 'unknown';
    }
}