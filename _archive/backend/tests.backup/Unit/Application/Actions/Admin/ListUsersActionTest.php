<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Admin;

use HotTubController\Application\Actions\Admin\ListUsersAction;
use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticationTestHelper;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

class ListUsersActionTest extends ApiTestCase
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

    public function testListUsersWithAdminTokenSucceeds(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $userToken = new Token(
            'usr_user123',
            'tk_user_token',
            'Regular User',
            new DateTimeImmutable('2025-01-15T09:30:00+00:00'),
            true,
            new DateTimeImmutable('2025-01-15T10:15:00+00:00'),
            Token::ROLE_USER
        );

        $adminToken = new Token(
            'usr_admin123',
            'tk_admin_token',
            'Admin User',
            new DateTimeImmutable('2025-01-15T08:30:00+00:00'),
            true,
            null,
            Token::ROLE_ADMIN
        );

        $tokenService->expects($this->once())
            ->method('getAllTokens')
            ->willReturn([$userToken, $adminToken]);

        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users', [
            'Authorization' => 'Bearer admin-token'
        ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $data = $this->getResponseData($result);

        $this->assertArrayHasKey('users', $data);
        $this->assertCount(2, $data['users']);

        $firstUser = $data['users'][0];
        $this->assertArrayHasKey('id', $firstUser);
        $this->assertArrayHasKey('name', $firstUser);
        $this->assertArrayHasKey('role', $firstUser);
        $this->assertArrayHasKey('created', $firstUser);
        $this->assertArrayHasKey('active', $firstUser);
        $this->assertArrayHasKey('last_used', $firstUser);
        $this->assertArrayHasKey('token_preview', $firstUser);

        $this->assertSame('usr_user123', $firstUser['id']);
        $this->assertSame('Regular User', $firstUser['name']);
        $this->assertSame('user', $firstUser['role']);
        $this->assertSame('2025-01-15T09:30:00+00:00', $firstUser['created']);
        $this->assertTrue($firstUser['active']);
        $this->assertSame('2025-01-15T10:15:00+00:00', $firstUser['last_used']);

        $secondUser = $data['users'][1];
        $this->assertSame('usr_admin123', $secondUser['id']);
        $this->assertSame('Admin User', $secondUser['name']);
        $this->assertSame('admin', $secondUser['role']);
        $this->assertSame('2025-01-15T08:30:00+00:00', $secondUser['created']);
        $this->assertTrue($secondUser['active']);
        $this->assertNull($secondUser['last_used']); // Never used
    }

    public function testListUsersFailsWithoutAuthentication(): void
    {
        $tokenService = $this->createMockFailingTokenService();
        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users');

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(401, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
    }

    public function testListUsersFailsWithUserToken(): void
    {
        $tokenService = $this->createMockTokenService('user-token', 'Regular User');
        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users', [
            'Authorization' => 'Bearer user-token'
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

    public function testListUsersWithEmptyTokenList(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $tokenService->expects($this->once())
            ->method('getAllTokens')
            ->willReturn([]);

        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users', [
            'Authorization' => 'Bearer admin-token'
        ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $data = $this->getResponseData($result);

        $this->assertArrayHasKey('users', $data);
        $this->assertCount(0, $data['users']);
        $this->assertSame([], $data['users']);
    }

    public function testListUsersHandlesTokenServiceException(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $tokenService->expects($this->once())
            ->method('getAllTokens')
            ->willThrowException(new RuntimeException('Database error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to retrieve user list', $this->callback(function ($context) {
                return str_contains($context['error'], 'Database error');
            }));

        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users', [
            'Authorization' => 'Bearer admin-token'
        ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(500, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Failed to retrieve user list', $data['error']);
    }

    public function testListUsersLogsSuccessfulRequest(): void
    {
        $tokenService = $this->createMockAdminTokenService('admin-token', 'Test Admin');

        $userToken = new Token(
            'usr_user123',
            'tk_user_token',
            'Regular User',
            new DateTimeImmutable('2025-01-15T09:30:00+00:00'),
            true,
            null,
            Token::ROLE_USER
        );

        $tokenService->expects($this->once())
            ->method('getAllTokens')
            ->willReturn([$userToken]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with('Admin user list requested', $this->callback(function ($context) {
                return $context['user_count'] === 1;
            }));

        $action = new ListUsersAction($this->logger, $tokenService);

        $request = $this->createRequest('GET', '/api/v1/admin/users', [
            'Authorization' => 'Bearer admin-token'
        ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $action->__invoke($request, $response, []);
    }
}
