<?php

declare(strict_types=1);

namespace HotTub\Tests\Helpers;

use PHPUnit\Framework\TestCase;

class TestAuthHelperTest extends TestCase
{
    public function testGetConfigLoadsFromEnvTesting(): void
    {
        $config = TestAuthHelper::getConfig();

        $this->assertArrayHasKey('AUTH_ADMIN_USERNAME', $config);
        $this->assertArrayHasKey('AUTH_ADMIN_PASSWORD', $config);
        $this->assertArrayHasKey('JWT_SECRET', $config);
        $this->assertEquals('admin', $config['AUTH_ADMIN_USERNAME']);
    }

    public function testGetAdminCredentialsReturnsUsernameAndPassword(): void
    {
        $credentials = TestAuthHelper::getAdminCredentials();

        $this->assertArrayHasKey('username', $credentials);
        $this->assertArrayHasKey('password', $credentials);
        $this->assertEquals('admin', $credentials['username']);
        $this->assertEquals('password', $credentials['password']);
    }

    public function testGetValidTokenReturnsJwt(): void
    {
        $token = TestAuthHelper::getValidToken();

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        // JWT has 3 parts
        $this->assertCount(3, explode('.', $token));
    }

    public function testValidTokenCanBeValidatedByAuthService(): void
    {
        $token = TestAuthHelper::getValidToken();
        $authService = TestAuthHelper::getAuthService();

        $claims = $authService->validateToken($token);

        $this->assertEquals('admin', $claims['sub']);
        $this->assertEquals('admin', $claims['role']);
    }
}
