<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Contract for IFTTT webhook clients.
 *
 * Implementations can be either stub (for testing) or live (for production).
 * The frontend remains completely unaware of which implementation is used.
 */
interface IftttClientInterface
{
    /**
     * Trigger an IFTTT webhook event.
     *
     * @param string $eventName The IFTTT event name to trigger
     * @return bool True on success, false on failure
     */
    public function trigger(string $eventName): bool;

    /**
     * Get the current mode of this client.
     *
     * @return string 'stub' or 'live'
     */
    public function getMode(): string;
}
