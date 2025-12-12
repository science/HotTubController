<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Contracts\UserRepositoryInterface;

class UserController
{
    private UserRepositoryInterface $userRepository;

    public function __construct(UserRepositoryInterface $userRepository)
    {
        $this->userRepository = $userRepository;
    }

    /**
     * List all users.
     */
    public function list(): array
    {
        $users = $this->userRepository->list();

        return [
            'status' => 200,
            'body' => ['users' => $users],
        ];
    }

    /**
     * Create a new user.
     */
    public function create(array $data): array
    {
        $username = $data['username'] ?? '';
        $password = $data['password'] ?? '';
        $role = $data['role'] ?? 'user';

        if (empty($username)) {
            return [
                'status' => 400,
                'body' => ['error' => 'Username is required'],
            ];
        }

        if (empty($password)) {
            return [
                'status' => 400,
                'body' => ['error' => 'Password is required'],
            ];
        }

        try {
            $user = $this->userRepository->create($username, $password, $role);

            return [
                'status' => 201,
                'body' => [
                    'username' => $username,
                    'password' => $password, // Return plain password for sharing
                    'role' => $user['role'],
                    'message' => 'Share these credentials with the user',
                ],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 409,
                'body' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * Delete a user.
     */
    public function delete(string $username): array
    {
        try {
            $this->userRepository->delete($username);

            return [
                'status' => 200,
                'body' => ['success' => true],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 404,
                'body' => ['error' => 'User not found: ' . $username],
            ];
        }
    }

    /**
     * Update a user's password.
     */
    public function updatePassword(string $username, array $data): array
    {
        $password = $data['password'] ?? '';

        if (empty($password)) {
            return [
                'status' => 400,
                'body' => ['error' => 'Password is required'],
            ];
        }

        try {
            $this->userRepository->updatePassword($username, $password);

            return [
                'status' => 200,
                'body' => ['success' => true],
            ];
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 404,
                'body' => ['error' => 'User not found: ' . $username],
            ];
        }
    }
}
