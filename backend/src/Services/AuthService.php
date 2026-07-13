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
            $claims = (array) $decoded;
        } catch (\Exception $e) {
            throw new \RuntimeException('Invalid token: ' . $e->getMessage());
        }

        // DB-backed validation: the token's subject must still resolve to a
        // user, and its claimed role must match that user's current role. This
        // makes the user store authoritative — deleting a user (or changing its
        // role) immediately invalidates its outstanding tokens, even tokens the
        // backend was never told were minted.
        $user = $this->userRepository->findByUsername($claims['sub'] ?? '');

        if ($user === null) {
            throw new \RuntimeException('Invalid token: unknown subject');
        }

        if (($user['role'] ?? null) !== ($claims['role'] ?? null)) {
            throw new \RuntimeException('Invalid token: role mismatch');
        }

        // The token must postdate the user row. This makes delete-then-recreate
        // a real revocation: tokens minted for the deleted account carry an iat
        // older than the recreated row's created_at and stay dead forever.
        // (Password changes deliberately do NOT revoke tokens — delete/recreate
        // is this app's revocation mechanism.) Rows without created_at (legacy)
        // can't anchor the check and are accepted.
        $createdAt = isset($user['created_at']) ? strtotime($user['created_at']) : false;
        if ($createdAt !== false) {
            if (!isset($claims['iat'])) {
                throw new \RuntimeException('Invalid token: missing iat');
            }
            if ((int) $claims['iat'] < $createdAt) {
                throw new \RuntimeException('Invalid token: predates the user account');
            }
        }

        return $claims;
    }
}
