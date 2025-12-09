<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Admin;

use HotTubController\Application\Actions\Admin\CreateUserAction;
use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticationTestHelper;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

class CreateUserActionTest extends ApiTestCase
{
    use AuthenticationTestHelper;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
    }

    protected function configureApp(): void
    {
        // No routes needed for unit tests - testing action directly
    }

    public function testCreateUserWithAdminTokenSucceeds(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $expectedNewToken = new Token(
            'usr_new123',
            'tk_new_token_456',
            'New User',
            new DateTimeImmutable('2025-01-15T10:30:00+00:00'),
            true,
            null,
            Token::ROLE_USER
        );

        $tokenService->expects($this->once())
            ->method('createToken')
            ->with('New User', 'user')
            ->willReturn($expectedNewToken);

        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([
            'name' => 'New User'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $data = $this->getResponseData($result);

        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('role', $data);
        $this->assertArrayHasKey('created', $data);

        $this->assertSame('tk_new_token_456', $data['token']);
        $this->assertSame('usr_new123', $data['user_id']);
        $this->assertSame('user', $data['role']);
        $this->assertSame('2025-01-15T10:30:00+00:00', $data['created']);
    }

    public function testCreateUserCanCreateAdminToken(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $expectedNewToken = new Token(
            'usr_admin_new123',
            'tk_admin_token_456',
            'New Admin',
            new DateTimeImmutable('2025-01-15T10:30:00+00:00'),
            true,
            null,
            Token::ROLE_ADMIN
        );

        $tokenService->expects($this->once())
            ->method('createToken')
            ->with('New Admin', 'admin')
            ->willReturn($expectedNewToken);

        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([
            'name' => 'New Admin',
            'role' => 'admin'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $data = $this->getResponseData($result);

        $this->assertSame('tk_admin_token_456', $data['token']);
        $this->assertSame('usr_admin_new123', $data['user_id']);
        $this->assertSame('admin', $data['role']);
    }

    public function testCreateUserFailsWithoutAuthentication(): void
    {
        $tokenService = $this->createMockFailingTokenService();
        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user')
            ->withParsedBody([
            'name' => 'New User'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(401, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
    }

    public function testCreateUserFailsWithUserToken(): void
    {
        $tokenService = $this->createMockTokenService('user-token', 'Regular User');
        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer user-token'
        ])
            ->withParsedBody([
                'name' => 'New User'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(401, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        if (is_array($data['error'])) {
            $this->assertStringContainsString('Admin access required', $data['error']['message']);
        } else {
            $this->assertStringContainsString('Admin access required', $data['error']);
        }
    }

    public function testCreateUserFailsWithMissingName(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');
        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing required fields: name', $data['error']);
    }

    public function testCreateUserFailsWithInvalidNameLength(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');
        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([
                'name' => str_repeat('a', 51) // Too long name
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Name must be between 1 and 50 characters', $data['error']);
    }

    public function testCreateUserFailsWithInvalidRole(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');
        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([
            'name' => 'New User',
            'role' => 'superuser' // Invalid role
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid role. Must be "user" or "admin"', $data['error']);
    }

    public function testCreateUserHandlesTokenServiceException(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $tokenService->expects($this->once())
            ->method('createToken')
            ->with('New User', 'user')
            ->willThrowException(new RuntimeException('Database error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to create user token', $this->callback(function ($context) {
                return $context['name'] === 'New User' &&
                       str_contains($context['error'], 'Database error');
            }));

        $action = new CreateUserAction($this->logger, $tokenService);

        $request = $this->createRequest('POST', '/api/v1/admin/user', [
            'Authorization' => 'Bearer admin-token'
        ])
            ->withParsedBody([
            'name' => 'New User'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(500, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Failed to create user token', $data['error']);
    }
}
