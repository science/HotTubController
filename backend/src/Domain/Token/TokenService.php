<?php

declare(strict_types=1);

namespace HotTubController\Domain\Token;

use DateTimeImmutable;

class TokenService
{
    public function __construct(
        private TokenRepositoryInterface $tokenRepository
    ) {}

    public function createToken(string $name): Token
    {
        $id = 'usr_' . bin2hex(random_bytes(3));
        $token = 'tk_' . bin2hex(random_bytes(8));
        
        $tokenEntity = new Token(
            $id,
            $token,
            $name,
            new DateTimeImmutable()
        );
        
        $this->tokenRepository->save($tokenEntity);
        
        return $tokenEntity;
    }

    public function validateToken(string $token): bool
    {
        return $this->tokenRepository->isValidToken($token);
    }

    public function updateTokenLastUsed(string $token): void
    {
        $this->tokenRepository->updateLastUsed($token);
    }

    public function deactivateToken(string $id): bool
    {
        $token = $this->tokenRepository->findById($id);
        if (!$token) {
            return false;
        }

        $deactivatedToken = $token->deactivate();
        $this->tokenRepository->save($deactivatedToken);
        
        return true;
    }

    /**
     * @return Token[]
     */
    public function getAllTokens(): array
    {
        return $this->tokenRepository->findAll();
    }

    public function getTokenById(string $id): ?Token
    {
        return $this->tokenRepository->findById($id);
    }

    public function deleteToken(string $id): bool
    {
        return $this->tokenRepository->deleteById($id);
    }
}