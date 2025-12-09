<?php

declare(strict_types=1);

namespace HotTubController\Domain\Token;

interface TokenRepositoryInterface
{
    /**
     * Find a token by its token string
     */
    public function findByToken(string $token): ?Token;

    /**
     * Find a token by its ID
     */
    public function findById(string $id): ?Token;

    /**
     * Get all tokens
     *
     * @return Token[]
     */
    public function findAll(): array;

    /**
     * Save a token (create or update)
     */
    public function save(Token $token): void;

    /**
     * Delete a token by ID
     */
    public function deleteById(string $id): bool;

    /**
     * Check if a token exists and is active
     */
    public function isValidToken(string $token): bool;

    /**
     * Update the last used timestamp for a token
     */
    public function updateLastUsed(string $token): void;
}
