<?php

declare(strict_types=1);

namespace HotTubController\Tests\Integration\Admin;

use HotTubController\Tests\TestCase\ApiTestCase;
use HotTubController\Tests\TestCase\AuthenticatedTestTrait;
use HotTubController\Config\HeatingConfig;
use Slim\Psr7\Factory\ServerRequestFactory;

class HeatingConfigEndpointsTest extends ApiTestCase
{
    use AuthenticatedTestTrait;

    private string $userToken = '';
    private string $adminToken = '';
    private string $tempConfigFile;

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

        // Use a temporary file for testing persistence
        $this->tempConfigFile = sys_get_temp_dir() . '/heating-config-test-' . uniqid() . '.json';

        // Clear any existing environment variables
        unset($_ENV['HOT_TUB_HEATING_RATE']);
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

        // Fall back to hardcoded tokens if bootstrap fails (for isolated testing)
        if (empty($this->adminToken)) {
            $this->adminToken = 'tk_admintoken1234';
        }
        if (empty($this->userToken)) {
            $this->userToken = 'tk_testtoken1234';
        }
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (file_exists($this->tempConfigFile)) {
            unlink($this->tempConfigFile);
        }

        // Reset configuration to default by removing any persisted config file
        $configFile = __DIR__ . '/../../../storage/heating-config.json';
        if (file_exists($configFile)) {
            unlink($configFile);
        }

        parent::tearDown();
    }

    public function testGetHeatingConfigSuccess(): void
    {
        // Create request
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/admin/config/heating'
        )->withHeader('Authorization', "Bearer {$this->adminToken}");

        // Process request
        $response = $this->app->handle($request);

        // Assert success
        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertTrue($responseData['success']);

        $configData = $responseData['data'];
        $this->assertArrayHasKey('heating_rate', $configData);
        $this->assertArrayHasKey('unit', $configData);
        $this->assertArrayHasKey('min_allowed', $configData);
        $this->assertArrayHasKey('max_allowed', $configData);
        $this->assertArrayHasKey('supported_units', $configData);

        // Check default values
        $this->assertEquals(0.5, $configData['heating_rate']);
        $this->assertEquals('fahrenheit_per_minute', $configData['unit']);
        $this->assertEquals(0.1, $configData['min_allowed']);
        $this->assertEquals(2.0, $configData['max_allowed']);
        $this->assertEquals(['fahrenheit_per_minute'], $configData['supported_units']);
    }

    public function testGetHeatingConfigRequiresAdminAuth(): void
    {
        // Create request
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/admin/config/heating'
        )->withHeader('Authorization', "Bearer {$this->userToken}");

        // Process request
        $response = $this->app->handle($request);

        // Assert forbidden
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testGetHeatingConfigRequiresAuth(): void
    {
        // Create request without auth header
        $request = (new ServerRequestFactory())->createServerRequest(
            'GET',
            '/api/v1/admin/config/heating'
        );

        // Process request
        $response = $this->app->handle($request);

        // Assert unauthorized
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUpdateHeatingConfigSuccess(): void
    {
        // Create request body
        $requestData = [
            'heating_rate' => 0.6,
            'unit' => 'fahrenheit_per_minute'
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->adminToken, $requestData);

        // Assert success
        $this->assertEquals(200, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertTrue($responseData['success']);
        $this->assertEquals('Heating configuration updated successfully', $responseData['message']);

        $configData = $responseData['data'];
        $this->assertEquals(0.6, $configData['heating_rate']);
        $this->assertEquals('fahrenheit_per_minute', $configData['unit']);
    }

    public function testUpdateHeatingConfigMissingHeatingRate(): void
    {
        // Create request body without heating_rate
        $requestData = [
            'unit' => 'fahrenheit_per_minute'
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->adminToken, $requestData);

        // Assert bad request
        $this->assertEquals(400, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Missing required field: heating_rate', $responseData['error']);
    }

    public function testUpdateHeatingConfigMissingUnit(): void
    {

        // Create request body without unit
        $requestData = [
            'heating_rate' => 0.6
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->adminToken, $requestData);

        // Assert bad request
        $this->assertEquals(400, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertFalse($responseData['success']);
        $this->assertEquals('Missing required field: unit', $responseData['error']);
    }

    public function testUpdateHeatingConfigInvalidHeatingRate(): void
    {

        // Create request body with invalid heating rate
        $requestData = [
            'heating_rate' => 2.5, // Too high
            'unit' => 'fahrenheit_per_minute'
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->adminToken, $requestData);

        // Assert unprocessable entity
        $this->assertEquals(422, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('Heating rate must be between 0.1 and 2.0', $responseData['error']);
    }

    public function testUpdateHeatingConfigInvalidUnit(): void
    {

        // Create request body with invalid unit
        $requestData = [
            'heating_rate' => 0.6,
            'unit' => 'celsius_per_minute' // Not supported
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->adminToken, $requestData);

        // Assert unprocessable entity
        $this->assertEquals(422, $response->getStatusCode());

        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertFalse($responseData['success']);
        $this->assertStringContainsString('Unit "celsius_per_minute" is not supported', $responseData['error']);
    }

    public function testUpdateHeatingConfigInvalidJSON(): void
    {
        // Create request with invalid JSON
        $request = (new ServerRequestFactory())->createServerRequest(
            'PUT',
            '/api/v1/admin/config/heating'
        )->withHeader('Authorization', "Bearer {$this->adminToken}")
         ->withHeader('Content-Type', 'application/json');

        $request->getBody()->write('invalid json');

        // Process request
        $response = $this->app->handle($request);

        // Assert bad request
        $this->assertEquals(400, $response->getStatusCode());

        // Since getJsonInput() now handles JSON parsing, invalid JSON will result in 
        // the parent class returning a 400 error. The exact error message may vary.
        $responseData = json_decode((string) $response->getBody(), true);
        $this->assertFalse($responseData['success']);
        $this->assertArrayHasKey('error', $responseData);
    }

    public function testUpdateHeatingConfigRequiresAdminAuth(): void
    {

        // Create request data
        $requestData = [
            'heating_rate' => 0.6,
            'unit' => 'fahrenheit_per_minute'
        ];

        // Process request
        $response = $this->requestWithToken('PUT', '/api/v1/admin/config/heating', $this->userToken, $requestData);

        // Assert forbidden
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testUpdateHeatingConfigRequiresAuth(): void
    {
        // Create request data
        $requestData = [
            'heating_rate' => 0.6,
            'unit' => 'fahrenheit_per_minute'
        ];

        // Process request without authentication
        $response = $this->request('PUT', '/api/v1/admin/config/heating', $requestData);

        // Assert unauthorized
        $this->assertEquals(401, $response->getStatusCode());
    }
}
