<?php

declare(strict_types=1);

namespace HotTubController\Tests\TestCase;

use DI\ContainerBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\App;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Headers;
use Slim\Psr7\Request;
use Slim\Psr7\Uri;

abstract class ApiTestCase extends TestCase
{
    protected App $app;
    protected ContainerInterface $container;

    protected function setUp(): void
    {
        parent::setUp();

        // Create container with test dependencies
        $containerBuilder = new ContainerBuilder();

        // Add standard dependencies
        $dependencies = require __DIR__ . '/../../config/dependencies.php';
        $dependencies($containerBuilder);

        // Override with test-specific bindings if needed
        $containerBuilder->addDefinitions([
            // Test-specific overrides can be added here
        ]);

        $this->container = $containerBuilder->build();

        // Create Slim app
        AppFactory::setContainer($this->container);
        $this->app = AppFactory::create();

        // Add routes and middleware
        $this->configureApp();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    abstract protected function configureApp(): void;

    protected function createRequest(
        string $method,
        string $uri,
        array $headers = [],
        array $cookies = [],
        array $serverParams = []
    ): ServerRequestInterface {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri, $serverParams);

        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        foreach ($cookies as $name => $value) {
            $request = $request->withCookieParams(array_merge($request->getCookieParams(), [$name => $value]));
        }

        return $request;
    }

    protected function request(
        string $method,
        string $uri,
        array $data = [],
        array $headers = []
    ): ResponseInterface {
        $request = $this->createRequest($method, $uri, $headers);

        if (!empty($data)) {
            $request->getBody()->write(json_encode($data));
            $request = $request->withHeader('Content-Type', 'application/json');
        }

        return $this->app->handle($request);
    }

    protected function requestWithToken(
        string $method,
        string $uri,
        string $token,
        array $data = [],
        array $headers = []
    ): ResponseInterface {
        $headers['Authorization'] = 'Bearer ' . $token;
        return $this->request($method, $uri, $data, $headers);
    }

    /**
     * @deprecated Use requestWithToken() instead - tokens now go in Authorization header
     */
    protected function requestWithTokenInBody(
        string $method,
        string $uri,
        string $token,
        array $data = [],
        array $headers = []
    ): ResponseInterface {
        $data['token'] = $token;
        return $this->request($method, $uri, $data, $headers);
    }

    protected function requestWithCronAuth(
        string $method,
        string $uri,
        string $cronApiKey = 'cron_api_test_key_123',
        array $data = [],
        array $headers = []
    ): ResponseInterface {
        $data['auth'] = $cronApiKey;
        return $this->request($method, $uri, $data, $headers);
    }

    protected function assertJsonResponse(array $expected, ResponseInterface $response): void
    {
        $this->assertSame('application/json', $response->getHeaderLine('Content-Type'));

        $body = (string) $response->getBody();
        $actual = json_decode($body, true);

        $this->assertIsArray($actual, 'Response body is not valid JSON: ' . $body);

        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, "Response missing key: $key");
            if (is_array($value)) {
                $this->assertSame($value, $actual[$key], "Response key '$key' does not match");
            } else {
                $this->assertSame($value, $actual[$key], "Response key '$key' does not match");
            }
        }
    }

    protected function assertJsonError(string $expectedError, int $expectedCode, ResponseInterface $response): void
    {
        $this->assertSame($expectedCode, $response->getStatusCode());
        $this->assertJsonResponse(['error' => $expectedError], $response);
    }

    protected function createTestToken(string $role = 'user'): array
    {
        return [
            'id' => 'usr_test123',
            'token' => 'tk_testtoken1234',
            'name' => 'Test User',
            'role' => $role,
            'created' => '2025-01-15T10:30:00+00:00',
            'active' => true,
            'last_used' => null,
        ];
    }

    protected function createTestAdminToken(): array
    {
        return [
            'id' => 'usr_admin123',
            'token' => 'tk_admintoken1234',
            'name' => 'Test Admin',
            'role' => 'admin',
            'created' => '2025-01-15T10:30:00+00:00',
            'active' => true,
            'last_used' => null,
        ];
    }

    protected function getResponseData(ResponseInterface $response): array
    {
        $body = (string) $response->getBody();
        return json_decode($body, true) ?? [];
    }
}
