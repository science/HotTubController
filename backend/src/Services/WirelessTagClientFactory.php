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
     */
    private function resolveAutoMode(): string
    {
        // Safety: Always use stub in testing environment
        if ($this->isTestingEnvironment()) {
            return 'stub';
        }

        // Use live if OAuth token is available
        if ($this->hasOAuthToken()) {
            return 'live';
        }

        // Fallback to stub
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
