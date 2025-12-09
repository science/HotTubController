<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration;

use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticatedTestTrait;

class WarmingWorkflowTest extends ApiTestCase
{
    use AuthenticatedTestTrait;

    private string $userToken = '';
    protected function configureApp(): void
    {
        // Load actual routes and middleware for full integration testing
        $routes = require __DIR__ . '/../../config/routes.php';
        $routes($this->app);

        $middleware = require __DIR__ . '/../../config/middleware.php';
        $middleware($this->app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Create test token for authentication
        $this->createAuthToken();
    }

    private function createAuthToken(): void
    {
        // Create admin token via bootstrap
        $bootstrapResponse = $this->request('POST', '/api/v1/admin/bootstrap', [
            'master_password' => $_ENV['MASTER_PASSWORD'] ?? 'test-master-password',
            'name' => 'Warming Test Admin'
        ]);

        if ($bootstrapResponse->getStatusCode() === 200) {
            $bootstrapData = $this->getResponseData($bootstrapResponse);
            $adminToken = $bootstrapData['token'];

            // Create a regular user token for warming requests
            $userResponse = $this->requestWithToken('POST', '/api/v1/admin/user', $adminToken, [
                'name' => 'Warming Test User'
            ]);

            if ($userResponse->getStatusCode() === 200) {
                $userData = $this->getResponseData($userResponse);
                $this->userToken = $userData['token'];
            }
        }
    }

    public function testStatusEndpointForWarming(): void
    {
        // Test the new dedicated warming endpoint
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Response-Time'));
        $this->assertEquals('application/json', $response->getHeaderLine('Content-Type'));

        $data = $this->getResponseData($response);

        // Verify warming-relevant fields are present
        $this->assertArrayHasKey('service', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('uptime_seconds', $data);
        $this->assertArrayHasKey('response_time_ms', $data);
        $this->assertArrayHasKey('memory', $data);
        $this->assertArrayHasKey('system', $data);

        // Verify values are appropriate for warming
        $this->assertEquals('Hot Tub Controller', $data['service']);
        $this->assertEquals('1.0.0', $data['version']);
        $this->assertContains($data['status'], ['ready', 'warning', 'critical']);
        $this->assertIsNumeric($data['uptime_seconds']);
        $this->assertIsNumeric($data['response_time_ms']);
    }

    public function testRootStatusEndpointStillWorks(): void
    {
        // Ensure existing root endpoint continues to work
        $response = $this->requestWithToken('GET', '/', $this->userToken);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Response-Time'));

        $data = $this->getResponseData($response);
        $this->assertArrayHasKey('service', $data);
        $this->assertEquals('Hot Tub Controller', $data['service']);
    }

    public function testMultipleWarmingCalls(): void
    {
        $responses = [];
        $startTime = microtime(true);

        // Simulate a warming sequence with multiple rapid calls
        for ($i = 0; $i < 5; $i++) {
            $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
            $data = $this->getResponseData($response);

            $responses[] = [
                'call_number' => $i + 1,
                'status_code' => $response->getStatusCode(),
                'status' => $data['status'],
                'response_time_ms' => $data['response_time_ms'],
                'uptime_seconds' => $data['uptime_seconds'],
                'memory_usage_percent' => $data['memory']['usage_percent'],
                'timestamp' => $data['timestamp'],
                'has_response_time_header' => $response->hasHeader('X-Response-Time')
            ];

            if ($i < 4) {
                usleep(100000); // 100ms between calls
            }
        }

        $totalTime = microtime(true) - $startTime;

        // Verify all calls succeeded
        $this->assertCount(5, $responses);

        foreach ($responses as $response) {
            $this->assertEquals(200, $response['status_code']);
            $this->assertContains($response['status'], ['ready', 'warning', 'critical']);
            $this->assertIsNumeric($response['response_time_ms']);
            $this->assertIsNumeric($response['uptime_seconds']);
            $this->assertIsNumeric($response['memory_usage_percent']);
            $this->assertTrue($response['has_response_time_header']);

            // Response times should be reasonable for warming
            $this->assertLessThan(500, $response['response_time_ms']);
        }

        // Verify uptime increases across calls
        $firstUptime = $responses[0]['uptime_seconds'];
        $lastUptime = $responses[4]['uptime_seconds'];
        $this->assertGreaterThan($firstUptime, $lastUptime);

        // Total test time should be reasonable
        $this->assertLessThan(2.0, $totalTime);
    }

    public function testCorsHeadersForStatusEndpoint(): void
    {
        // Test CORS support for frontend warming requests
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->hasHeader('X-Response-Time'));

        // CORS headers should be present (may be '*' or specific origin)
        $corsOrigin = $response->getHeaderLine('Access-Control-Allow-Origin');
        $this->assertNotEmpty($corsOrigin, 'CORS Allow-Origin header should be present');
    }

    public function testOptionsRequestForStatusEndpoint(): void
    {
        // Test OPTIONS preflight request handling
        $response = $this->request('OPTIONS', '/api/v1/status');

        $this->assertEquals(200, $response->getStatusCode());

        // ResponseTimeMiddleware may not be applied to the catch-all OPTIONS route
        // but the request should still succeed for CORS preflight

        // OPTIONS requests typically have empty body for CORS
        $body = (string) $response->getBody();
        $this->assertEmpty($body);
    }

    public function testWarmingPerformanceCharacteristics(): void
    {
        $performanceData = [];

        // Test performance under rapid warming requests
        for ($i = 0; $i < 10; $i++) {
            $start = microtime(true);
            $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
            $end = microtime(true);

            $data = $this->getResponseData($response);
            $externalDuration = ($end - $start) * 1000; // Convert to ms

            $performanceData[] = [
                'external_duration_ms' => $externalDuration,
                'internal_response_time_ms' => $data['response_time_ms'],
                'memory_usage_mb' => $data['memory']['usage_mb'],
                'status' => $data['status']
            ];

            if ($i < 9) {
                usleep(50000); // 50ms between requests
            }
        }

        // Analyze performance characteristics
        $externalTimes = array_column($performanceData, 'external_duration_ms');
        $internalTimes = array_column($performanceData, 'internal_response_time_ms');

        $avgExternalTime = array_sum($externalTimes) / count($externalTimes);
        $avgInternalTime = array_sum($internalTimes) / count($internalTimes);
        $maxExternalTime = max($externalTimes);
        $maxInternalTime = max($internalTimes);

        // Performance assertions for warming effectiveness
        $this->assertLessThan(200, $avgExternalTime, 'Average external response time should be under 200ms');
        $this->assertLessThan(100, $avgInternalTime, 'Average internal response time should be under 100ms');
        $this->assertLessThan(500, $maxExternalTime, 'Maximum external response time should be under 500ms');
        $this->assertLessThan(200, $maxInternalTime, 'Maximum internal response time should be under 200ms');

        // All requests should succeed
        foreach ($performanceData as $data) {
            $this->assertContains($data['status'], ['ready', 'warning', 'critical']);
            $this->assertGreaterThan(0, $data['memory_usage_mb']);
        }
    }

    public function testMemoryAndSystemInformationForWarming(): void
    {
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $data = $this->getResponseData($response);

        // Memory information useful for warming diagnostics
        $this->assertArrayHasKey('memory', $data);
        $memory = $data['memory'];

        $this->assertArrayHasKey('usage_mb', $memory);
        $this->assertArrayHasKey('usage_percent', $memory);
        $this->assertArrayHasKey('limit_mb', $memory);
        $this->assertArrayHasKey('peak_mb', $memory);

        $this->assertGreaterThan(0, $memory['usage_mb']);
        $this->assertGreaterThanOrEqual(0, $memory['usage_percent']);
        $this->assertLessThan(100, $memory['usage_percent']);
        $this->assertGreaterThan(0, $memory['limit_mb']);

        // System information useful for warming diagnostics
        $this->assertArrayHasKey('system', $data);
        $system = $data['system'];

        $this->assertArrayHasKey('php_version', $system);
        $this->assertArrayHasKey('process_id', $system);
        $this->assertArrayHasKey('request_time', $system);
        $this->assertArrayHasKey('server_time', $system);
        $this->assertArrayHasKey('timezone', $system);

        $this->assertStringStartsWith('8.', $system['php_version']);
        $this->assertIsInt($system['process_id']);
        $this->assertGreaterThan(0, $system['process_id']);
    }

    public function testWarmingSequenceDetectsColdStart(): void
    {
        // First request should show relatively low uptime (cold start indicator)
        $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $data = $this->getResponseData($response);

        $initialUptime = $data['uptime_seconds'];
        $this->assertIsNumeric($initialUptime);
        $this->assertGreaterThanOrEqual(0, $initialUptime);

        // Wait a moment then make another request
        sleep(1);

        $response2 = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
        $data2 = $this->getResponseData($response2);

        $laterUptime = $data2['uptime_seconds'];

        // Uptime should have increased
        $this->assertGreaterThan($initialUptime, $laterUptime);

        // The difference should be approximately 1 second (allowing for variance)
        $uptimeDiff = $laterUptime - $initialUptime;
        $this->assertGreaterThan(0.8, $uptimeDiff);
        $this->assertLessThan(2.0, $uptimeDiff);
    }

    public function testConcurrentWarmingRequests(): void
    {
        // Simulate multiple concurrent warming attempts
        $responses = [];
        $startTime = microtime(true);

        // Make several requests in quick succession (simulating concurrent frontend calls)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->requestWithToken('GET', '/api/v1/status', $this->userToken);
            $responses[] = $response;

            // Very small delay between requests to simulate near-concurrent access
            if ($i < 5) {
                usleep(5000); // 5ms
            }
        }

        $endTime = microtime(true);
        $totalTime = ($endTime - $startTime) * 1000;

        // All requests should succeed
        foreach ($responses as $i => $response) {
            $this->assertEquals(200, $response->getStatusCode(), "Request $i failed");
            $this->assertTrue($response->hasHeader('X-Response-Time'), "Request $i missing response time");

            $data = $this->getResponseData($response);
            $this->assertContains($data['status'], ['ready', 'warning', 'critical'], "Request $i bad status");
        }

        // Total time for all requests should be reasonable
        $this->assertLessThan(1000, $totalTime, 'Concurrent requests took too long');
    }
}
