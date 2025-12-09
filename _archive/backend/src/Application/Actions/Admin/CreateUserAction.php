<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\AdminAuthenticatedAction;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class CreateUserAction extends AdminAuthenticatedAction
{
    public function __construct(
        LoggerInterface $logger,
        protected TokenService $tokenService
    ) {
        parent::__construct($logger, $tokenService);
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->getJsonInput($request);

        // Validate required fields
        $missing = $this->validateRequired($input, ['name']);
        if (!empty($missing)) {
            return $this->errorResponse('Missing required fields: ' . implode(', ', $missing), 400);
        }

        // Validate name
        $name = trim($input['name']);
        if (strlen($name) < 1 || strlen($name) > 50) {
            return $this->errorResponse('Name must be between 1 and 50 characters', 400);
        }

        // Get role from input (admin can create both user and admin tokens)
        $role = $input['role'] ?? 'user';
        if (!in_array($role, ['user', 'admin'])) {
            return $this->errorResponse('Invalid role. Must be "user" or "admin"', 400);
        }

        try {
            // Create new token with specified role
            $token = $this->tokenService->createToken($name, $role);

            $this->logger->info('New user token created', [
                'user_id' => $token->getId(),
                'name' => $token->getName(),
                'role' => $token->getRole(),
                'token_preview' => $token->getTokenPreview()
            ]);

            return $this->jsonResponse([
                'token' => $token->getToken(),
                'user_id' => $token->getId(),
                'role' => $token->getRole(),
                'created' => $token->getCreated()->format('c')
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create user token', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to create user token', 500);
        }
    }
}
