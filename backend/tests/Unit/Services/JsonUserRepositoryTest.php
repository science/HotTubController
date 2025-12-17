<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\JsonUserRepository;
use HotTub\Contracts\UserRepositoryInterface;

class JsonUserRepositoryTest extends TestCase
{
    private string $tempDir;
    private string $usersFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hottub_test_' . uniqid();
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

    public function testImplementsUserRepositoryInterface(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $this->assertInstanceOf(UserRepositoryInterface::class, $repo);
    }

    public function testCreateUserStoresHashedPassword(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $user = $repo->create('steve', 'password123', 'user');

        $this->assertEquals('steve', $user['username']);
        $this->assertEquals('user', $user['role']);
        $this->assertArrayHasKey('created_at', $user);
        // Password hash should not be returned
        $this->assertArrayNotHasKey('password_hash', $user);
    }

    public function testCreateAdminUser(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $user = $repo->create('admin', 'adminpass', 'admin');

        $this->assertEquals('admin', $user['username']);
        $this->assertEquals('admin', $user['role']);
    }

    public function testCreateUserWithDuplicateUsernameThrows(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'pass1');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User already exists: steve');

        $repo->create('steve', 'pass2');
    }

    public function testFindByUsernameReturnsUser(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'password123', 'user');

        $user = $repo->findByUsername('steve');

        $this->assertNotNull($user);
        $this->assertEquals('steve', $user['username']);
        $this->assertEquals('user', $user['role']);
        $this->assertArrayHasKey('password_hash', $user);
    }

    public function testFindByUsernameReturnsNullForNonexistent(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $user = $repo->findByUsername('nonexistent');

        $this->assertNull($user);
    }

    public function testListReturnsAllUsersWithoutPasswordHashes(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('admin', 'pass1', 'admin');
        $repo->create('steve', 'pass2', 'user');
        $repo->create('jane', 'pass3', 'user');

        $users = $repo->list();

        $this->assertCount(3, $users);
        foreach ($users as $user) {
            $this->assertArrayHasKey('username', $user);
            $this->assertArrayHasKey('role', $user);
            $this->assertArrayNotHasKey('password_hash', $user);
        }
    }

    public function testListReturnsEmptyArrayWhenNoUsers(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $users = $repo->list();

        $this->assertEquals([], $users);
    }

    public function testDeleteRemovesUser(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'password123');

        $repo->delete('steve');

        $this->assertNull($repo->findByUsername('steve'));
    }

    public function testDeleteNonexistentUserThrows(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found: nonexistent');

        $repo->delete('nonexistent');
    }

    public function testUpdatePasswordChangesPassword(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'oldpassword');

        $repo->updatePassword('steve', 'newpassword');

        $this->assertFalse($repo->verifyPassword('steve', 'oldpassword'));
        $this->assertTrue($repo->verifyPassword('steve', 'newpassword'));
    }

    public function testUpdatePasswordForNonexistentUserThrows(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User not found: nonexistent');

        $repo->updatePassword('nonexistent', 'newpass');
    }

    public function testVerifyPasswordReturnsTrueForCorrectPassword(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'mypassword');

        $this->assertTrue($repo->verifyPassword('steve', 'mypassword'));
    }

    public function testVerifyPasswordReturnsFalseForIncorrectPassword(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'mypassword');

        $this->assertFalse($repo->verifyPassword('steve', 'wrongpassword'));
    }

    public function testVerifyPasswordReturnsFalseForNonexistentUser(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $this->assertFalse($repo->verifyPassword('nonexistent', 'anypass'));
    }

    public function testPasswordIsHashedWithBcrypt(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'password123');

        $user = $repo->findByUsername('steve');
        $hash = $user['password_hash'];

        // bcrypt hashes start with $2y$
        $this->assertStringStartsWith('$2y$', $hash);
        // Verify the hash works with password_verify
        $this->assertTrue(password_verify('password123', $hash));
    }

    public function testDataPersistsAcrossInstances(): void
    {
        $repo1 = new JsonUserRepository($this->usersFile);
        $repo1->create('steve', 'password123', 'user');

        // Create new instance pointing to same file
        $repo2 = new JsonUserRepository($this->usersFile);

        $user = $repo2->findByUsername('steve');
        $this->assertNotNull($user);
        $this->assertEquals('steve', $user['username']);
    }

    public function testCreatesDirectoryIfNotExists(): void
    {
        $nestedPath = $this->tempDir . '/nested/path/users.json';
        $repo = new JsonUserRepository($nestedPath);

        $repo->create('steve', 'pass');

        $this->assertFileExists($nestedPath);

        // Cleanup nested directories
        unlink($nestedPath);
        rmdir($this->tempDir . '/nested/path');
        rmdir($this->tempDir . '/nested');
    }

    public function testFileHasCorrectStructure(): void
    {
        $repo = new JsonUserRepository($this->usersFile);
        $repo->create('steve', 'password123', 'user');

        $content = json_decode(file_get_contents($this->usersFile), true);

        $this->assertArrayHasKey('version', $content);
        $this->assertEquals(1, $content['version']);
        $this->assertArrayHasKey('users', $content);
        $this->assertArrayHasKey('steve', $content['users']);
    }

    public function testInvalidRoleDefaultsToUser(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $user = $repo->create('steve', 'pass', 'superuser');

        // Invalid role should default to 'user'
        $this->assertEquals('user', $user['role']);
    }

    public function testValidRolesAccepted(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $admin = $repo->create('admin', 'pass', 'admin');
        $user = $repo->create('steve', 'pass', 'user');

        $this->assertEquals('admin', $admin['role']);
        $this->assertEquals('user', $user['role']);
    }

    public function testBasicRoleIsAccepted(): void
    {
        $repo = new JsonUserRepository($this->usersFile);

        $basicUser = $repo->create('basic_user', 'pass', 'basic');

        // 'basic' should be preserved, not defaulted to 'user'
        $this->assertEquals('basic', $basicUser['role']);

        // Verify it persists correctly
        $found = $repo->findByUsername('basic_user');
        $this->assertEquals('basic', $found['role']);
    }
}
