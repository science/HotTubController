<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HttpClientInterface;
use HotTub\Contracts\HttpResponse;

/**
 * Stub HTTP client that simulates network responses without making real calls.
 *
 * This implements the Strategy pattern at the lowest level - all business logic
 * in the IFTTT client remains the same, only this final network layer differs
 * between stub and live modes.
 */
class StubHttpClient implements HttpClientInterface
{
    private const SIMULATED_DELAY_MS = 100;

    /**
     * Simulate a POST request without making an actual HTTP call.
     *
     * @param string $url The URL (logged but not actually called)
     * @return HttpResponse Simulated success response
     */
    public function post(string $url): HttpResponse
    {
        // Simulate network latency for realistic behavior
        usleep(self::SIMULATED_DELAY_MS * 1000);

        // Return simulated success response matching IFTTT's typical response
        return new HttpResponse(200, 'Congratulations! You\'ve fired the event');
    }
}
