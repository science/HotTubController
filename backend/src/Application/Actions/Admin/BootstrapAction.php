<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\Action;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class BootstrapAction extends Action
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
        $input = $this->getJsonInput($request);

        // Validate required fields
        $missing = $this->validateRequired($input, ['master_password', 'name']);
        if (!empty($missing)) {
            return $this->errorResponse('Missing required fields: ' . implode(', ', $missing), 400);
        }

        // Verify master password
        if (!password_verify($input['master_password'], $this->masterPasswordHash)) {
            $this->logger->warning('Failed admin authentication attempt for bootstrap token creation');
            return $this->errorResponse('Invalid master password', 401);
        }

        // Validate name
        $name = trim($input['name']);
        if (strlen($name) < 1 || strlen($name) > 50) {
            return $this->errorResponse('Name must be between 1 and 50 characters', 400);
        }

        try {
            // Create first admin token - this is used to bootstrap the system
            $token = $this->tokenService->createToken($name, 'admin');

            $this->logger->info('Bootstrap admin token created', [
                'user_id' => $token->getId(),
                'name' => $token->getName(),
                'role' => $token->getRole(),
                'token_preview' => $token->getTokenPreview()
            ]);

            return $this->jsonResponse([
                'token' => $token->getToken(),
                'user_id' => $token->getId(),
                'role' => $token->getRole(),
                'created' => $token->getCreated()->format('c'),
                'message' => 'Bootstrap admin token created. Use this token for all subsequent admin operations.'
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create bootstrap admin token', [
                'name' => $name,
                'error' => $e->getMessage()
            ]);

            return $this->errorResponse('Failed to create bootstrap admin token', 500);
        }
    }
}
