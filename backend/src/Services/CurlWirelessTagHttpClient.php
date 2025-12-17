<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\WirelessTagHttpClientInterface;
use RuntimeException;

/**
 * Curl-based HTTP client for WirelessTag API.
 *
 * Makes real HTTP calls to the WirelessTag cloud service.
 */
class CurlWirelessTagHttpClient implements WirelessTagHttpClientInterface
{
    private const BASE_URL = 'https://wirelesstag.net/ethClient.asmx';

    private string $oauthToken;
    private int $timeoutSeconds;

    public function __construct(string $oauthToken, int $timeoutSeconds = 60)
    {
        // Tripwire: Live client should never be instantiated in stub mode
        $apiMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? 'auto');
        if ($apiMode === 'stub') {
            throw new RuntimeException(
                'CurlWirelessTagHttpClient instantiated while EXTERNAL_API_MODE=stub. ' .
                'This indicates a configuration bug - the factory should have created a stub client.'
            );
        }

        if (empty($oauthToken)) {
            throw new RuntimeException('WirelessTag OAuth token cannot be empty');
        }

        $this->oauthToken = $oauthToken;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    /**
     * Make POST request to WirelessTag API.
     */
    public function post(string $endpoint, array $payload): array
    {
        $url = self::BASE_URL . $endpoint;

        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: Bearer ' . $this->oauthToken,
            'User-Agent: HotTubController/1.0',
            'Accept: application/json',
        ];

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException("WirelessTag API request failed: {$curlError}");
        }

        if ($httpCode === 401 || $httpCode === 403) {
            throw new RuntimeException("WirelessTag API authentication failed (HTTP {$httpCode})");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("WirelessTag API error: HTTP {$httpCode}");
        }

        $decoded = json_decode($response, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('WirelessTag API returned invalid JSON: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
