<?php

declare(strict_types=1);

namespace HotTub\Contracts;

/**
 * Simple HTTP client interface for making requests.
 */
interface HttpClientInterface
{
    /**
     * Make a POST request.
     *
     * @param string $url The URL to POST to
     * @return HttpResponse The response
     */
    public function post(string $url): HttpResponse;
}
