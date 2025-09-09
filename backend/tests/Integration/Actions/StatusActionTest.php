<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration\Actions;

use HotTubController\Tests\TestCase\ApiTestCase;

class StatusActionTest extends ApiTestCase
{
    protected function configureApp(): void
    {
        // Configure routes
        $routes = require __DIR__ . '/../../../config/routes.php';
        $routes($this->app);

        // Configure middleware
        $middleware = require __DIR__ . '/../../../config/middleware.php';
        $middleware($this->app);
    }

    public function testStatusEndpointReturnsCorrectStructure(): void
    {
        $response = $this->request('GET', '/');

        $this->assertSame(200, $response->getStatusCode());
        
        $data = $this->getResponseData($response);
        
        // Updated to match new minimal response format
        $this->assertArrayHasKey('service', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        
        // These fields were removed for security
        $this->assertArrayNotHasKey('version', $data);
        $this->assertArrayNotHasKey('environment', $data);
        
        $this->assertSame('Hot Tub Controller', $data['service']);
        $this->assertSame('running', $data['status']);
        $this->assertNotEmpty($data['timestamp']);
    }

    public function testStatusEndpointAlsoWorksOnIndexPhp(): void
    {
        $response = $this->request('GET', '/index.php');

        $this->assertSame(200, $response->getStatusCode());
        
        $data = $this->getResponseData($response);
        $this->assertSame('running', $data['status']);
    }

    public function testOptionsRequestReturnsCorsHeaders(): void
    {
        $response = $this->request('OPTIONS', '/', [], ['Origin' => 'http://localhost']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('http://localhost', $response->getHeaderLine('Access-Control-Allow-Origin'));
        $this->assertStringContainsString('GET', $response->getHeaderLine('Access-Control-Allow-Methods'));
        $this->assertStringContainsString('POST', $response->getHeaderLine('Access-Control-Allow-Methods'));
    }
}