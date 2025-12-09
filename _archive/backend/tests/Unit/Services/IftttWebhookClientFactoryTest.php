<?php

declare(strict_types=1);

namespace HotTubController\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use HotTubController\Services\IftttWebhookClientFactory;
use HotTubController\Services\IftttWebhookClient;

/**
 * Test suite for IftttWebhookClientFactory
 *
 * Tests environment detection, safety checks, and proper client creation
 */
class IftttWebhookClientFactoryTest extends TestCase
{
    private array $originalEnv;

    protected function setUp(): void
    {
        // Save original environment variables
        $this->originalEnv = $_ENV;
    }

    protected function tearDown(): void
    {
        // Restore original environment variables
        $_ENV = $this->originalEnv;
    }

    /**
     * Test factory creates client with API key in production environment
     */
    public function testFactoryCreatesClientWithApiKeyInProduction(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'test-production-key';

        $client = IftttWebhookClientFactory::create();

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertFalse($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test factory creates test mode client when API key is missing
     */
    public function testFactoryCreatesTestModeClientWhenApiKeyMissing(): void
    {
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['IFTTT_WEBHOOK_KEY']);

        $client = IftttWebhookClientFactory::create();

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test factory creates test mode client in testing environment
     */
    public function testFactoryCreatesTestModeClientInTestingEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        unset($_ENV['IFTTT_WEBHOOK_KEY']);

        $client = IftttWebhookClientFactory::create();

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test factory forces test mode when API key exists in test environment
     */
    public function testFactoryForcesTestModeWhenApiKeyExistsInTestEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'should-not-be-used';

        $client = IftttWebhookClientFactory::create();

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test factory respects dry run parameter
     */
    public function testFactoryRespectsDryRunParameter(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'test-key';

        $client = IftttWebhookClientFactory::create(true);

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertFalse($client->isTestMode());
        $this->assertTrue($client->isDryRun());
    }

    /**
     * Test createProduction method with valid parameters
     */
    public function testCreateProductionWithValidParameters(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $client = IftttWebhookClientFactory::createProduction('explicit-api-key');

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertFalse($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test createProduction throws exception in test environment
     */
    public function testCreateProductionThrowsExceptionInTestEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'testing';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('createProduction() cannot be called in test environment');

        IftttWebhookClientFactory::createProduction('api-key');
    }

    /**
     * Test createProduction throws exception with empty API key
     */
    public function testCreateProductionThrowsExceptionWithEmptyApiKey(): void
    {
        $_ENV['APP_ENV'] = 'production';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Production IFTTT client requires explicit API key');

        IftttWebhookClientFactory::createProduction('');
    }

    /**
     * Test createSafe method always creates safe client
     */
    public function testCreateSafeAlwaysCreatesSafeClient(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'real-api-key';

        $client = IftttWebhookClientFactory::createSafe();

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertTrue($client->isTestMode());
        $this->assertTrue($client->isDryRun());
    }

    /**
     * Test createSafe respects dry run parameter
     */
    public function testCreateSafeRespectsDryRunParameter(): void
    {
        $client = IftttWebhookClientFactory::createSafe(false);

        $this->assertInstanceOf(IftttWebhookClient::class, $client);
        $this->assertTrue($client->isTestMode());
        $this->assertFalse($client->isDryRun());
    }

    /**
     * Test getEnvironmentInfo returns correct information
     */
    public function testGetEnvironmentInfoReturnsCorrectInformation(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'test-key';

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $this->assertIsArray($info);
        $this->assertEquals('production', $info['environment']);
        $this->assertFalse($info['is_test_environment']);
        $this->assertTrue($info['has_api_key']);
        $this->assertFalse($info['safe_mode_active']);
        $this->assertIsArray($info['recommendations']);
    }

    /**
     * Test getEnvironmentInfo detects test environment
     */
    public function testGetEnvironmentInfoDetectsTestEnvironment(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        unset($_ENV['IFTTT_WEBHOOK_KEY']);

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $this->assertEquals('testing', $info['environment']);
        $this->assertTrue($info['is_test_environment']);
        $this->assertFalse($info['has_api_key']);
        $this->assertTrue($info['safe_mode_active']);
    }

    /**
     * Test getEnvironmentInfo provides recommendations for production without API key
     */
    public function testGetEnvironmentInfoProvidesRecommendationsForProductionWithoutApiKey(): void
    {
        $_ENV['APP_ENV'] = 'production';
        unset($_ENV['IFTTT_WEBHOOK_KEY']);

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $recommendations = $info['recommendations'];
        $this->assertContains('Add IFTTT_WEBHOOK_KEY to .env for production hardware control', $recommendations);
    }

    /**
     * Test getEnvironmentInfo provides recommendations for test environment with API key
     */
    public function testGetEnvironmentInfoProvidesRecommendationsForTestEnvironmentWithApiKey(): void
    {
        $_ENV['APP_ENV'] = 'testing';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'should-not-be-here';

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $recommendations = $info['recommendations'];
        $this->assertContains('Remove IFTTT_WEBHOOK_KEY from .env.testing to prevent accidental hardware triggers', $recommendations);
    }

    /**
     * Test getEnvironmentInfo provides recommendations for development with API key
     */
    public function testGetEnvironmentInfoProvidesRecommendationsForDevelopmentWithApiKey(): void
    {
        $_ENV['APP_ENV'] = 'development';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'dev-key';

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $recommendations = $info['recommendations'];
        $this->assertContains('Consider using dry run mode in development to avoid accidental hardware triggers', $recommendations);
    }

    /**
     * Test getEnvironmentInfo shows good configuration
     */
    public function testGetEnvironmentInfoShowsGoodConfiguration(): void
    {
        $_ENV['APP_ENV'] = 'production';
        $_ENV['IFTTT_WEBHOOK_KEY'] = 'production-key';

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $recommendations = $info['recommendations'];
        $this->assertContains('Configuration looks good for current environment', $recommendations);
    }

    /**
     * Test factory defaults to production environment when APP_ENV not set
     */
    public function testFactoryDefaultsToProductionEnvironmentWhenAppEnvNotSet(): void
    {
        unset($_ENV['APP_ENV']);
        unset($_ENV['IFTTT_WEBHOOK_KEY']);

        $info = IftttWebhookClientFactory::getEnvironmentInfo();

        $this->assertEquals('production', $info['environment']);
        $this->assertFalse($info['is_test_environment']);
    }
}
