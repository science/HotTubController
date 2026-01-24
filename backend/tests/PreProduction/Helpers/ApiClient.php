<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction\Helpers;

/**
 * Simple HTTP client for E2E API tests.
 *
 * Provides a clean interface for making authenticated API requests
 * to the test server.
 */
class ApiClient
{
    private string $baseUrl;
    private ?string $authToken;

    public function __construct(string $baseUrl, ?string $authToken = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->authToken = $authToken;
    }

    /**
     * Set the authentication token.
     */
    public function setAuthToken(string $token): void
    {
        $this->authToken = $token;
    }

    /**
     * Make a GET request.
     *
     * @return array{status: int, body: array, headers: array}
     */
    public function get(string $endpoint): array
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * Make a POST request.
     *
     * @return array{status: int, body: array, headers: array}
     */
    public function post(string $endpoint, ?array $body = null): array
    {
        return $this->request('POST', $endpoint, $body);
    }

    /**
     * Make a DELETE request.
     *
     * @return array{status: int, body: array, headers: array}
     */
    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Make a PUT request.
     *
     * @return array{status: int, body: array, headers: array}
     */
    public function put(string $endpoint, ?array $body = null): array
    {
        return $this->request('PUT', $endpoint, $body);
    }

    /**
     * Make an HTTP request.
     *
     * @return array{status: int, body: array, headers: array}
     */
    private function request(string $method, string $endpoint, ?array $body = null): array
    {
        $url = $this->baseUrl . $endpoint;

        $headers = ['Content-Type: application/json'];
        if ($this->authToken) {
            $headers[] = 'Authorization: Bearer ' . $this->authToken;
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ];

        if ($body !== null) {
            $opts['http']['content'] = json_encode($body);
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        // Parse status code and headers
        $status = 500;
        $responseHeaders = [];
        if (isset($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('/HTTP\/\d\.\d\s+(\d+)/', $header, $matches)) {
                    $status = (int) $matches[1];
                } elseif (str_contains($header, ':')) {
                    [$name, $value] = explode(':', $header, 2);
                    $responseHeaders[trim($name)] = trim($value);
                }
            }
        }

        return [
            'status' => $status,
            'body' => json_decode($response ?: '{}', true) ?? [],
            'headers' => $responseHeaders,
        ];
    }

    /**
     * Assert a response was successful (2xx status).
     */
    public function assertSuccess(array $response, string $context = ''): void
    {
        $prefix = $context ? "$context: " : '';
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException(
                "{$prefix}Expected success but got {$response['status']}: " .
                json_encode($response['body'])
            );
        }
    }
}
