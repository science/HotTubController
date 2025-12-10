<?php

declare(strict_types=1);

namespace HotTub\Tests\Helpers;

use HotTub\Services\AuthService;
use HotTub\Services\EnvLoader;

class TestAuthHelper
{
    private static ?array $config = null;
    private static ?AuthService $authService = null;

    public static function getConfig(): array
    {
        if (self::$config === null) {
            $loader = new EnvLoader();
            $envPath = dirname(__DIR__, 2) . '/config/env.testing';
            self::$config = $loader->load($envPath);
        }
        return self::$config;
    }

    public static function getAuthService(): AuthService
    {
        if (self::$authService === null) {
            self::$authService = new AuthService(self::getConfig());
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
}
