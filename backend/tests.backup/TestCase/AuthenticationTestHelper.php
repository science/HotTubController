<?php

declare(strict_types=1);

namespace HotTubController\Tests\TestCase;

use HotTubController\Domain\Token\Token;
use HotTubController\Domain\Token\TokenService;
use DateTimeImmutable;

/**
 * Helper trait for creating properly mocked TokenService instances in tests
 *
 * This trait provides convenience methods for setting up authentication mocks
 * that work correctly with the AuthenticatedAction base class requirements.
 *
 * Usage:
 * ```php
 * class MyActionTest extends TestCase
 * {
 *     use AuthenticationTestHelper;
 *
 *     public function testMyAction(): void
 *     {
 *         $tokenService = $this->createMockTokenService();
 *         $action = new MyAction($logger, $tokenService);
 *
 *         $request = $request->withHeader('Authorization', 'Bearer test-token');
 *         $response = $action->__invoke($request, $response, []);
 *         // ... assertions
 *     }
 * }
 * ```
 *
 * Note: This trait creates TokenService mocks for dependency injection.
 * For HTTP request creation with auth headers, see AuthenticatedTestTrait.
 */
trait AuthenticationTestHelper
{
    /**
     * Create a mock TokenService with user role authentication
     *
     * @param string $tokenValue The token value to mock (default: 'test-token')
     * @param string $tokenName The token name for debugging (default: 'Test Token')
     * @return TokenService
     */
    protected function createMockTokenService(
        string $tokenValue = 'test-token',
        string $tokenName = 'Test Token'
    ): TokenService {
        $mockToken = new Token(
            'test-id',
            $tokenValue,
            $tokenName,
            new DateTimeImmutable(),
            true,
            null,
            Token::ROLE_USER
        );

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateToken')
            ->with($tokenValue)
            ->willReturn(true);
        $tokenService->method('getTokenByValue')
            ->with($tokenValue)
            ->willReturn($mockToken);
        $tokenService->method('updateTokenLastUsed')
            ->with($tokenValue);

        return $tokenService;
    }

    /**
     * Create a mock TokenService with admin role authentication
     *
     * @param string $tokenValue The admin token value to mock (default: 'admin-token')
     * @param string $tokenName The token name for debugging (default: 'Admin Test Token')
     * @return TokenService
     */
    protected function createMockAdminTokenService(
        string $tokenValue = 'admin-token',
        string $tokenName = 'Admin Test Token'
    ): TokenService {
        $mockToken = new Token(
            'admin-id',
            $tokenValue,
            $tokenName,
            new DateTimeImmutable(),
            true,
            null,
            Token::ROLE_ADMIN
        );

        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateToken')
            ->with($tokenValue)
            ->willReturn(true);
        $tokenService->method('getTokenByValue')
            ->with($tokenValue)
            ->willReturn($mockToken);
        $tokenService->method('updateTokenLastUsed')
            ->with($tokenValue);

        return $tokenService;
    }

    /**
     * Create a mock TokenService that rejects authentication
     *
     * @param string $tokenValue The token value that should be rejected
     * @return TokenService
     */
    protected function createMockFailingTokenService(
        string $tokenValue = 'invalid-token'
    ): TokenService {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateToken')
            ->with($tokenValue)
            ->willReturn(false);

        return $tokenService;
    }

    /**
     * Create a mock TokenService where token exists but getTokenByValue returns null
     * This simulates edge cases in token validation
     *
     * @param string $tokenValue The token value to mock
     * @return TokenService
     */
    protected function createMockIncompleteTokenService(
        string $tokenValue = 'incomplete-token'
    ): TokenService {
        $tokenService = $this->createMock(TokenService::class);
        $tokenService->method('validateToken')
            ->with($tokenValue)
            ->willReturn(true);
        $tokenService->method('getTokenByValue')
            ->with($tokenValue)
            ->willReturn(null); // This will cause authentication to fail

        return $tokenService;
    }
}
