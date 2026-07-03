<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HttpResponse;
use HotTub\Contracts\JsonHttpClientInterface;

/**
 * Live JSON HTTP client (cURL).
 *
 * Unlike CurlHttpClient this never follows redirects: requests carry an
 * Authorization header and must not be replayed to a redirect target.
 */
class CurlJsonHttpClient implements JsonHttpClientInterface
{
    public function __construct(private int $timeout = 15)
    {
    }

    public function postJson(string $url, array $body, array $headers = []): HttpResponse
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'HotTubController/2.0',
            CURLOPT_HTTPHEADER => array_merge(
                ['Content-Type: application/json', 'Accept: application/json'],
                $headers
            ),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $responseBody = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($responseBody === false || !empty($error)) {
            return new HttpResponse(0, $error ?: 'Unknown cURL error');
        }

        return new HttpResponse($httpCode, (string) $responseBody);
    }
}
