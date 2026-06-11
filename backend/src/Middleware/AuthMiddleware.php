<?php

declare(strict_types=1);

namespace HotTub\Middleware;

use HotTub\Services\AuthService;

class AuthMiddleware
{
    /**
     * Roles permitted to perform mutating (write) operations. Any other role
     * (notably 'readonly') is denied write access. Layer-1 validation in
     * AuthService guarantees this role matches the user's current DB role.
     */
    private const WRITE_ROLES = ['admin', 'user', 'basic'];

    public function __construct(
        private AuthService $authService
    ) {
    }

    public function authenticate(array $headers, array $cookies): ?array
    {
        $token = $this->extractToken($headers, $cookies);

        if ($token === null) {
            return null;
        }

        try {
            return $this->authService->validateToken($token);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public function requireAuth(array $headers, array $cookies): ?array
    {
        $user = $this->authenticate($headers, $cookies);

        if ($user === null) {
            return [
                'status' => 401,
                'body' => [
                    'error' => 'Authentication required',
                ],
            ];
        }

        return null;
    }

    public function requireAdmin(array $headers, array $cookies): ?array
    {
        $user = $this->authenticate($headers, $cookies);

        if ($user === null) {
            return [
                'status' => 401,
                'body' => [
                    'error' => 'Authentication required',
                ],
            ];
        }

        if (($user['role'] ?? '') !== 'admin') {
            return [
                'status' => 403,
                'body' => [
                    'error' => 'Admin access required',
                ],
            ];
        }

        return null;
    }

    public function requireWrite(array $headers, array $cookies): ?array
    {
        $user = $this->authenticate($headers, $cookies);

        if ($user === null) {
            return [
                'status' => 401,
                'body' => [
                    'error' => 'Authentication required',
                ],
            ];
        }

        if (!in_array($user['role'] ?? '', self::WRITE_ROLES, true)) {
            return [
                'status' => 403,
                'body' => [
                    'error' => 'Write access required',
                ],
            ];
        }

        return null;
    }

    private function extractToken(array $headers, array $cookies): ?string
    {
        // Check Authorization header first
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (str_starts_with($auth, 'Bearer ')) {
                return substr($auth, 7);
            }
            return null;
        }

        // Fall back to cookie
        if (isset($cookies['auth_token'])) {
            return $cookies['auth_token'];
        }

        return null;
    }
}
