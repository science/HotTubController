<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Application\Actions;

use PHPUnit\Framework\TestCase;
use HotTubController\Application\Actions\StatusAction;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

class StatusActionTest extends TestCase
{
    private StatusAction $action;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock the logger dependency
        $this->logger = $this->createMock(LoggerInterface::class);
        
        // Create the action with mocked dependency
        $this->action = new StatusAction($this->logger);
    }

    public function testStatusReturnsExpectedStructure(): void
    {
        // Create mock request and response objects
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        // Call the action directly (not through HTTP)
        $result = $this->action->__invoke($request, $response, []);
        
        // Verify response
        $this->assertEquals(200, $result->getStatusCode());
        $this->assertEquals('application/json', $result->getHeaderLine('Content-Type'));
        
        // Parse JSON body
        $body = json_decode((string) $result->getBody(), true);
        
        // Test basic structure
        $this->assertIsArray($body);
        $this->assertArrayHasKey('service', $body);
        $this->assertArrayHasKey('version', $body);
        $this->assertArrayHasKey('status', $body);
        $this->assertArrayHasKey('timestamp', $body);
        $this->assertArrayHasKey('uptime_seconds', $body);
        $this->assertArrayHasKey('response_time_ms', $body);
        $this->assertArrayHasKey('memory', $body);
        $this->assertArrayHasKey('system', $body);
        
        // Test values
        $this->assertEquals('Hot Tub Controller', $body['service']);
        $this->assertEquals('1.0.0', $body['version']);
        $this->assertContains($body['status'], ['ready', 'warning', 'critical']);
    }

    public function testMemoryMetricsStructure(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result = $this->action->__invoke($request, $response, []);
        $body = json_decode((string) $result->getBody(), true);
        
        // Test memory structure
        $this->assertArrayHasKey('memory', $body);
        $memory = $body['memory'];
        
        $this->assertArrayHasKey('usage_bytes', $memory);
        $this->assertArrayHasKey('usage_mb', $memory);
        $this->assertArrayHasKey('peak_bytes', $memory);
        $this->assertArrayHasKey('peak_mb', $memory);
        $this->assertArrayHasKey('limit_bytes', $memory);
        $this->assertArrayHasKey('limit_mb', $memory);
        $this->assertArrayHasKey('usage_percent', $memory);
        
        // Test memory value types and ranges
        $this->assertIsInt($memory['usage_bytes']);
        $this->assertIsNumeric($memory['usage_mb']); // Can be int or float from round()
        $this->assertIsInt($memory['peak_bytes']);
        $this->assertIsNumeric($memory['peak_mb']); // Can be int or float from round()
        $this->assertIsInt($memory['limit_bytes']);
        $this->assertIsNumeric($memory['limit_mb']); // Can be int or float from round()
        $this->assertIsNumeric($memory['usage_percent']); // Can be int or float from round()
        
        $this->assertGreaterThan(0, $memory['usage_bytes']);
        $this->assertGreaterThan(0, $memory['usage_mb']);
        $this->assertGreaterThanOrEqual(0, $memory['usage_percent']);
        $this->assertLessThanOrEqual(100, $memory['usage_percent']);
    }

    public function testSystemMetricsStructure(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result = $this->action->__invoke($request, $response, []);
        $body = json_decode((string) $result->getBody(), true);
        
        // Test system structure
        $this->assertArrayHasKey('system', $body);
        $system = $body['system'];
        
        $this->assertArrayHasKey('php_version', $system);
        $this->assertArrayHasKey('process_id', $system);
        $this->assertArrayHasKey('request_time', $system);
        $this->assertArrayHasKey('server_time', $system);
        $this->assertArrayHasKey('timezone', $system);
        
        // Test system value types and validity
        $this->assertEquals(PHP_VERSION, $system['php_version']);
        $this->assertIsInt($system['process_id']);
        $this->assertGreaterThan(0, $system['process_id']);
        $this->assertIsInt($system['request_time']);
        $this->assertIsInt($system['server_time']);
        $this->assertIsString($system['timezone']);
        $this->assertNotEmpty($system['timezone']);
    }

    public function testResponseTimeIsTracked(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result = $this->action->__invoke($request, $response, []);
        $body = json_decode((string) $result->getBody(), true);
        
        $this->assertArrayHasKey('response_time_ms', $body);
        $this->assertIsNumeric($body['response_time_ms']); // Can be int or float from round()
        $this->assertGreaterThanOrEqual(0, $body['response_time_ms']);
        // Should be very fast in unit test environment
        $this->assertLessThan(100, $body['response_time_ms']);
    }

    public function testUptimeTracking(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result1 = $this->action->__invoke($request, $response, []);
        $body1 = json_decode((string) $result1->getBody(), true);
        
        // Small delay
        usleep(10000); // 10ms
        
        $result2 = $this->action->__invoke($request, $response, []);
        $body2 = json_decode((string) $result2->getBody(), true);
        
        $this->assertArrayHasKey('uptime_seconds', $body1);
        $this->assertArrayHasKey('uptime_seconds', $body2);
        
        $this->assertIsNumeric($body1['uptime_seconds']); // Can be int or float from round()
        $this->assertIsNumeric($body2['uptime_seconds']); // Can be int or float from round()
        
        // Second call should have higher uptime
        $this->assertGreaterThan($body1['uptime_seconds'], $body2['uptime_seconds']);
    }

    public function testStatusDeterminationLogic(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result = $this->action->__invoke($request, $response, []);
        $body = json_decode((string) $result->getBody(), true);
        
        $this->assertContains($body['status'], ['ready', 'warning', 'critical']);
        
        // Validate status logic based on memory usage
        $memoryPercent = $body['memory']['usage_percent'];
        if ($memoryPercent > 95) {
            $this->assertEquals('critical', $body['status']);
        } elseif ($memoryPercent > 90) {
            $this->assertEquals('warning', $body['status']);
        } else {
            $this->assertEquals('ready', $body['status']);
        }
    }

    public function testTimestampFormat(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        $result = $this->action->__invoke($request, $response, []);
        $body = json_decode((string) $result->getBody(), true);
        
        $this->assertArrayHasKey('timestamp', $body);
        
        // Verify ISO 8601 format
        $timestamp = \DateTime::createFromFormat(\DateTime::ATOM, $body['timestamp']);
        $this->assertInstanceOf(\DateTime::class, $timestamp);
        
        // Should be recent (within 10 seconds)
        $now = new \DateTime();
        $this->assertLessThan(10, abs($now->getTimestamp() - $timestamp->getTimestamp()));
    }

    public function testConsistentResponseStructure(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = (new ResponseFactory())->createResponse();
        
        // Call multiple times to ensure consistent structure
        for ($i = 0; $i < 3; $i++) {
            $result = $this->action->__invoke($request, $response, []);
            $body = json_decode((string) $result->getBody(), true);
            
            // Basic structure should always be the same
            $this->assertArrayHasKey('service', $body);
            $this->assertArrayHasKey('status', $body);
            $this->assertArrayHasKey('memory', $body);
            $this->assertArrayHasKey('system', $body);
            
            $this->assertEquals('Hot Tub Controller', $body['service']);
            $this->assertContains($body['status'], ['ready', 'warning', 'critical']);
        }
    }
}