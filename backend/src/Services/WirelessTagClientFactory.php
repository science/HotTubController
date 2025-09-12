<?php

declare(strict_types=1);

namespace HotTubController\Services;

use InvalidArgumentException;

/**
 * WirelessTag Client Factory
 *
 * Creates WirelessTagClient instances with appropriate configuration
 * for different environments (test vs production).
 */
class WirelessTagClientFactory
{
    /**
     * Create WirelessTagClient with environment-aware configuration
     *
     * Automatically detects test environment and creates safe client
     * when running tests or when no valid API token is available.
     *
     * @param int $maxRetries Maximum number of API retry attempts
     * @param int $timeoutSeconds Request timeout in seconds
     * @return WirelessTagClient Configured client instance
     */
    public static function create(int $maxRetries = 8, int $timeoutSeconds = 30): WirelessTagClient
    {
        $oauthToken = $_ENV['WIRELESSTAG_OAUTH_TOKEN'] ?? '';
        $environment = $_ENV['APP_ENV'] ?? 'production';

        // Force test mode in test environment or when token is missing/placeholder
        if ($environment === 'testing' || empty($oauthToken) || $oauthToken === 'bearer_token_goes_here') {
            return new WirelessTagClient(null, $maxRetries, $timeoutSeconds);
        }

        return new WirelessTagClient($oauthToken, $maxRetries, $timeoutSeconds);
    }

    /**
     * Create WirelessTagClient in safe mode (test mode)
     *
     * Always creates a client that operates in test mode with
     * simulated responses, regardless of environment or available tokens.
     * Safe for use in all testing scenarios.
     *
     * @param int $maxRetries Maximum number of API retry attempts (unused in test mode)
     * @param int $timeoutSeconds Request timeout in seconds (unused in test mode)
     * @return WirelessTagClient Test-mode client instance
     */
    public static function createSafe(int $maxRetries = 8, int $timeoutSeconds = 30): WirelessTagClient
    {
        return new WirelessTagClient(null, $maxRetries, $timeoutSeconds);
    }

    /**
     * Create WirelessTagClient for production use
     *
     * Requires explicit API token and refuses to operate in test environment.
     * Use this method when you explicitly need production API access.
     *
     * @param string $oauthToken Valid WirelessTag OAuth token
     * @param int $maxRetries Maximum number of API retry attempts
     * @param int $timeoutSeconds Request timeout in seconds
     * @return WirelessTagClient Production client instance
     * @throws InvalidArgumentException If token is empty or in test environment
     */
    public static function createProduction(
        string $oauthToken,
        int $maxRetries = 8,
        int $timeoutSeconds = 30
    ): WirelessTagClient {
        $environment = $_ENV['APP_ENV'] ?? 'production';

        if ($environment === 'testing') {
            throw new InvalidArgumentException(
                'Cannot create production WirelessTag client in test environment. Use createSafe() for testing.'
            );
        }

        if (empty($oauthToken)) {
            throw new InvalidArgumentException('Production WirelessTag client requires valid OAuth token');
        }

        return new WirelessTagClient($oauthToken, $maxRetries, $timeoutSeconds);
    }
}
