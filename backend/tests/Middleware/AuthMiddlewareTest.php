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

    public function testRequireAdminReturns401WhenNotAuthenticated(): void
    {
        $response = $this->middleware->requireAdmin([], []);

        $this->assertNotNull($response);
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Authentication required', $response['body']['error']);
    }

    public function testRequireAdminReturnsNullForAdminUser(): void
    {
        // TestAuthHelper creates an admin user, so this token is for an admin
        $token = TestAuthHelper::getValidToken();
        $headers = ['Authorization' => 'Bearer ' . $token];

        $response = $this->middleware->requireAdmin($headers, []);

        $this->assertNull($response);
    }

    public function testRequireAdminReturns403ForNonAdminUser(): void
    {
        // Create a regular user token
        $userRepo = TestAuthHelper::getUserRepository();
        // Make sure the user exists
        if ($userRepo->findByUsername('regularuser') === null) {
            $userRepo->create('regularuser', 'password', 'user');
        }

        $authService = TestAuthHelper::getAuthService();
        $token = $authService->login('regularuser', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $response = $this->middleware->requireAdmin($headers, []);

        $this->assertNotNull($response);
        $this->assertEquals(403, $response['status']);
        $this->assertEquals('Admin access required', $response['body']['error']);
    }

    public function testRequireAuthReturnsNullForBasicUser(): void
    {
        // Create a basic user
        $userRepo = TestAuthHelper::getUserRepository();
        if ($userRepo->findByUsername('basicuser') === null) {
            $userRepo->create('basicuser', 'password', 'basic');
        }

        $authService = TestAuthHelper::getAuthService();
        $token = $authService->login('basicuser', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        // Basic users should pass requireAuth (same as 'user')
        $response = $this->middleware->requireAuth($headers, []);

        $this->assertNull($response);
    }

    public function testRequireAdminReturns403ForBasicUser(): void
    {
        // Create a basic user
        $userRepo = TestAuthHelper::getUserRepository();
        if ($userRepo->findByUsername('basicuser2') === null) {
            $userRepo->create('basicuser2', 'password', 'basic');
        }

        $authService = TestAuthHelper::getAuthService();
        $token = $authService->login('basicuser2', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        // Basic users should NOT have admin access
        $response = $this->middleware->requireAdmin($headers, []);

        $this->assertNotNull($response);
        $this->assertEquals(403, $response['status']);
        $this->assertEquals('Admin access required', $response['body']['error']);
    }

    // --- requireWrite (Layer 2a) ---

    public function testRequireWriteReturns401WhenNotAuthenticated(): void
    {
        $response = $this->middleware->requireWrite([], []);

        $this->assertNotNull($response);
        $this->assertEquals(401, $response['status']);
        $this->assertEquals('Authentication required', $response['body']['error']);
    }

    public function testRequireWriteReturnsNullForAdminUser(): void
    {
        $token = TestAuthHelper::getValidToken(); // admin
        $headers = ['Authorization' => 'Bearer ' . $token];

        $this->assertNull($this->middleware->requireWrite($headers, []));
    }

    public function testRequireWriteReturnsNullForBasicUser(): void
    {
        $userRepo = TestAuthHelper::getUserRepository();
        if ($userRepo->findByUsername('writebasic') === null) {
            $userRepo->create('writebasic', 'password', 'basic');
        }
        $token = TestAuthHelper::getAuthService()->login('writebasic', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        // basic is a write role (e.g. vickie controls hardware) — must pass.
        $this->assertNull($this->middleware->requireWrite($headers, []));
    }

    public function testRequireWriteReturns403ForReadonlyUser(): void
    {
        $userRepo = TestAuthHelper::getUserRepository();
        if ($userRepo->findByUsername('readonlyuser') === null) {
            $userRepo->create('readonlyuser', 'password', 'readonly');
        }
        $token = TestAuthHelper::getAuthService()->login('readonlyuser', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $response = $this->middleware->requireWrite($headers, []);

        $this->assertNotNull($response);
        $this->assertEquals(403, $response['status']);
        $this->assertEquals('Write access required', $response['body']['error']);
    }

    public function testBasicUserTokenHasCorrectRole(): void
    {
        // Create a basic user and verify the token contains the correct role
        $userRepo = TestAuthHelper::getUserRepository();
        if ($userRepo->findByUsername('basicuser3') === null) {
            $userRepo->create('basicuser3', 'password', 'basic');
        }

        $authService = TestAuthHelper::getAuthService();
        $token = $authService->login('basicuser3', 'password');
        $headers = ['Authorization' => 'Bearer ' . $token];

        $result = $this->middleware->authenticate($headers, []);

        $this->assertNotNull($result);
        $this->assertEquals('basicuser3', $result['sub']);
        $this->assertEquals('basic', $result['role']);
    }
}
