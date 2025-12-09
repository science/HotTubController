<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\AdminAuthenticatedAction;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class ListUsersAction extends AdminAuthenticatedAction
{
    public function __construct(
        LoggerInterface $logger,
        protected TokenService $tokenService
    ) {
        parent::__construct($logger, $tokenService);
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $tokens = $this->tokenService->getAllTokens();

            // Convert tokens to safe public format
            $users = array_map(function ($token) {
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
