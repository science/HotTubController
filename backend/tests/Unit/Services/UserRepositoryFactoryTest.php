<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\UserRepositoryFactory;
use HotTub\Contracts\UserRepositoryInterface;

class UserRepositoryFactoryTest extends TestCase
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

    public function testCreateReturnsUserRepositoryInterface(): void
    {
        $config = [
            'AUTH_ADMIN_USERNAME' => 'admin',
            'AUTH_ADMIN_PASSWORD' => 'password',
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);
        $repo = $factory->create();

        $this->assertInstanceOf(UserRepositoryInterface::class, $repo);
    }

    public function testBootstrapsAdminWhenFileDoesNotExist(): void
    {
        $config = [
            'AUTH_ADMIN_USERNAME' => 'myadmin',
            'AUTH_ADMIN_PASSWORD' => 'mypassword',
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);
        $repo = $factory->create();

        // Admin should have been bootstrapped from config
        $this->assertTrue($repo->verifyPassword('myadmin', 'mypassword'));
        $user = $repo->findByUsername('myadmin');
        $this->assertEquals('admin', $user['role']);
    }

    public function testDoesNotBootstrapWhenFileExists(): void
    {
        // Pre-create the file with existing user
        $existingData = [
            'version' => 1,
            'users' => [
                'existingadmin' => [
                    'password_hash' => password_hash('existingpass', PASSWORD_BCRYPT),
                    'role' => 'admin',
                    'created_at' => date('c'),
                ],
            ],
        ];
        file_put_contents($this->usersFile, json_encode($existingData, JSON_PRETTY_PRINT));

        $config = [
            'AUTH_ADMIN_USERNAME' => 'newadmin',
            'AUTH_ADMIN_PASSWORD' => 'newpassword',
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);
        $repo = $factory->create();

        // Should NOT create new admin from config
        $this->assertNull($repo->findByUsername('newadmin'));
        // Existing admin should still work
        $this->assertTrue($repo->verifyPassword('existingadmin', 'existingpass'));
    }

    public function testBootstrapsWithHashedPassword(): void
    {
        $config = [
            'AUTH_ADMIN_USERNAME' => 'admin',
            'AUTH_ADMIN_PASSWORD' => 'secretpassword',
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);
        $repo = $factory->create();

        $user = $repo->findByUsername('admin');

        // Password should be hashed (bcrypt starts with $2y$)
        $this->assertStringStartsWith('$2y$', $user['password_hash']);
        // Original password should verify
        $this->assertTrue(password_verify('secretpassword', $user['password_hash']));
    }

    public function testThrowsWhenMissingAdminCredentialsAndNoFile(): void
    {
        $config = [
            // Missing AUTH_ADMIN_USERNAME and AUTH_ADMIN_PASSWORD
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('AUTH_ADMIN_USERNAME and AUTH_ADMIN_PASSWORD required for initial setup');

        $factory->create();
    }

    public function testDoesNotThrowForMissingCredentialsWhenFileExists(): void
    {
        // Pre-create the file
        $existingData = [
            'version' => 1,
            'users' => [
                'admin' => [
                    'password_hash' => password_hash('existingpass', PASSWORD_BCRYPT),
                    'role' => 'admin',
                    'created_at' => date('c'),
                ],
            ],
        ];
        file_put_contents($this->usersFile, json_encode($existingData, JSON_PRETTY_PRINT));

        $config = [
            // No admin credentials needed when file exists
        ];

        $factory = new UserRepositoryFactory($this->usersFile, $config);
        $repo = $factory->create();

        // Should work fine without credentials
        $this->assertTrue($repo->verifyPassword('admin', 'existingpass'));
    }
}
