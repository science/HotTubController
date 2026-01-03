<?php

declare(strict_types=1);

namespace HotTub\Services;

use RuntimeException;
use InvalidArgumentException;

/**
 * Factory for creating WirelessTag clients based on environment configuration.
 *
 * Uses Strategy pattern with late-binding HTTP clients:
 * - All business logic is handled by the unified WirelessTagClient
 * - Only the HTTP client differs between modes
 *
 * Mode resolution:
 * - 'stub': Uses StubWirelessTagHttpClient (simulated responses)
 * - 'live': Uses CurlWirelessTagHttpClient (real API calls)
 * - 'auto': Checks environment and token availability
 */
class WirelessTagClientFactory
{
    /** @var array<string, string|null> */
    private array $config;

    /**
     * @param array<string, string|null> $config Environment configuration
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Check if the WirelessTag sensor is properly configured.
     *
     * In stub mode, always returns true (no credentials needed).
     * In live mode, returns true if OAuth token is present and not a placeholder.
     */
    public function isConfigured(): bool
    {
        // In stub mode, we don't need credentials - always configured
        $externalApiMode = $this->config['EXTERNAL_API_MODE'] ?? null;
        if ($externalApiMode === 'stub') {
            return true;
        }

        $token = $this->getOAuthToken();
        if (empty($token)) {
            return false;
        }

        // Check for placeholder values
        $placeholders = [
            'your-wirelesstag-oauth-token-here',
            'your-oauth-token',
            'placeholder',
        ];

        return !in_array(strtolower($token), $placeholders, true);
    }

    /**
     * Get a human-readable reason why the sensor is not configured.
     */
    public function getConfigurationError(): ?string
    {
        if ($this->isConfigured()) {
            return null;
        }

        $token = $this->getOAuthToken();
        if (empty($token)) {
            return 'Temperature sensor not configured: WIRELESSTAG_OAUTH_TOKEN is missing. Add it to your .env file or GitHub secrets.';
        }

        return 'Temperature sensor not configured: WIRELESSTAG_OAUTH_TOKEN appears to be a placeholder value.';
    }

    /**
     * Create a WirelessTag client based on the specified mode.
     *
     * @param string $mode 'stub', 'live', or 'auto'
     * @return WirelessTagClient
     * @throws RuntimeException If live mode is requested without OAuth token
     * @throws InvalidArgumentException If an invalid mode is specified
     */
    public function create(string $mode = 'auto'): WirelessTagClient
    {
        $resolvedMode = match ($mode) {
            'stub' => 'stub',
            'live' => 'live',
            'auto' => $this->resolveAutoMode(),
            default => throw new InvalidArgumentException("Invalid mode: {$mode}"),
        };

        return $this->createClient($resolvedMode);
    }

    /**
     * Create client with appropriate HTTP client.
     */
    private function createClient(string $mode): WirelessTagClient
    {
        if ($mode === 'live') {
            $token = $this->getOAuthToken();
            if (empty($token)) {
                throw new RuntimeException('WIRELESSTAG_OAUTH_TOKEN required for live mode');
            }
            $httpClient = new CurlWirelessTagHttpClient($token, $this->getTimeout());
        } else {
            $httpClient = new StubWirelessTagHttpClient();
        }

        return new WirelessTagClient($httpClient);
    }

    /**
     * Resolve auto mode to either stub or live.
     *
     * Priority:
     * 1. EXTERNAL_API_MODE from environment variable (allows test override via phpunit.xml)
     * 2. EXTERNAL_API_MODE from config (unified system mode from .env file)
     * 3. Default to 'stub' (fail-safe)
     */
    private function resolveAutoMode(): string
    {
        // Priority 1: Check environment variable (for test isolation via phpunit.xml)
        $envMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? null);
        if ($envMode !== null && $envMode !== '' && in_array($envMode, ['stub', 'live'], true)) {
            return $envMode;
        }

        // Priority 2: Check EXTERNAL_API_MODE from config (unified system mode)
        $externalApiMode = $this->config['EXTERNAL_API_MODE'] ?? null;
        if ($externalApiMode !== null && in_array($externalApiMode, ['stub', 'live'], true)) {
            return $externalApiMode;
        }

        // Priority 3: Default to stub (fail-safe)
        return 'stub';
    }

    /**
     * Check if running in a testing environment.
     */
    private function isTestingEnvironment(): bool
    {
        $env = $this->config['APP_ENV'] ?? 'development';
        return in_array($env, ['testing', 'test'], true);
    }

    /**
     * Check if OAuth token is configured.
     */
    private function hasOAuthToken(): bool
    {
        return !empty($this->getOAuthToken());
    }

    /**
     * Get OAuth token from config.
     */
    private function getOAuthToken(): ?string
    {
        return $this->config['WIRELESSTAG_OAUTH_TOKEN'] ?? null;
    }

    /**
     * Get timeout from config (default 60s for slow API).
     */
    private function getTimeout(): int
    {
        $timeout = $this->config['WIRELESSTAG_TIMEOUT'] ?? '60';
        return (int) $timeout;
    }
}
