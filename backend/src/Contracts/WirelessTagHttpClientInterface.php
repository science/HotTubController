<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * HTTP client interface for WirelessTag API requests.
 *
 * This interface allows the WirelessTagClient to work with either:
 * - CurlWirelessTagHttpClient for real API calls
 * - StubWirelessTagHttpClient for simulated responses
 */
interface WirelessTagHttpClientInterface
{
    /**
     * Make a POST request to the WirelessTag API.
     *
     * @param string $endpoint API endpoint path (e.g., '/GetTagList')
     * @param array $payload Request payload
     * @return array Decoded JSON response
     * @throws \RuntimeException on failure
     */
    public function post(string $endpoint, array $payload): array;
}
