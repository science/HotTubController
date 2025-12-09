<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\IftttClientInterface;
use HotTub\Contracts\HttpClientInterface;

/**
 * Unified IFTTT webhook client with late-binding HTTP strategy.
 *
 * This class implements the Strategy pattern where ALL business logic is shared
 * between stub and live modes. The only difference is the injected HTTP client:
 * - StubHttpClient: Simulates responses without network calls
 * - CurlHttpClient: Makes real HTTP calls to IFTTT
 *
 * This design ensures:
 * - 100% of business logic (URL building, timing, logging) is shared
 * - Branching happens at the lowest possible level (HTTP transport)
 * - Both modes execute identical code paths until the actual network call
 */
class IftttClient implements IftttClientInterface
{
    private const BASE_URL = 'https://maker.ifttt.com/trigger';

    private string $mode;

    public function __construct(
        private string $apiKey,
        private HttpClientInterface $httpClient,
        private ConsoleLogger $console,
        private EventLogger $logger
    ) {
        // Determine mode from the HTTP client type
        $this->mode = $httpClient instanceof StubHttpClient ? 'stub' : 'live';
    }

    /**
     * Trigger an IFTTT webhook event.
     *
     * This method executes identically for both stub and live modes:
     * 1. Build the webhook URL
     * 2. Record start time
     * 3. Make HTTP request (stub returns simulated response, live makes real call)
     * 4. Calculate duration
     * 5. Log to console and event log
     * 6. Return success/failure
     *
     * @param string $eventName The IFTTT event name to trigger
     * @return bool True on success, false on failure
     */
    public function trigger(string $eventName): bool
    {
        $url = $this->buildUrl($eventName);

        $start = microtime(true);
        $response = $this->httpClient->post($url);
        $durationMs = (int) round((microtime(true) - $start) * 1000);

        $httpCode = $response->getStatusCode();
        $success = $response->isSuccess();

        // Log to console with mode-appropriate formatting
        $this->logToConsole($eventName, $httpCode, $durationMs);

        // Log to event log for audit trail
        $this->logToEventLog($eventName, $httpCode, $durationMs, $success);

        return $success;
    }

    /**
     * Get the current mode of this client.
     *
     * @return string 'stub' or 'live'
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Build the IFTTT webhook URL for an event.
     */
    private function buildUrl(string $eventName): string
    {
        return sprintf(
            '%s/%s/with/key/%s',
            self::BASE_URL,
            $eventName,
            $this->apiKey
        );
    }

    /**
     * Log trigger to console with mode-specific formatting.
     */
    private function logToConsole(string $eventName, int $httpCode, int $durationMs): void
    {
        if ($this->mode === 'stub') {
            $this->console->stub($eventName, $durationMs);
        } else {
            $this->console->live($eventName, $httpCode, $durationMs);
        }
    }

    /**
     * Log trigger to event log for audit trail.
     */
    private function logToEventLog(string $eventName, int $httpCode, int $durationMs, bool $success): void
    {
        $action = $this->mode === 'stub' ? 'ifttt_stub' : 'ifttt_live';

        $data = [
            'event' => $eventName,
            'duration_ms' => $durationMs,
        ];

        if ($this->mode === 'stub') {
            $data['simulated'] = true;
        } else {
            $data['http_code'] = $httpCode;
            $data['success'] = $success;
        }

        $this->logger->log($action, $data);
    }
}
