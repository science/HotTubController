<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration;

use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticatedTestTrait;

class AdminAuthenticationFlowTest extends ApiTestCase
{
    use AuthenticatedTestTrait;

    protected function configureApp(): void
    {
        // Set up middleware
        $middleware = require __DIR__ . '/../../config/middleware.php';
        $middleware($this->app);

        // Set up routes
        $routes = require __DIR__ . '/../../config/routes.php';
        $routes($this->app);
    }

    public function testCompleteAdminAuthenticationFlow(): void
    {
        // Step 1: Try admin operations without authentication - should fail
        $this->assertEndpointRequiresAuth('POST', '/api/v1/admin/user', ['name' => 'Test User']);
        $this->assertEndpointRequiresAuth('GET', '/api/v1/admin/users');

        // Step 2: Create bootstrap admin token using master password
        $bootstrapResponse = $this->request('POST', '/api/v1/admin/bootstrap', [
            'master_password' => $_ENV['MASTER_PASSWORD'] ?? 'test-master-password',
            'name' => 'Bootstrap Admin'
        ]);

        $this->assertSame(200, $bootstrapResponse->getStatusCode());
        $bootstrapData = $this->getResponseData($bootstrapResponse);

        $this->assertArrayHasKey('token', $bootstrapData);
        $this->assertArrayHasKey('role', $bootstrapData);
        $this->assertSame('admin', $bootstrapData['role']);

        $adminToken = $bootstrapData['token'];

        // Step 3: Use admin token to create regular user
        $createUserResponse = $this->requestWithToken('POST', '/api/v1/admin/user', $adminToken, [
            'name' => 'Regular User'
        ]);

        $this->assertSame(200, $createUserResponse->getStatusCode());
        $userData = $this->getResponseData($createUserResponse);

        $this->assertArrayHasKey('token', $userData);
        $this->assertArrayHasKey('role', $userData);
        $this->assertSame('user', $userData['role']);

        $userToken = $userData['token'];

        // Step 4: Use admin token to create another admin
        $createAdminResponse = $this->requestWithToken('POST', '/api/v1/admin/user', $adminToken, [
            'name' => 'Second Admin',
            'role' => 'admin'
        ]);

        $this->assertSame(200, $createAdminResponse->getStatusCode());
        $secondAdminData = $this->getResponseData($createAdminResponse);

        $this->assertSame('admin', $secondAdminData['role']);

        // Step 5: Use admin token to list all users
        $listUsersResponse = $this->requestWithToken('GET', '/api/v1/admin/users', $adminToken);

        $this->assertSame(200, $listUsersResponse->getStatusCode());
        $usersData = $this->getResponseData($listUsersResponse);

        $this->assertArrayHasKey('users', $usersData);
        $this->assertGreaterThanOrEqual(3, count($usersData['users'])); // At least bootstrap admin + regular user + second admin

        // Verify user details in the list
        $users = $usersData['users'];
        $userNames = array_column($users, 'name');
        $this->assertContains('Bootstrap Admin', $userNames);
        $this->assertContains('Regular User', $userNames);
        $this->assertContains('Second Admin', $userNames);

        // Step 6: Verify regular user cannot access admin endpoints
        $userTryCreateResponse = $this->requestWithToken('POST', '/api/v1/admin/user', $userToken, [
            'name' => 'Unauthorized User'
        ]);
        $this->assertAdminRequired($userTryCreateResponse);

        $userTryListResponse = $this->requestWithToken('GET', '/api/v1/admin/users', $userToken);
        $this->assertAdminRequired($userTryListResponse);

        // Step 7: Verify second admin can also perform admin operations
        $secondAdminListResponse = $this->requestWithToken('GET', '/api/v1/admin/users', $secondAdminData['token']);
        $this->assertSame(200, $secondAdminListResponse->getStatusCode());
    }

    public function testBootstrapFailsWithWrongMasterPassword(): void
    {
        $response = $this->request('POST', '/api/v1/admin/bootstrap', [
            'master_password' => 'wrong-password',
            'name' => 'Bootstrap Admin'
        ]);

        $this->assertSame(401, $response->getStatusCode());
        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid master password', $data['error']);
    }

    public function testAdminEndpointsNoLongerAcceptMasterPassword(): void
    {
        // These endpoints should now require Bearer token authentication
        // and should not accept master_password in the request body

        // Try CreateUserAction with master password in body (old method)
        $createResponse = $this->request('POST', '/api/v1/admin/user', [
            'master_password' => $_ENV['MASTER_PASSWORD'] ?? 'test-master-password',
            'name' => 'Test User'
        ]);
        $this->assertAuthenticationRequired($createResponse);

        // Try ListUsersAction with master password in query params (old method)
        $listResponse = $this->request('GET', '/api/v1/admin/users?master_password=' .
            urlencode($_ENV['MASTER_PASSWORD'] ?? 'test-master-password'));
        $this->assertAuthenticationRequired($listResponse);
    }

    public function testInvalidTokensAreRejected(): void
    {
        $createResponse = $this->requestWithToken('POST', '/api/v1/admin/user', 'invalid-token', [
            'name' => 'Test User'
        ]);
        $this->assertAuthenticationRequired($createResponse);

        $listResponse = $this->requestWithToken('GET', '/api/v1/admin/users', 'invalid-token');
        $this->assertAuthenticationRequired($listResponse);
    }

    public function testMissingAuthorizationHeaderIsRejected(): void
    {
        $createResponse = $this->request('POST', '/api/v1/admin/user', [
            'name' => 'Test User'
        ]);
        $this->assertAuthenticationRequired($createResponse);

        $listResponse = $this->request('GET', '/api/v1/admin/users');
        $this->assertAuthenticationRequired($listResponse);
    }
}
