<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class AuthenticatedAction extends Action
{
    protected TokenService $tokenService;
    protected ?Token $authenticatedToken = null;

    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService
    ) {
        parent::__construct($logger);
        $this->tokenService = $tokenService;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            // Authenticate request before proceeding
            $this->authenticate($request);
            return $this->action($request, $response, $args);
        } catch (RuntimeException $e) {
            $this->logger->warning('Authentication failed', [
                'action' => static::class,
                'error' => $e->getMessage(),
                'request_uri' => (string) $request->getUri(),
                'request_method' => $request->getMethod(),
                'ip_address' => $this->getClientIpAddress($request),
            ]);

            return $this->unauthorizedResponse($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Action error', [
                'action' => static::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Internal server error', 500);
        }
    }

    protected function authenticate(ServerRequestInterface $request): void
    {
        // Extract Authorization header
        $authHeader = $request->getHeaderLine('Authorization');

        if (empty($authHeader)) {
            throw new RuntimeException('Missing Authorization header');
        }

        // Parse Bearer token
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            throw new RuntimeException('Invalid Authorization header format. Expected: Bearer <token>');
        }

        $token = $matches[1];

        // Validate token
        if (!$this->tokenService->validateToken($token)) {
            throw new RuntimeException('Invalid or expired token');
        }

        // Get the full token object for role checking
        $this->authenticatedToken = $this->tokenService->getTokenByValue($token);
        if (!$this->authenticatedToken) {
            throw new RuntimeException('Token not found');
        }

        // Update token last used timestamp
        $this->tokenService->updateTokenLastUsed($token);

        $this->logger->debug('Token validation successful', [
            'token_preview' => $this->authenticatedToken->getTokenPreview(),
            'token_name' => $this->authenticatedToken->getName(),
            'request_uri' => (string) $request->getUri(),
            'request_method' => $request->getMethod(),
        ]);
    }

    protected function getAuthenticatedToken(): ?Token
    {
        return $this->authenticatedToken;
    }

    protected function unauthorizedResponse(string $message): ResponseInterface
    {
        return $this->jsonResponse([
            'error' => [
                'type' => 'authentication_required',
                'message' => $message,
                'status_code' => 401,
            ],
            'timestamp' => (new \DateTime())->format('c'),
        ], 401);
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
