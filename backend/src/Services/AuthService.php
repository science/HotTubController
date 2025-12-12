<?php

declare(strict_types=1);

namespace HotTub\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use HotTub\Contracts\UserRepositoryInterface;

class AuthService
{
    private UserRepositoryInterface $userRepository;
    private string $jwtSecret;
    private int $expiryHours;

    public function __construct(UserRepositoryInterface $userRepository, array $config)
    {
        $this->userRepository = $userRepository;
        $this->jwtSecret = $config['JWT_SECRET'] ?? '';
        $this->expiryHours = (int) ($config['JWT_EXPIRY_HOURS'] ?? 24);
    }

    public function login(string $username, string $password): string
    {
        if (!$this->userRepository->verifyPassword($username, $password)) {
            throw new \InvalidArgumentException('Invalid credentials');
        }

        $user = $this->userRepository->findByUsername($username);

        $issuedAt = time();
        $expiresAt = $issuedAt + ($this->expiryHours * 3600);

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expiresAt,
            'sub' => $username,
            'role' => $user['role'],
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
