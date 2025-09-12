<?php

declare(strict_types=1);

namespace HotTubController\Tests\TestCase;

use Psr\Http\Message\ResponseInterface;

trait AuthenticatedTestTrait
{
    /**
     * Create an authenticated request with user role token
     */
    protected function createAuthenticatedRequest(
        string $method,
        string $uri,
        array $data = [],
        string $token = 'tk_testtoken1234'
    ): ResponseInterface {
        return $this->requestWithToken($method, $uri, $token, $data);
    }

    /**
     * Create an authenticated request with admin role token
     */
    protected function createAdminRequest(
        string $method,
        string $uri,
        array $data = [],
        string $token = 'tk_admintoken1234'
    ): ResponseInterface {
        return $this->requestWithToken($method, $uri, $token, $data);
    }

    /**
     * Create a cron-authenticated request
     */
    protected function createCronRequest(
        string $method,
        string $uri,
        array $data = [],
        string $cronApiKey = 'cron_api_test_key_123'
    ): ResponseInterface {
        return $this->requestWithCronAuth($method, $uri, $cronApiKey, $data);
    }

    /**
     * Assert that a request fails with authentication error
     */
    protected function assertAuthenticationRequired(ResponseInterface $response): void
    {
        $this->assertSame(401, $response->getStatusCode());

        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('error', $data);

        if (is_array($data['error'])) {
            $message = strtolower($data['error']['message']);
            $this->assertTrue(
                str_contains($message, 'authentication') ||
                str_contains($message, 'authorization') ||
                str_contains($message, 'token') ||
                str_contains($message, 'missing') ||
                str_contains($message, 'invalid'),
                "Expected authentication-related error message, got: {$data['error']['message']}"
            );
        } else {
            $message = strtolower($data['error']);
            $this->assertTrue(
                str_contains($message, 'authentication') ||
                str_contains($message, 'authorization') ||
                str_contains($message, 'token') ||
                str_contains($message, 'missing') ||
                str_contains($message, 'invalid'),
                "Expected authentication-related error message, got: {$data['error']}"
            );
        }
    }

    /**
     * Assert that a request fails with insufficient privileges (admin required)
     */
    protected function assertAdminRequired(ResponseInterface $response): void
    {
        $this->assertSame(401, $response->getStatusCode());

        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('error', $data);

        if (is_array($data['error'])) {
            $this->assertStringContainsString('admin', strtolower($data['error']['message']));
        } else {
            $this->assertStringContainsString('admin', strtolower($data['error']));
        }
    }

    /**
     * Test that endpoint requires authentication by trying without token
     */
    protected function assertEndpointRequiresAuth(string $method, string $uri, array $data = []): void
    {
        $response = $this->request($method, $uri, $data);
        $this->assertAuthenticationRequired($response);
    }

    /**
     * Test that endpoint requires admin role by trying with user token
     */
    protected function assertEndpointRequiresAdmin(string $method, string $uri, array $data = []): void
    {
        $response = $this->createAuthenticatedRequest($method, $uri, $data);
        $this->assertAdminRequired($response);
    }
}
