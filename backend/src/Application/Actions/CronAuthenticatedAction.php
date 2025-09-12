<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use HotTubController\Services\CronSecurityManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

abstract class CronAuthenticatedAction extends Action
{
    protected CronSecurityManager $securityManager;

    public function __construct(
        LoggerInterface $logger,
        CronSecurityManager $securityManager
    ) {
        parent::__construct($logger);
        $this->securityManager = $securityManager;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            // Authenticate cron request before proceeding
            $this->authenticateCronRequest($request);
            return $this->action($request, $response, $args);
        } catch (RuntimeException $e) {
            $this->logger->warning('Cron authentication failed', [
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

    protected function authenticateCronRequest(ServerRequestInterface $request): void
    {
        // Get auth parameter from request body
        $input = $this->getJsonInput($request);
        $providedAuth = $input['auth'] ?? null;

        if (empty($providedAuth)) {
            throw new RuntimeException('Missing authentication parameter');
        }

        if (!$this->securityManager->verifyApiKey($providedAuth)) {
            $this->logger->warning('Invalid cron API key provided', [
                'action' => static::class,
                'provided_key_preview' => substr($providedAuth, 0, 10) . '...',
                'request_uri' => (string) $request->getUri(),
            ]);
            throw new RuntimeException('Invalid authentication key');
        }

        $this->logger->debug('Cron authentication successful', [
            'action' => static::class,
            'request_uri' => (string) $request->getUri(),
            'request_method' => $request->getMethod(),
        ]);
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
