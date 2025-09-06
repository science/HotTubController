<?php

declare(strict_types=1);

namespace HotTubController\Infrastructure\Http;

use HotTubController\Domain\Proxy\HttpResponse;

class CurlHttpClient implements HttpClientInterface
{
    public function __construct(
        private int $defaultTimeout = 30,
        private string $userAgent = 'HotTubController/1.0',
        private bool $verifySsl = true
    ) {}

    public function request(string $url, string $method, array $options = []): HttpResponse
    {
        $ch = curl_init();
        
        // Basic cURL options
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $options['timeout'] ?? $this->defaultTimeout,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => false,
        ]);

        // Add headers if provided
        if (isset($options['headers']) && is_array($options['headers'])) {
            $headers = [];
            foreach ($options['headers'] as $key => $value) {
                $headers[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        // Add body for POST/PUT/PATCH requests
        if (isset($options['body']) && in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'])) {
            $body = is_array($options['body']) ? json_encode($options['body']) : $options['body'];
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        // Execute request
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return HttpResponse::error('cURL error: ' . $error, 0);
        }

        if ($response === false) {
            return HttpResponse::error('Failed to execute cURL request', 0);
        }

        // Separate headers and body
        $headers = $this->parseHeaders(substr($response, 0, $headerSize));
        $body = substr($response, $headerSize);

        return new HttpResponse($httpCode, $body, $headers);
    }

    private function parseHeaders(string $headerString): array
    {
        $headers = [];
        $lines = explode("\r\n", $headerString);
        
        foreach ($lines as $line) {
            if (strpos($line, ':') !== false) {
                [$key, $value] = explode(':', $line, 2);
                $headers[trim($key)] = trim($value);
            }
        }
        
        return $headers;
    }
}