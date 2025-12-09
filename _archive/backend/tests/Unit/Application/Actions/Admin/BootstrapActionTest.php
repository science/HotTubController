<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions\Admin;

use HotTubController\Application\Actions\Admin\BootstrapAction;
use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Tests\TestCase\ApiTestCase;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use RuntimeException;

class BootstrapActionTest extends ApiTestCase
{
    private LoggerInterface $logger;
    private string $masterPasswordHash;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->masterPasswordHash = password_hash('master-password-123', PASSWORD_DEFAULT);
    }

    protected function configureApp(): void
    {
        // No routes needed for unit tests - testing action directly
    }

    public function testBootstrapSuccessfullyCreatesAdminToken(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $expectedToken = new Token(
            'usr_admin123',
            'tk_generated_token_456',
            'Bootstrap Admin',
            new DateTimeImmutable('2025-01-15T10:30:00+00:00'),
            true,
            null,
            Token::ROLE_ADMIN
        );

        $tokenService->expects($this->once())
            ->method('createToken')
            ->with('Bootstrap Admin', 'admin')
            ->willReturn($expectedToken);

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'master_password' => 'master-password-123',
                'name' => 'Bootstrap Admin'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(200, $result->getStatusCode());
        $data = $this->getResponseData($result);

        $this->assertArrayHasKey('token', $data);
        $this->assertArrayHasKey('user_id', $data);
        $this->assertArrayHasKey('role', $data);
        $this->assertArrayHasKey('created', $data);
        $this->assertArrayHasKey('message', $data);

        $this->assertSame('tk_generated_token_456', $data['token']);
        $this->assertSame('usr_admin123', $data['user_id']);
        $this->assertSame('admin', $data['role']);
        $this->assertSame('2025-01-15T10:30:00+00:00', $data['created']);
        $this->assertStringContainsString('Bootstrap admin token created', $data['message']);
    }

    public function testBootstrapFailsWithInvalidMasterPassword(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('createToken');

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'master_password' => 'wrong-password',
                'name' => 'Bootstrap Admin'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(401, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Invalid master password', $data['error']);
    }

    public function testBootstrapFailsWithMissingMasterPassword(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('createToken');

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'name' => 'Bootstrap Admin'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing required fields: master_password', $data['error']);
    }

    public function testBootstrapFailsWithMissingName(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('createToken');

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'master_password' => 'master-password-123'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing required fields: name', $data['error']);
    }

    public function testBootstrapFailsWithInvalidNameLength(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->never())->method('createToken');

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'master_password' => 'master-password-123',
                'name' => str_repeat('a', 51) // Too long name
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(400, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Name must be between 1 and 50 characters', $data['error']);
    }

    public function testBootstrapHandlesTokenServiceException(): void
    {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->expects($this->once())
            ->method('createToken')
            ->with('Bootstrap Admin', 'admin')
            ->willThrowException(new RuntimeException('Database error'));

        $this->logger->expects($this->once())
            ->method('error')
            ->with('Failed to create bootstrap admin token', $this->callback(function ($context) {
                return $context['name'] === 'Bootstrap Admin' &&
                       str_contains($context['error'], 'Database error');
            }));

        $action = new BootstrapAction($this->logger, $tokenService, $this->masterPasswordHash);

        $request = $this->createRequest('POST', '/api/v1/admin/bootstrap')
            ->withParsedBody([
                'master_password' => 'master-password-123',
                'name' => 'Bootstrap Admin'
            ]);

        $response = $this->app->getResponseFactory()->createResponse();
        $result = $action->__invoke($request, $response, []);

        $this->assertSame(500, $result->getStatusCode());
        $data = $this->getResponseData($result);
        $this->assertArrayHasKey('error', $data);
        $this->assertSame('Failed to create bootstrap admin token', $data['error']);
    }
}
