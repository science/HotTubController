<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\AuthService;
use HotTub\Contracts\UserRepositoryInterface;

class AuthServiceTest extends TestCase
{
    private array $config;
    private string $tempDir;
    private string $usersFile;

    protected function setUp(): void
    {
        $this->config = [
            'JWT_SECRET' => 'test-secret-for-phpunit-only',
            'JWT_EXPIRY_HOURS' => '24',
        ];

        // Create temp directory for test user files
        $this->tempDir = sys_get_temp_dir() . '/hottub_auth_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->usersFile = $this->tempDir . '/users.json';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->usersFile)) {
            unlink($this->usersFile);
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    private function createMockUserRepository(bool $verifyResult = true, ?array $userData = null): UserRepositoryInterface
    {
        $mock = $this->createMock(UserRepositoryInterface::class);

        $mock->method('verifyPassword')->willReturn($verifyResult);

        if ($userData !== null) {
            $mock->method('findByUsername')->willReturn($userData);
        }

        return $mock;
    }

    public function testValidCredentialsReturnJwtToken(): void
    {
        $userRepo = $this->createMockUserRepository(true, [
            'username' => 'admin',
            'role' => 'admin',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($userRepo, $this->config);

        $token = $authService->login('admin', 'password');

        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        // JWT tokens have 3 parts separated by dots
        $this->assertCount(3, explode('.', $token));
    }

    public function testInvalidUsernameThrowsException(): void
    {
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('verifyPassword')->willReturn(false);
        $mock->method('findByUsername')->willReturn(null);

        $authService = new AuthService($mock, $this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authService->login('wrong-user', 'password');
    }

    public function testInvalidPasswordThrowsException(): void
    {
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('verifyPassword')->willReturn(false);
        $mock->method('findByUsername')->willReturn([
            'username' => 'admin',
            'role' => 'admin',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);

        $authService = new AuthService($mock, $this->config);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid credentials');

        $authService->login('admin', 'wrong-password');
    }

    public function testValidTokenReturnsUserClaims(): void
    {
        $userRepo = $this->createMockUserRepository(true, [
            'username' => 'admin',
            'role' => 'admin',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($userRepo, $this->config);
        $token = $authService->login('admin', 'password');

        $claims = $authService->validateToken($token);

        $this->assertEquals('admin', $claims['sub']);
        $this->assertEquals('admin', $claims['role']);
        $this->assertArrayHasKey('iat', $claims);
        $this->assertArrayHasKey('exp', $claims);
    }

    public function testInvalidTokenThrowsException(): void
    {
        $userRepo = $this->createMockUserRepository();
        $authService = new AuthService($userRepo, $this->config);

        $this->expectException(\RuntimeException::class);

        $authService->validateToken('invalid.token.here');
    }

    public function testTokenWithWrongSecretThrowsException(): void
    {
        $userRepo = $this->createMockUserRepository(true, [
            'username' => 'admin',
            'role' => 'admin',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($userRepo, $this->config);
        $token = $authService->login('admin', 'password');

        // Create a new service with different secret
        $otherConfig = $this->config;
        $otherConfig['JWT_SECRET'] = 'different-secret';
        $otherAuthService = new AuthService($userRepo, $otherConfig);

        $this->expectException(\RuntimeException::class);

        $otherAuthService->validateToken($token);
    }

    public function testRegularUserGetsUserRole(): void
    {
        $userRepo = $this->createMockUserRepository(true, [
            'username' => 'steve',
            'role' => 'user',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($userRepo, $this->config);

        $token = $authService->login('steve', 'password');
        $claims = $authService->validateToken($token);

        $this->assertEquals('steve', $claims['sub']);
        $this->assertEquals('user', $claims['role']);
    }

    public function testAdminUserGetsAdminRole(): void
    {
        $userRepo = $this->createMockUserRepository(true, [
            'username' => 'boss',
            'role' => 'admin',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($userRepo, $this->config);

        $token = $authService->login('boss', 'password');
        $claims = $authService->validateToken($token);

        $this->assertEquals('boss', $claims['sub']);
        $this->assertEquals('admin', $claims['role']);
    }
}
