<?php

declare(strict_types=1);

namespace HotTubController\Config;

use InvalidArgumentException;
use RuntimeException;

/**
 * Configuration manager for external API credentials and settings
 *
 * Provides secure access to WirelessTag and IFTTT API credentials
 * from environment variables without exposing token values in logs.
 */
class ExternalApiConfig
{
    private array $config = [];
    private array $requiredKeys = [
        'WIRELESSTAG_OAUTH_TOKEN',
        'WIRELESSTAG_HOT_TUB_DEVICE_ID',
        'IFTTT_WEBHOOK_KEY'
    ];

    public function __construct()
    {
        $this->loadConfiguration();
        $this->validateConfiguration();
    }

    /**
     * Get WirelessTag OAuth Bearer token
     */
    public function getWirelessTagToken(): string
    {
        return $this->config['WIRELESSTAG_OAUTH_TOKEN'] ?? '';
    }

    /**
     * Get WirelessTag hot tub device ID
     */
    public function getHotTubDeviceId(): string
    {
        return $this->config['WIRELESSTAG_HOT_TUB_DEVICE_ID'] ?? '';
    }

    /**
     * Get WirelessTag ambient temperature device ID (optional)
     */
    public function getAmbientDeviceId(): ?string
    {
        return $this->config['WIRELESSTAG_AMBIENT_DEVICE_ID'] ?? null;
    }

    /**
     * Get IFTTT webhook API key
     */
    public function getIftttWebhookKey(): string
    {
        return $this->config['IFTTT_WEBHOOK_KEY'] ?? '';
    }

    /**
     * Check if all required tokens are available
     */
    public function hasValidTokens(): bool
    {
        foreach ($this->requiredKeys as $key) {
            if (empty($this->config[$key])) {
                return false;
            }
        }
        return true;
    }

    /**
     * Get configuration status for debugging (without exposing token values)
     */
    public function getConfigStatus(): array
    {
        $status = [];

        foreach ($this->requiredKeys as $key) {
            $value = $this->config[$key] ?? null;
            $status[$key] = [
                'configured' => !empty($value),
                'length' => $value ? strlen($value) : 0,
                'preview' => $value ? substr($value, 0, 8) . '...' : null
            ];
        }

        // Add optional keys
        $optionalKeys = ['WIRELESSTAG_AMBIENT_DEVICE_ID'];
        foreach ($optionalKeys as $key) {
            $value = $this->config[$key] ?? null;
            $status[$key] = [
                'configured' => !empty($value),
                'length' => $value ? strlen($value) : 0,
                'preview' => $value ? substr($value, 0, 8) . '...' : null,
                'optional' => true
            ];
        }

        return $status;
    }

    /**
     * Validate that WirelessTag token appears to be a valid Bearer token
     */
    public function validateWirelessTagToken(): bool
    {
        $token = $this->getWirelessTagToken();

        // Basic token validation - should be a reasonably long string
        if (strlen($token) < 20) {
            return false;
        }

        // Could add more sophisticated validation here if needed
        return true;
    }

    /**
     * Get WirelessTag API configuration array
     */
    public function getWirelessTagConfig(): array
    {
        return [
            'oauth_token' => $this->getWirelessTagToken(),
            'devices' => [
                'hot_tub_sensor' => $this->getHotTubDeviceId(),
                'ambient_sensor' => $this->getAmbientDeviceId()
            ]
        ];
    }

    /**
     * Get IFTTT API configuration array
     */
    public function getIftttConfig(): array
    {
        return [
            'webhook_key' => $this->getIftttWebhookKey()
        ];
    }

    /**
     * Load configuration from environment variables
     */
    private function loadConfiguration(): void
    {
        // Load all external API related environment variables
        $keys = array_merge($this->requiredKeys, [
            'WIRELESSTAG_AMBIENT_DEVICE_ID' // Optional
        ]);

        foreach ($keys as $key) {
            $value = $_ENV[$key] ?? getenv($key);
            if ($value !== false) {
                $this->config[$key] = trim($value);
            }
        }
    }

    /**
     * Validate that required configuration is present
     *
     * @throws RuntimeException if required configuration is missing
     */
    private function validateConfiguration(): void
    {
        $missing = [];

        foreach ($this->requiredKeys as $key) {
            if (empty($this->config[$key])) {
                $missing[] = $key;
            }
        }

        if (!empty($missing)) {
            $missingKeys = implode(', ', $missing);
            throw new RuntimeException(
                "Missing required environment variables: {$missingKeys}. " .
                "Please ensure these are set in your .env file."
            );
        }
    }

    /**
     * Create configuration from custom values (useful for testing)
     *
     * @param array $customConfig Custom configuration values
     * @return self
     */
    public static function fromArray(array $customConfig): self
    {
        $instance = new self();
        $instance->config = array_merge($instance->config, $customConfig);
        $instance->validateConfiguration();
        return $instance;
    }

    /**
     * Log configuration status (safely, without exposing tokens)
     */
    public function logConfigStatus(): void
    {
        $status = $this->getConfigStatus();

        foreach ($status as $key => $info) {
            $optional = $info['optional'] ?? false;
            $configured = $info['configured'] ? '✓' : '✗';
            $optionalText = $optional ? ' (optional)' : '';

            error_log("External API Config - {$key}{$optionalText}: {$configured}");
        }
    }
}
