<?php

declare(strict_types=1);

namespace HotTub\Tests\Middleware;

use PHPUnit\Framework\TestCase;
use HotTub\Middleware\AuthMiddleware;
use HotTub\Services\AuthService;
use HotTub\Tests\Helpers\TestAuthHelper;

class AuthMiddlewareTest extends TestCase
{
    private AuthMiddleware $middleware;

    protected function setUp(): void
    {
        $authService = TestAuthHelper::getAuthService();
        $this->middleware = new AuthMiddleware($authService);
    }

    public function testAuthenticateWithBearerTokenReturnsUser(): void
    {
        $token = TestAuthHelper::getValidToken();
        $headers = ['Authorization' => 'Bearer ' . $token];

        $result = $this->middleware->authenticate($headers, []);

        $this->assertNotNull($result);
        $this->assertEquals('admin', $result['sub']);
        $this->assertEquals('admin', $result['role']);
    }

    public function testAuthenticateWithCookieReturnsUser(): void
    {
        $token = TestAuthHelper::getValidToken();
        $cookies = ['auth_token' => $token];

        $result = $this->middleware->authenticate([], $cookies);

        $this->assertNotNull($result);
        $this->assertEquals('admin', $result['sub']);
    }

    public function testAuthenticatePrefersHeaderOverCookie(): void
    {
        $token = TestAuthHelper::getValidToken();
        $headers = ['Authorization' => 'Bearer ' . $token];
        $cookies = ['auth_token' => 'different-token'];

        $result = $this->middleware->authenticate($headers, $cookies);

        $this->assertNotNull($result);
        $this->assertEquals('admin', $result['sub']);
    }

    public function testAuthenticateWithNoTokenReturnsNull(): void
    {
        $result = $this->middleware->authenticate([], []);

        $this->assertNull($result);
    }

    public function testAuthenticateWithInvalidTokenReturnsNull(): void
    {
        $headers = ['Authorization' => 'Bearer invalid.token.here'];

        $result = $this->middleware->authenticate($headers, []);

        $this->assertNull($result);
    }

    public function testAuthenticateWithMalformedHeaderReturnsNull(): void
    {
        $headers = ['Authorization' => 'NotBearer something'];

        $result = $this->middleware->authenticate($headers, []);

        $this->assertNull($result);
    }

    public function testRequireAuthReturns401WhenNotAuthenticated(): void
    {
        $response = $this->middleware->requireAuth([], []);

        $this->assertNotNull($response);
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Authentication required', $response['body']['error']);
    }

    public function testRequireAuthReturnsNullWhenAuthenticated(): void
    {
        $token = TestAuthHelper::getValidToken();
        $headers = ['Authorization' => 'Bearer ' . $token];

        $response = $this->middleware->requireAuth($headers, []);

        $this->assertNull($response);
    }
}
