<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * HTTP client for JSON POST requests with custom headers.
 *
 * Companion to HttpClientInterface (which is body-less and header-less,
 * shaped for IFTTT's key-in-URL webhooks). iot-api needs a JSON body and
 * an Authorization header, so this is a separate additive contract rather
 * than a breaking change to the existing one.
 */
interface JsonHttpClientInterface
{
    /**
     * POST a JSON body.
     *
     * @param string $url The URL to POST to
     * @param array<string, mixed> $body JSON-encodable request body
     * @param array<int, string> $headers Extra headers ("Name: value" strings)
     * @return HttpResponse The response (status 0 on transport failure)
     */
    public function postJson(string $url, array $body, array $headers = []): HttpResponse;
}
