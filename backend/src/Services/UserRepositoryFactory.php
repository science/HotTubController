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
    /**
     * Username of the cron runner's identity. The cron JWT is minted with
     * sub=cron-system / role=admin (see bin/generate-cron-jwt.php); DB-backed
     * token validation requires this subject to exist as a user.
     */
    private const CRON_SYSTEM_USERNAME = 'cron-system';

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

        // Self-heal required system accounts on every boot (idempotent).
        // This guarantees the cron identity exists once DB-backed token
        // validation is active, with no manual provisioning step on deploy.
        $this->ensureSystemAccounts($repo);

        return $repo;
    }

    /**
     * Ensure non-human system accounts exist. Idempotent: only creates what's
     * missing. System accounts have password login disabled.
     */
    private function ensureSystemAccounts(JsonUserRepository $repo): void
    {
        if ($repo->findByUsername(self::CRON_SYSTEM_USERNAME) === null) {
            $repo->createSystemAccount(self::CRON_SYSTEM_USERNAME, 'admin');
        }
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
