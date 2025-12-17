<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HealthchecksClientInterface;
use RuntimeException;

/**
 * No-op Healthchecks.io client for when monitoring is disabled.
 *
 * This client is used when:
 * - No API key is configured (feature flag disabled)
 * - The user explicitly disables monitoring
 *
 * All operations silently succeed without making any API calls.
 */
class NullHealthchecksClient implements HealthchecksClientInterface
{
    public function __construct()
    {
        // Tripwire: Null client should never be instantiated in live mode
        $apiMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? 'auto');
        if ($apiMode === 'live') {
            throw new RuntimeException(
                'NullHealthchecksClient instantiated while EXTERNAL_API_MODE=live. ' .
                'This indicates a configuration bug - the factory should have created a live client.'
            );
        }
    }

    public function isEnabled(): bool
    {
        return false;
    }

    public function createCheck(
        string $name,
        string $schedule,
        string $timezone,
        int $grace,
        ?string $channels = null
    ): ?array {
        return null;
    }

    public function ping(string $pingUrl): bool
    {
        return true;
    }

    public function delete(string $uuid): bool
    {
        return true;
    }

    public function getCheck(string $uuid): ?array
    {
        return null;
    }
}
