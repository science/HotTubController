<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use Firebase\JWT\JWT;
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

    // --- Layer 1: DB-backed token validation ---

    private function mintToken(string $sub, string $role, ?int $iat = null): string
    {
        $payload = [
            'exp' => time() + 3600,
            'sub' => $sub,
            'role' => $role,
        ];
        if ($iat !== -1) {
            $payload['iat'] = $iat ?? time();
        }

        return JWT::encode($payload, $this->config['JWT_SECRET'], 'HS256');
    }

    public function testValidateTokenRejectsUnknownSubject(): void
    {
        // Subject does not resolve to any user (e.g. its account was deleted, or
        // the token was minted out-of-band for a never-provisioned identity).
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn(null);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('ghost', 'admin');

        $this->expectException(\RuntimeException::class);
        $authService->validateToken($token);
    }

    public function testValidateTokenRejectsRoleMismatch(): void
    {
        // Token claims 'basic' but the DB user is 'readonly' → reject. This is the
        // auto-revoke guarantee: changing a user's role invalidates its old tokens.
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'homeassistant',
            'role' => 'readonly',
            'password_hash' => '*',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('homeassistant', 'basic');

        $this->expectException(\RuntimeException::class);
        $authService->validateToken($token);
    }

    public function testValidateTokenAcceptsMatchingSubjectAndRole(): void
    {
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'homeassistant',
            'role' => 'readonly',
            'password_hash' => '*',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('homeassistant', 'readonly');
        $claims = $authService->validateToken($token);

        $this->assertSame('homeassistant', $claims['sub']);
        $this->assertSame('readonly', $claims['role']);
    }

    // --- Layer 2: tokens must postdate the user row ---
    //
    // Deleting a user must revoke its tokens PERMANENTLY, even if an account with
    // the same name is created later: the recreated row has a newer created_at
    // than the old token's iat. (Password changes deliberately do NOT revoke —
    // delete/recreate is this app's revocation mechanism.)

    public function testValidateTokenRejectsTokenPredatingUserCreation(): void
    {
        // Token minted an hour before the user row existed → a leftover token
        // from a deleted-then-recreated account. Must be rejected.
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'steve',
            'role' => 'user',
            'password_hash' => 'hashed',
            'created_at' => date('c'),
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('steve', 'user', time() - 3600);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('predates');
        $authService->validateToken($token);
    }

    public function testValidateTokenAcceptsTokenMintedSameSecondAsUserCreation(): void
    {
        // Create-user-then-login can happen within one second; must not reject.
        $now = time();
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'steve',
            'role' => 'user',
            'password_hash' => 'hashed',
            'created_at' => date('c', $now),
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('steve', 'user', $now);
        $claims = $authService->validateToken($token);

        $this->assertSame('steve', $claims['sub']);
    }

    public function testValidateTokenRejectsTokenWithoutIat(): void
    {
        // Every token this system mints (login, mint-jwt.php, generate-cron-jwt.php)
        // carries iat; a token without one has unknown provenance → reject.
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'steve',
            'role' => 'user',
            'password_hash' => 'hashed',
            'created_at' => date('c', time() - 3600),
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('steve', 'user', -1); // -1 → omit iat

        $this->expectException(\RuntimeException::class);
        $authService->validateToken($token);
    }

    public function testValidateTokenAcceptsWhenUserRowLacksCreatedAt(): void
    {
        // Legacy rows without created_at can't anchor the check; accept rather
        // than lock those accounts out.
        $mock = $this->createMock(UserRepositoryInterface::class);
        $mock->method('findByUsername')->willReturn([
            'username' => 'legacy',
            'role' => 'user',
            'password_hash' => 'hashed',
        ]);
        $authService = new AuthService($mock, $this->config);

        $token = $this->mintToken('legacy', 'user', time() - 999999);
        $claims = $authService->validateToken($token);

        $this->assertSame('legacy', $claims['sub']);
    }

    public function testDeleteAndRecreateUserInvalidatesOldTokens(): void
    {
        // End-to-end with the real JSON repository: login → delete → recreate
        // (same name, same role, same password) → the old token must be dead,
        // and a fresh login must work.
        $repo = new \HotTub\Services\JsonUserRepository($this->usersFile);
        $authService = new AuthService($repo, $this->config);

        $repo->create('forgetful', 'oldpass', 'user');
        $oldToken = $authService->login('forgetful', 'oldpass');
        $this->assertSame('forgetful', $authService->validateToken($oldToken)['sub']);

        $repo->delete('forgetful');
        sleep(1); // created_at has second resolution; make the recreation strictly newer
        $repo->create('forgetful', 'oldpass', 'user');

        try {
            $authService->validateToken($oldToken);
            $this->fail('Expected old token to be rejected after delete + recreate');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('predates', $e->getMessage());
        }

        $newToken = $authService->login('forgetful', 'oldpass');
        $this->assertSame('forgetful', $authService->validateToken($newToken)['sub']);
    }
}
