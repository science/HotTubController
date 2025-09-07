<?php

declare(strict_types=1);

namespace HotTubController\Services;

/**
 * Factory for creating IFTTT Webhook Clients with proper environment detection
 * 
 * This factory ensures that:
 * - Test environments automatically use safe mode (no hardware triggers)
 * - Production environments require explicit API key configuration
 * - Proper logging and audit trails are maintained
 */
class IftttWebhookClientFactory
{
    /**
     * Create an IFTTT webhook client based on environment configuration
     * 
     * @param bool $dryRun Force dry run mode (for testing without API calls)
     * @param string|null $auditLogPath Custom audit log path
     * @return IftttWebhookClient
     */
    public static function create(
        bool $dryRun = false,
        ?string $auditLogPath = null
    ): IftttWebhookClient {
        $apiKey = $_ENV['IFTTT_WEBHOOK_KEY'] ?? null;
        $environment = $_ENV['APP_ENV'] ?? 'production';
        
        // In testing environment, ensure we're in safe mode
        $isTestEnvironment = in_array($environment, ['testing', 'test']);
        
        if ($isTestEnvironment && !empty($apiKey)) {
            // Safety check - if we're in test environment but somehow have an API key,
            // force test mode by clearing the key and log a warning
            error_log("WARNING: IFTTT API key found in test environment - forcing safe mode");
            $apiKey = null;
        }
        
        return new IftttWebhookClient(
            $apiKey,
            30, // timeout
            $dryRun,
            $auditLogPath
        );
    }
    
    /**
     * Create a client specifically for production use
     * 
     * This method requires an explicit API key and will throw an exception
     * if called in a test environment for extra safety.
     * 
     * @param string $apiKey Explicit API key (required)
     * @param int $timeout Request timeout in seconds
     * @param string|null $auditLogPath Custom audit log path
     * @return IftttWebhookClient
     * @throws \RuntimeException If called in test environment
     */
    public static function createProduction(
        string $apiKey,
        int $timeout = 30,
        ?string $auditLogPath = null
    ): IftttWebhookClient {
        $environment = $_ENV['APP_ENV'] ?? 'production';
        
        if (in_array($environment, ['testing', 'test'])) {
            throw new \RuntimeException(
                'createProduction() cannot be called in test environment. Use create() instead.'
            );
        }
        
        if (empty($apiKey)) {
            throw new \RuntimeException('Production IFTTT client requires explicit API key');
        }
        
        return new IftttWebhookClient($apiKey, $timeout, false, $auditLogPath);
    }
    
    /**
     * Create a client specifically for testing/development
     * 
     * This client will always operate in safe mode regardless of API key presence.
     * 
     * @param bool $dryRun Enable dry run mode
     * @param string|null $auditLogPath Custom audit log path
     * @return IftttWebhookClient
     */
    public static function createSafe(
        bool $dryRun = true,
        ?string $auditLogPath = null
    ): IftttWebhookClient {
        // Always use null API key for safe mode, regardless of environment
        return new IftttWebhookClient(null, 30, $dryRun, $auditLogPath);
    }
    
    /**
     * Get information about the current environment configuration
     * 
     * @return array Environment info including safety status
     */
    public static function getEnvironmentInfo(): array
    {
        $apiKey = $_ENV['IFTTT_WEBHOOK_KEY'] ?? null;
        $environment = $_ENV['APP_ENV'] ?? 'production';
        $isTestEnvironment = in_array($environment, ['testing', 'test']);
        
        return [
            'environment' => $environment,
            'is_test_environment' => $isTestEnvironment,
            'has_api_key' => !empty($apiKey),
            'safe_mode_active' => $isTestEnvironment || empty($apiKey),
            'recommendations' => self::getRecommendations($environment, !empty($apiKey))
        ];
    }
    
    /**
     * Get safety recommendations based on current configuration
     */
    private static function getRecommendations(string $environment, bool $hasApiKey): array
    {
        $recommendations = [];
        
        if ($environment === 'production' && !$hasApiKey) {
            $recommendations[] = 'Add IFTTT_WEBHOOK_KEY to .env for production hardware control';
        }
        
        if (in_array($environment, ['testing', 'test']) && $hasApiKey) {
            $recommendations[] = 'Remove IFTTT_WEBHOOK_KEY from .env.testing to prevent accidental hardware triggers';
        }
        
        if ($environment === 'development' && $hasApiKey) {
            $recommendations[] = 'Consider using dry run mode in development to avoid accidental hardware triggers';
        }
        
        if (empty($recommendations)) {
            $recommendations[] = 'Configuration looks good for current environment';
        }
        
        return $recommendations;
    }
}