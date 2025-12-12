<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Contract for user storage and authentication.
 *
 * Implementations manage user CRUD operations and password verification.
 */
interface UserRepositoryInterface
{
    /**
     * Find a user by username.
     *
     * @param string $username The username to search for
     * @return array|null User data array or null if not found
     */
    public function findByUsername(string $username): ?array;

    /**
     * List all users.
     *
     * @return array Array of user data (without password hashes)
     */
    public function list(): array;

    /**
     * Create a new user.
     *
     * @param string $username Username (must be unique)
     * @param string $password Plain-text password (will be hashed)
     * @param string $role User role ('admin' or 'user')
     * @return array Created user data
     * @throws \InvalidArgumentException If username already exists
     */
    public function create(string $username, string $password, string $role = 'user'): array;

    /**
     * Delete a user.
     *
     * @param string $username Username to delete
     * @throws \InvalidArgumentException If user not found
     */
    public function delete(string $username): void;

    /**
     * Update a user's password.
     *
     * @param string $username Username
     * @param string $newPassword New plain-text password (will be hashed)
     * @throws \InvalidArgumentException If user not found
     */
    public function updatePassword(string $username, string $newPassword): void;

    /**
     * Verify a user's password.
     *
     * @param string $username Username
     * @param string $password Plain-text password to verify
     * @return bool True if password matches, false otherwise
     */
    public function verifyPassword(string $username, string $password): bool;
}
