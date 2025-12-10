<?php

declare(strict_types=1);

namespace HotTub\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    private string $adminUsername;
    private string $adminPassword;
    private string $jwtSecret;
    private int $expiryHours;

    public function __construct(array $config)
    {
        $this->adminUsername = $config['AUTH_ADMIN_USERNAME'] ?? '';
        $this->adminPassword = $config['AUTH_ADMIN_PASSWORD'] ?? '';
        $this->jwtSecret = $config['JWT_SECRET'] ?? '';
        $this->expiryHours = (int) ($config['JWT_EXPIRY_HOURS'] ?? 24);
    }

    public function login(string $username, string $password): string
    {
        if ($username !== $this->adminUsername || $password !== $this->adminPassword) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + ($this->expiryHours * 3600);

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'sub' => $username,
            'role' => 'admin',
        ];

        return JWT::encode($payload, $this->jwtSecret, 'HS256');
    }

    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));
            return (array) $decoded;
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid token: ' . $e->getMessage());
        }
    }
}
