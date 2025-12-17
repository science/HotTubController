<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\HttpClientInterface;
use HotTub\Contracts\HttpResponse;
use RuntimeException;

/**
 * cURL-based HTTP client implementation.
 */
class CurlHttpClient implements HttpClientInterface
{
    private int $timeout;

    public function __construct(int $timeout = 30)
    {
        // Tripwire: Live client should never be instantiated in stub mode
        $apiMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? 'auto');
        if ($apiMode === 'stub') {
            throw new RuntimeException(
                'CurlHttpClient instantiated while EXTERNAL_API_MODE=stub. ' .
                'This indicates a configuration bug - the factory should have created a stub client.'
            );
        }

        $this->timeout = $timeout;
    }

    public function post(string $url): HttpResponse
    {
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => 'HotTubController/2.0',
            CURLOPT_HTTPHEADER => ['Accept: */*'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);

        curl_close($curl);

        if ($body === false || !empty($error)) {
            return new HttpResponse(0, $error ?: 'Unknown cURL error');
        }

        return new HttpResponse($httpCode, $body);
    }
}
