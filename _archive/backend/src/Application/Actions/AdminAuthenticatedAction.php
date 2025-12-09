<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

abstract class AdminAuthenticatedAction extends AuthenticatedAction
{
    protected function authenticate(ServerRequestInterface $request): void
    {
        // First perform standard authentication
        parent::authenticate($request);

        // At this point, $this->authenticatedToken should be set by parent::authenticate()
        if (!$this->authenticatedToken) {
            throw new RuntimeException('Authentication failed: no token available');
        }

        // Then check for admin role
        if (!$this->authenticatedToken->isAdmin()) {
            $this->logger->warning('Non-admin user attempted to access admin endpoint', [
                'action' => static::class,
                'token_name' => $this->authenticatedToken->getName(),
                'token_role' => $this->authenticatedToken->getRole(),
                'request_uri' => (string) $request->getUri(),
                'request_method' => $request->getMethod(),
            ]);

            throw new RuntimeException('Admin access required');
        }

        $this->logger->debug('Admin authentication successful', [
            'action' => static::class,
            'token_name' => $this->authenticatedToken->getName(),
            'request_uri' => (string) $request->getUri(),
            'request_method' => $request->getMethod(),
        ]);
    }
}
