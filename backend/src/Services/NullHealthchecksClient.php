<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HealthchecksClientInterface;

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
