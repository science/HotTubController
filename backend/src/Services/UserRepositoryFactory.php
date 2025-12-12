<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\UserRepositoryInterface;

/**
 * Factory for creating user repositories with bootstrap logic.
 *
 * On first run (no users.json), seeds admin from .env credentials.
 * On subsequent runs, uses existing users.json as source of truth.
 */
class UserRepositoryFactory
{
    private string $filePath;
    private array $config;

    public function __construct(string $filePath, array $config)
    {
        $this->filePath = $filePath;
        $this->config = $config;
    }

    /**
     * Create a user repository, bootstrapping admin if needed.
     *
     * @return UserRepositoryInterface
     * @throws \RuntimeException If bootstrap needed but admin credentials missing
     */
    public function create(): UserRepositoryInterface
    {
        $repo = new JsonUserRepository($this->filePath);

        // Bootstrap admin if file doesn't exist
        if (!file_exists($this->filePath)) {
            $this->bootstrap($repo);
        }

        return $repo;
    }

    /**
     * Bootstrap the admin user from config.
     */
    private function bootstrap(JsonUserRepository $repo): void
    {
        $username = $this->config['AUTH_ADMIN_USERNAME'] ?? '';
        $password = $this->config['AUTH_ADMIN_PASSWORD'] ?? '';

        if (empty($username) || empty($password)) {
            throw new \RuntimeException(
                'AUTH_ADMIN_USERNAME and AUTH_ADMIN_PASSWORD required for initial setup'
            );
        }

        $repo->create($username, $password, 'admin');
    }
}
