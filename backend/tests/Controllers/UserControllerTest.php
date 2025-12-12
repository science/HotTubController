<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\UserController;
use HotTub\Services\JsonUserRepository;
use HotTub\Contracts\UserRepositoryInterface;

class UserControllerTest extends TestCase
{
    private UserController $controller;
    private UserRepositoryInterface $userRepository;
    private string $tempDir;
    private string $usersFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/hottub_userctrl_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->usersFile = $this->tempDir . '/users.json';

        $this->userRepository = new JsonUserRepository($this->usersFile);
        // Create an admin user for testing
        $this->userRepository->create('admin', 'adminpass', 'admin');

        $this->controller = new UserController($this->userRepository);
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

    // LIST tests
    public function testListReturnsAllUsers(): void
    {
        $this->userRepository->create('steve', 'pass1', 'user');
        $this->userRepository->create('jane', 'pass2', 'user');

        $response = $this->controller->list();

        $this->assertEquals(200, $response['status']);
        $this->assertCount(3, $response['body']['users']); // admin + steve + jane
    }

    public function testListDoesNotIncludePasswordHashes(): void
    {
        $response = $this->controller->list();

        foreach ($response['body']['users'] as $user) {
            $this->assertArrayNotHasKey('password_hash', $user);
        }
    }

    // CREATE tests
    public function testCreateUserReturnsCredentials(): void
    {
        $response = $this->controller->create([
            'username' => 'newuser',
            'password' => 'newpassword',
            'role' => 'user',
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertEquals('newuser', $response['body']['username']);
        $this->assertEquals('newpassword', $response['body']['password']);
        $this->assertEquals('user', $response['body']['role']);
        $this->assertStringContainsString('Share these credentials', $response['body']['message']);
    }

    public function testCreateAdminUser(): void
    {
        $response = $this->controller->create([
            'username' => 'newadmin',
            'password' => 'adminpass',
            'role' => 'admin',
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertEquals('admin', $response['body']['role']);
    }

    public function testCreateUserDefaultsToUserRole(): void
    {
        $response = $this->controller->create([
            'username' => 'newuser',
            'password' => 'pass',
        ]);

        $this->assertEquals(201, $response['status']);
        $this->assertEquals('user', $response['body']['role']);
    }

    public function testCreateUserWithDuplicateUsernameReturns409(): void
    {
        $response = $this->controller->create([
            'username' => 'admin', // already exists
            'password' => 'pass',
        ]);

        $this->assertEquals(409, $response['status']);
        $this->assertStringContainsString('already exists', $response['body']['error']);
    }

    public function testCreateUserWithMissingUsernameReturns400(): void
    {
        $response = $this->controller->create([
            'password' => 'pass',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('Username', $response['body']['error']);
    }

    public function testCreateUserWithMissingPasswordReturns400(): void
    {
        $response = $this->controller->create([
            'username' => 'newuser',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('Password', $response['body']['error']);
    }

    // DELETE tests
    public function testDeleteUserRemovesUser(): void
    {
        $this->userRepository->create('todelete', 'pass', 'user');

        $response = $this->controller->delete('todelete');

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertNull($this->userRepository->findByUsername('todelete'));
    }

    public function testDeleteNonexistentUserReturns404(): void
    {
        $response = $this->controller->delete('nonexistent');

        $this->assertEquals(404, $response['status']);
        $this->assertStringContainsString('not found', $response['body']['error']);
    }

    // UPDATE PASSWORD tests
    public function testUpdatePasswordChangesPassword(): void
    {
        $this->userRepository->create('steve', 'oldpass', 'user');

        $response = $this->controller->updatePassword('steve', [
            'password' => 'newpassword',
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
        $this->assertTrue($this->userRepository->verifyPassword('steve', 'newpassword'));
        $this->assertFalse($this->userRepository->verifyPassword('steve', 'oldpass'));
    }

    public function testUpdatePasswordForNonexistentUserReturns404(): void
    {
        $response = $this->controller->updatePassword('nonexistent', [
            'password' => 'newpass',
        ]);

        $this->assertEquals(404, $response['status']);
        $this->assertStringContainsString('not found', $response['body']['error']);
    }

    public function testUpdatePasswordWithMissingPasswordReturns400(): void
    {
        $this->userRepository->create('steve', 'pass', 'user');

        $response = $this->controller->updatePassword('steve', []);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('Password', $response['body']['error']);
    }
}
