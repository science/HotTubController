<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Interface for crontab operations.
 * Allows mocking in tests without touching actual system crontab.
 */
interface CrontabAdapterInterface
{
    /**
     * Add an entry to the crontab.
     *
     * @param string $entry Full crontab line including schedule and command
     */
    public function addEntry(string $entry): void;

    /**
     * Remove entries matching a pattern from crontab.
     *
     * @param string $pattern Pattern to match (used with grep -v)
     */
    public function removeByPattern(string $pattern): void;

    /**
     * List all crontab entries.
     *
     * @return array<string> Array of crontab lines
     */
    public function listEntries(): array;
}
