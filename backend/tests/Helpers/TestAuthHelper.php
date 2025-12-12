<?php

declare(strict_types=1);

namespace HotTub\Tests\Helpers;

use HotTub\Services\AuthService;
use HotTub\Services\EnvLoader;
use HotTub\Services\JsonUserRepository;
use HotTub\Contracts\UserRepositoryInterface;

class TestAuthHelper
{
    private static ?array $config = null;
    private static ?AuthService $authService = null;
    private static ?UserRepositoryInterface $userRepository = null;
    private static ?string $tempUsersFile = null;

    public static function getConfig(): array
    {
        if (self::$config === null) {
            $loader = new EnvLoader();
            $envPath = dirname(__DIR__, 2) . '/config/env.testing';
            self::$config = $loader->load($envPath);
        }
        return self::$config;
    }

    public static function getUserRepository(): UserRepositoryInterface
    {
        if (self::$userRepository === null) {
            // Use a temp file for test users
            self::$tempUsersFile = sys_get_temp_dir() . '/hottub_test_users_' . getmypid() . '.json';

            // Clean up any existing file
            if (file_exists(self::$tempUsersFile)) {
                unlink(self::$tempUsersFile);
            }

            self::$userRepository = new JsonUserRepository(self::$tempUsersFile);

            // Create the admin user from config
            $config = self::getConfig();
            self::$userRepository->create(
                $config['AUTH_ADMIN_USERNAME'],
                $config['AUTH_ADMIN_PASSWORD'],
                'admin'
            );
        }
        return self::$userRepository;
    }

    public static function getAuthService(): AuthService
    {
        if (self::$authService === null) {
            self::$authService = new AuthService(
                self::getUserRepository(),
                self::getConfig()
            );
        }
        return self::$authService;
    }

    public static function getAdminCredentials(): array
    {
        $config = self::getConfig();
        return [
            'username' => $config['AUTH_ADMIN_USERNAME'],
            'password' => $config['AUTH_ADMIN_PASSWORD'],
        ];
    }

    public static function getValidToken(): string
    {
        $credentials = self::getAdminCredentials();
        return self::getAuthService()->login(
            $credentials['username'],
            $credentials['password']
        );
    }

    public static function getJwtSecret(): string
    {
        return self::getConfig()['JWT_SECRET'];
    }

    /**
     * Clean up temp files (call in tearDownAfterClass).
     */
    public static function cleanup(): void
    {
        if (self::$tempUsersFile !== null && file_exists(self::$tempUsersFile)) {
            unlink(self::$tempUsersFile);
        }
        self::$config = null;
        self::$authService = null;
        self::$userRepository = null;
        self::$tempUsersFile = null;
    }
}
