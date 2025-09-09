<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Proxy;

use HotTubController\Application\Actions\AuthenticatedAction;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Infrastructure\Http\HttpClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class ProxyRequestAction extends AuthenticatedAction
{
    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        private HttpClientInterface $httpClient
    ) {
        parent::__construct($logger, $tokenService);
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->getJsonInput($request);
        
        // Validate required fields (token no longer required since we use header auth)
        $missing = $this->validateRequired($input, ['endpoint', 'method']);
        if (!empty($missing)) {
            return $this->errorResponse('Missing required fields: ' . implode(', ', $missing), 400);
        }

        // Authentication is handled by parent class AuthenticatedAction

        // Validate URL
        if (!filter_var($input['endpoint'], FILTER_VALIDATE_URL)) {
            return $this->errorResponse('Invalid endpoint URL', 400);
        }

        // Validate HTTP method
        $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH'];
        $method = strtoupper($input['method']);
        if (!in_array($method, $allowedMethods)) {
            return $this->errorResponse('Invalid HTTP method', 400);
        }

        $this->logger->info('Proxying request', [
            'endpoint' => $input['endpoint'],
            'method' => $method,
            'token_name' => $this->getAuthenticatedToken()->getName()
        ]);

        // Prepare request options
        $options = [];
        
        if (isset($input['headers']) && is_array($input['headers'])) {
            $options['headers'] = $input['headers'];
        }

        if (isset($input['body'])) {
            $options['body'] = $input['body'];
        }

        // Make the proxied request
        try {
            $proxiedResponse = $this->httpClient->request(
                $input['endpoint'],
                $method,
                $options
            );

            $this->logger->info('Proxy request completed', [
                'endpoint' => $input['endpoint'],
                'status_code' => $proxiedResponse->getStatusCode(),
                'success' => $proxiedResponse->isSuccessful()
            ]);

            return $this->jsonResponse($proxiedResponse->toArray());

        } catch (\Throwable $e) {
            $this->logger->error('Proxy request failed', [
                'endpoint' => $input['endpoint'],
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Proxy request failed: ' . $e->getMessage(), 500);
        }
    }
}