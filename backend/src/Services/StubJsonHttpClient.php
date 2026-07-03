<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HttpResponse;
use HotTub\Contracts\JsonHttpClientInterface;
use RuntimeException;

/**
 * Stub JSON HTTP client — same tripwire convention as StubHttpClient:
 * instantiation while EXTERNAL_API_MODE=live is a configuration bug.
 *
 * Records the last request so tests can assert URL/body/headers, and
 * returns a canned iot-api-shaped shadowed response.
 */
class StubJsonHttpClient implements JsonHttpClientInterface
{
    private const SIMULATED_DELAY_MS = 50;

    public ?string $lastUrl = null;

    /** @var array<string, mixed>|null */
    public ?array $lastBody = null;

    /** @var array<int, string>|null */
    public ?array $lastHeaders = null;

    private ?HttpResponse $cannedResponse = null;

    public function __construct()
    {
        $apiMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? 'auto');
        if ($apiMode === 'live') {
            throw new RuntimeException(
                'StubJsonHttpClient instantiated while EXTERNAL_API_MODE=live. ' .
                'This indicates a configuration bug - the factory should have created a live client.'
            );
        }
    }

    public function setResponse(HttpResponse $response): void
    {
        $this->cannedResponse = $response;
    }

    public function postJson(string $url, array $body, array $headers = []): HttpResponse
    {
        usleep(self::SIMULATED_DELAY_MS * 1000);

        $this->lastUrl = $url;
        $this->lastBody = $body;
        $this->lastHeaders = $headers;

        if ($this->cannedResponse !== null) {
            return $this->cannedResponse;
        }

        return new HttpResponse(200, json_encode([
            'ok' => true,
            'device' => $body['device'] ?? null,
            'action' => $body['action'] ?? null,
            'shadowed' => true,
            'reason' => 'stub_mode',
            'stub' => true,
        ], JSON_UNESCAPED_SLASHES));
    }
}
