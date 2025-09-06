<?php

declare(strict_types=1);

namespace HotTubController\Infrastructure\Http;

use HotTubController\Domain\Proxy\HttpResponse;

interface HttpClientInterface
{
    /**
     * Make an HTTP request
     * 
     * @param array $options {
     *     headers: array<string, string>,
     *     body: string|array,
     *     timeout: int
     * }
     */
    public function request(string $url, string $method, array $options = []): HttpResponse;
}