<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Manages async refresh state for temperature readings.
 *
 * Tracks when a refresh was requested so we can determine if a refresh
 * is in progress, succeeded, or timed out. Uses file-based storage
 * for simplicity on shared hosting.
 */
class TemperatureStateService
{
    private const TIMEOUT_SECONDS = 15;

    public function __construct(
        private string $stateFilePath
    ) {}

    /**
     * Record that a refresh was requested at the given time.
     */
    public function markRefreshRequested(\DateTimeImmutable $timestamp): void
    {
        $state = [
            'refresh_requested_at' => $timestamp->format('c'),
        ];

        $this->saveState($state);
    }

    /**
     * Get the current state, or null if no state exists.
     */
    public function getState(): ?array
    {
        if (!file_exists($this->stateFilePath)) {
            return null;
        }

        $content = file_get_contents($this->stateFilePath);
        if ($content === false || $content === '') {
            return null;
        }

        $state = json_decode($content, true);
        if (!is_array($state)) {
            return null;
        }

        return $state;
    }

    /**
     * Clear the refresh state (called after refresh completes or times out).
     */
    public function clearRefreshState(): void
    {
        if (file_exists($this->stateFilePath)) {
            unlink($this->stateFilePath);
        }
    }

    /**
     * Determine if a refresh is currently in progress.
     *
     * A refresh is "in progress" if:
     * - A refresh was requested
     * - The sensor timestamp is older than the request time
     * - The request hasn't timed out yet (within TIMEOUT_SECONDS)
     *
     * @param \DateTimeImmutable $sensorTimestamp When the sensor last reported data
     * @return bool True if refresh is in progress
     */
    public function isRefreshInProgress(\DateTimeImmutable $sensorTimestamp): bool
    {
        $requestedAt = $this->getRefreshRequestedAt();

        if ($requestedAt === null) {
            return false;
        }

        // If sensor timestamp is newer than request, refresh completed
        if ($sensorTimestamp >= $requestedAt) {
            return false;
        }

        // Check if request has timed out
        $now = new \DateTimeImmutable();
        $elapsed = $now->getTimestamp() - $requestedAt->getTimestamp();

        if ($elapsed > self::TIMEOUT_SECONDS) {
            return false;
        }

        // Sensor is stale and we're within timeout window
        return true;
    }

    /**
     * Get the timestamp when refresh was requested, or null if not set.
     */
    public function getRefreshRequestedAt(): ?\DateTimeImmutable
    {
        $state = $this->getState();

        if ($state === null || !isset($state['refresh_requested_at'])) {
            return null;
        }

        try {
            return new \DateTimeImmutable($state['refresh_requested_at']);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Save state to file, creating directory if needed.
     */
    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFilePath);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->stateFilePath,
            json_encode($state, JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}
