<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Auth;

use HotTubController\Application\Actions\Action;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

class AuthenticateAction extends Action
{
    public function __construct(
        LoggerInterface $logger,
        private string $masterPasswordHash
    ) {
        parent::__construct($logger);
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $input = $this->getJsonInput($request);

        $missing = $this->validateRequired($input, ['password']);
        if (!empty($missing)) {
            return $this->errorResponse('Password required', 400);
        }

        if (password_verify($input['password'], $this->masterPasswordHash)) {
            $this->logger->info('Master authentication successful');

            return $this->jsonResponse([
                'authenticated' => true,
                'message' => 'Master authentication successful'
            ]);
        }

        $this->logger->warning('Failed authentication attempt');
        return $this->errorResponse('Invalid password', 401);
    }
}
