<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\AuthController;
use HotTub\Services\AuthService;
use HotTub\Tests\Helpers\TestAuthHelper;

class AuthControllerTest extends TestCase
{
    private AuthController $controller;
    private AuthService $authService;

    protected function setUp(): void
    {
        $this->authService = TestAuthHelper::getAuthService();
        $this->controller = new AuthController($this->authService);
    }

    public function testLoginWithValidCredentialsReturnsToken(): void
    {
        $credentials = TestAuthHelper::getAdminCredentials();

        $response = $this->controller->login(
            $credentials['username'],
            $credentials['password']
        );

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('token', $response['body']);
        $this->assertArrayHasKey('user', $response['body']);
        $this->assertEquals('admin', $response['body']['user']['username']);
        $this->assertEquals('admin', $response['body']['user']['role']);
    }

    public function testLoginWithInvalidCredentialsReturns401(): void
    {
        $response = $this->controller->login('wrong', 'credentials');

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertEquals('Invalid credentials', $response['body']['error']);
    }

    public function testMeWithValidTokenReturnsUser(): void
    {
        $token = TestAuthHelper::getValidToken();

        $response = $this->controller->me($token);

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('user', $response['body']);
        $this->assertEquals('admin', $response['body']['user']['username']);
        $this->assertEquals('admin', $response['body']['user']['role']);
    }

    public function testMeWithInvalidTokenReturns401(): void
    {
        $response = $this->controller->me('invalid.token.here');

        $this->assertEquals(401, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    public function testLogoutReturnsSuccess(): void
    {
        $response = $this->controller->logout();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['success']);
    }
}
