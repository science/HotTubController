<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\AuthService;

class AuthController
{
    public function __construct(
        private AuthService $authService
    ) {
    }

    public function login(string $username, string $password): array
    {
        try {
            $token = $this->authService->login($username, $password);
            $claims = $this->authService->validateToken($token);

            return [
                'status' => 200,
                'body' => [
                    'token' => $token,
                    'user' => [
                        'username' => $claims['sub'],
                        'role' => $claims['role'],
                    ],
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 401,
                'body' => [
                    'error' => 'Invalid credentials',
                ],
            ];
        }
    }

    public function me(string $token): array
    {
        try {
            $claims = $this->authService->validateToken($token);

            return [
                'status' => 200,
                'body' => [
                    'user' => [
                        'username' => $claims['sub'],
                        'role' => $claims['role'],
                    ],
                ],
            ];
        } catch (\RuntimeException $e) {
            return [
                'status' => 401,
                'body' => [
                    'error' => 'Invalid or expired token',
                ],
            ];
        }
    }

    public function logout(): array
    {
        return [
            'status' => 200,
            'body' => [
                'success' => true,
            ],
        ];
    }
}
