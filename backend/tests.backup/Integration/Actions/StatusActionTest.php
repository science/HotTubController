<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration\Actions;

use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticatedTestTrait;

class StatusActionTest extends ApiTestCase
{
    use AuthenticatedTestTrait;

    private string $userToken = '';
    private string $adminToken = '';

    protected function configureApp(): void
    {
        // Configure routes
        $routes = require __DIR__ . '/../../../config/routes.php';
        $routes($this->app);

        // Configure middleware
        $middleware = require __DIR__ . '/../../../config/middleware.php';
        $middleware($this->app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tokens for authentication
        $this->createTestTokens();
    }

    private function createTestTokens(): void
    {
        // Create admin token via bootstrap
        $bootstrapResponse = $this->request('POST', '/api/v1/admin/bootstrap', [
            'master_password' => $_ENV['MASTER_PASSWORD'] ?? 'test-master-password',
            'name' => 'Test Admin'
        ]);

        if ($bootstrapResponse->getStatusCode() === 200) {
            $bootstrapData = $this->getResponseData($bootstrapResponse);
            $this->adminToken = $bootstrapData['token'];

            // Create a regular user token
            $userResponse = $this->requestWithToken('POST', '/api/v1/admin/user', $this->adminToken, [
                'name' => 'Test User'
            ]);

            if ($userResponse->getStatusCode() === 200) {
                $userData = $this->getResponseData($userResponse);
                $this->userToken = $userData['token'];
            }
        }
    }

    public function testStatusEndpointRequiresAuthentication(): void
    {
        // Test that unauthenticated requests are rejected
        $this->assertEndpointRequiresAuth('GET', '/');
        $this->assertEndpointRequiresAuth('GET', '/index.php');
        $this->assertEndpointRequiresAuth('GET', '/api/v1/status');
    }

    public function testStatusEndpointReturnsCorrectStructure(): void
    {
        $response = $this->requestWithToken('GET', '/', $this->userToken);

        $this->assertSame(200, $response->getStatusCode());

        $data = $this->getResponseData($response);

        // Updated to match enhanced status response format
        $this->assertArrayHasKey('service', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('uptime_seconds', $data);
        $this->assertArrayHasKey('response_time_ms', $data);
        $this->assertArrayHasKey('memory', $data);
        $this->assertArrayHasKey('system', $data);

        $this->assertSame('Hot Tub Controller', $data['service']);
        $this->assertSame('1.0.0', $data['version']);
        $this->assertContains($data['status'], ['ready', 'warning', 'critical']);
        $this->assertNotEmpty($data['timestamp']);
    }

    public function testStatusEndpointAlsoWorksOnIndexPhp(): void
    {
        $response = $this->requestWithToken('GET', '/index.php', $this->userToken);

        $this->assertSame(200, $response->getStatusCode());

        $data = $this->getResponseData($response);
        $this->assertContains($data['status'], ['ready', 'warning', 'critical']);
    }

    public function testOptionsRequestReturnsCorsHeaders(): void
    {
        $response = $this->request('OPTIONS', '/', [], ['Origin' => 'http://localhost']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('http://localhost', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }

    public function testEnhancedStatusEndpoint(): void
    {
        // Test new /api/v1/status endpoint
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);

        $this->assertEquals(200, $response->getStatusCode());
        $data = $this->getResponseData($response);

        // Test enhanced fields
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('uptime_seconds', $data);
        $this->assertArrayHasKey('response_time_ms', $data);
        $this->assertArrayHasKey('memory', $data);
        $this->assertArrayHasKey('system', $data);

        $this->assertEquals('1.0.0', $data['version']);
        $this->assertIsNumeric($data['uptime_seconds']);
        $this->assertIsNumeric($data['response_time_ms']);

        // Verify memory structure
        $this->assertArrayHasKey('usage_mb', $data['memory']);
        $this->assertArrayHasKey('usage_percent', $data['memory']);
        $this->assertArrayHasKey('limit_mb', $data['memory']);
        $this->assertArrayHasKey('peak_mb', $data['memory']);

        // Verify system structure
        $this->assertArrayHasKey('php_version', $data['system']);
        $this->assertArrayHasKey('process_id', $data['system']);
        $this->assertArrayHasKey('request_time', $data['system']);
        $this->assertArrayHasKey('server_time', $data['system']);
        $this->assertArrayHasKey('timezone', $data['system']);

        // Verify data types and reasonable values
        $this->assertIsNumeric($data['memory']['usage_mb']);
        $this->assertIsNumeric($data['memory']['usage_percent']);
        $this->assertGreaterThan(0, $data['memory']['usage_mb']);
        $this->assertGreaterThanOrEqual(0, $data['memory']['usage_percent']);
        $this->assertLessThan(100, $data['memory']['usage_percent']);

        $this->assertStringStartsWith('8.', $data['system']['php_version']);
        $this->assertIsInt($data['system']['process_id']);
        $this->assertGreaterThan(0, $data['system']['process_id']);
    }

    public function testResponseTimeHeader(): void
    {
        // Test that ResponseTimeMiddleware adds the header
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);

        $this->assertTrue($response->hasHeader('X-Response-Time'));

        $headerValue = $response->getHeaderLine('X-Response-Time');
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $headerValue);

        // Extract numeric value
        $timeValue = (float) str_replace('ms', '', $headerValue);
        $this->assertGreaterThanOrEqual(0, $timeValue);
        $this->assertLessThan(1000, $timeValue); // Should be fast
    }

    public function testResponseTimeHeaderOnRootEndpoint(): void
    {
        // Test that ResponseTimeMiddleware works on root endpoint too
        $response = $this->requestWithToken('GET', '/', $this->userToken);

        $this->assertTrue($response->hasHeader('X-Response-Time'));

        $headerValue = $response->getHeaderLine('X-Response-Time');
        $this->assertMatchesRegularExpression('/^\d+(\.\d+)?ms$/', $headerValue);
    }

    public function testMemoryInformationIsRealistic(): void
    {
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $data = $this->getResponseData($response);

        $memory = $data['memory'];

        // Memory values should be realistic for a PHP application
        $this->assertGreaterThan(0, $memory['usage_bytes']);
        $this->assertGreaterThan(0, $memory['peak_bytes']);
        $this->assertGreaterThan(0, $memory['limit_bytes']);

        // Peak should be at least equal to current usage
        $this->assertGreaterThanOrEqual($memory['usage_bytes'], $memory['peak_bytes']);

        // Usage should not exceed limit
        $this->assertLessThanOrEqual($memory['limit_bytes'], $memory['usage_bytes']);

        // MB conversions should be accurate
        $this->assertEquals(
            round($memory['usage_bytes'] / 1024 / 1024, 2),
            $memory['usage_mb']
        );

        $this->assertEquals(
            round($memory['peak_bytes'] / 1024 / 1024, 2),
            $memory['peak_mb']
        );

        $this->assertEquals(
            round($memory['limit_bytes'] / 1024 / 1024, 2),
            $memory['limit_mb']
        );

        // Percentage calculation should be accurate
        $expectedPercent = round(($memory['usage_bytes'] / $memory['limit_bytes']) * 100, 1);
        $this->assertEquals($expectedPercent, $memory['usage_percent']);
    }

    public function testStatusDeterminationBasedOnMemory(): void
    {
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $data = $this->getResponseData($response);

        $status = $data['status'];
        $memoryPercent = $data['memory']['usage_percent'];

        // Validate status determination logic
        if ($memoryPercent > 95) {
            $this->assertEquals('critical', $status);
        } elseif ($memoryPercent > 90) {
            $this->assertEquals('warning', $status);
        } else {
            $this->assertEquals('ready', $status);
        }
    }

    public function testTimestampIsValid(): void
    {
        $beforeRequest = time();
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $afterRequest = time();

        $data = $this->getResponseData($response);

        // Verify timestamp format and validity
        $timestamp = \DateTime::createFromFormat(\DateTime::ATOM, $data['timestamp']);
        $this->assertInstanceOf(\DateTime::class, $timestamp);

        $requestTime = $timestamp->getTimestamp();
        $this->assertGreaterThanOrEqual($beforeRequest, $requestTime);
        $this->assertLessThanOrEqual($afterRequest, $requestTime);
    }
}
