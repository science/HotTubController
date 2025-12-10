<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\AuthService;

class AuthServiceTest extends TestCase
{
    private array $config;

    protected function setUp(): void
    {
        $this->config = [
            'AUTH_ADMIN_USERNAME' => 'admin',
            'AUTH_ADMIN_PASSWORD' => 'password',
            'JWT_SECRET' => 'test-secret-for-phpunit-only',
            'JWT_EXPIRY_HOURS' => '24',
        ];
    }

    public function testValidCredentialsReturnJwtToken(): void
    {
        $authService = new AuthService($this->config);

        $token = $authService->login('admin', 'password');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        // JWT tokens have 3 parts separated by dots
        $this->assertCount(3, explode('.', $token));
    }

    public function testInvalidUsernameThrowsException(): void
    {
        $authService = new AuthService($this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authService->login('wrong-user', 'password');
    }

    public function testInvalidPasswordThrowsException(): void
    {
        $authService = new AuthService($this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authService->login('admin', 'wrong-password');
    }

    public function testValidTokenReturnsUserClaims(): void
    {
        $authService = new AuthService($this->config);
        $token = $authService->login('admin', 'password');

        $claims = $authService->validateToken($token);

        $this->assertEquals('admin', $claims['sub']);
        $this->assertEquals('admin', $claims['role']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
    }

    public function testInvalidTokenThrowsException(): void
    {
        $authService = new AuthService($this->config);

        $this->expectException(\RuntimeException::class);

        $authService->validateToken('invalid.token.here');
    }

    public function testTokenWithWrongSecretThrowsException(): void
    {
        $authService = new AuthService($this->config);
        $token = $authService->login('admin', 'password');

        // Create a new service with different secret
        $otherConfig = $this->config;
        $otherConfig['JWT_SECRET'] = 'different-secret';
        $otherAuthService = new AuthService($otherConfig);

        $this->expectException(\RuntimeException::class);

        $otherAuthService->validateToken($token);
    }
}
