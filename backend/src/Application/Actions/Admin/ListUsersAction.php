<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\Action;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class ListUsersAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private TokenService $tokenService,
        private string $masterPasswordHash
    ) {
        parent::__construct($logger);
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        // Get master password from query parameters (for GET request)
        $queryParams = $request->getQueryParams();
        $masterPassword = $queryParams['master_password'] ?? null;

        if (!$masterPassword) {
            return $this->errorResponse('Master password required', 400);
        }

        // Verify master password
        if (!password_verify($masterPassword, $this->masterPasswordHash)) {
            $this->logger->warning('Failed admin authentication attempt for user list');
            return $this->errorResponse('Invalid master password', 401);
        }

        try {
            $tokens = $this->tokenService->getAllTokens();

            // Convert tokens to safe public format
            $users = array_map(function($token) {
                return [
                    'id' => $token->getId(),
                    'name' => $token->getName(),
                    'role' => $token->getRole(),
                    'created' => $token->getCreated()->format('c'),
                    'active' => $token->isActive(),
                    'last_used' => $token->getLastUsed()?->format('c'),
                    'token_preview' => $token->getTokenPreview()
                ];
            }, $tokens);

            $this->logger->info('Admin user list requested', [
                'user_count' => count($users)
            ]);

            return $this->jsonResponse(['users' => $users]);

        } catch (\Throwable $e) {
            $this->logger->error('Failed to retrieve user list', [
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to retrieve user list', 500);
        }
    }
}