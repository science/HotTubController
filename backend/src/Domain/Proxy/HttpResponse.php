<?php

declare(strict_types=1);

namespace HotTubController\Domain\Proxy;

class HttpResponse
{
    public function __construct(
        private int $statusCode,
        private string $body,
        private array $headers = [],
        private ?string $error = null
    ) {}

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function isSuccessful(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300 && $this->error === null;
    }

    public function toArray(): array
    {
        $result = [
            'success' => $this->isSuccessful(),
            'http_code' => $this->statusCode,
            'data' => $this->body,
        ];

        if ($this->error !== null) {
            $result['error'] = $this->error;
        }

        // Try to parse response as JSON
        if ($this->body) {
            $decoded = json_decode($this->body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $result['parsed_data'] = $decoded;
            }
        }

        return $result;
    }

    public static function error(string $error, int $statusCode = 0): self
    {
        return new self($statusCode, '', [], $error);
    }
}