<?php

declare(strict_types=1);

namespace HotTub\Middleware;

/**
 * CORS middleware for handling cross-origin requests.
 */
class CorsMiddleware
{
    /** @var array<string> */
    private array $allowedOrigins;

    /** @var callable */
    private $headerSender;

    /** @var string Default origin for same-origin requests */
    private string $defaultOrigin;

    /**
     * @param array<string> $allowedOrigins List of allowed origins
     * @param callable|null $headerSender Function to send headers (for testing)
     */
    public function __construct(array $allowedOrigins, ?callable $headerSender = null)
    {
        $this->allowedOrigins = $allowedOrigins;
        $this->defaultOrigin = $allowedOrigins[0] ?? '';
        $this->headerSender = $headerSender ?? fn(string $h) => header($h);
    }

    /**
     * Handle CORS headers and preflight requests.
     *
     * @return array{status: int, body: string}|null Response for preflight, null otherwise
     */
    public function handle(string $origin, string $method): ?array
    {
        $this->sendHeaders($origin);

        // Handle preflight
        if ($method === 'OPTIONS') {
            return [
                'status' => 204,
                'body' => '',
            ];
        }

        return null;
    }

    private function sendHeaders(string $origin): void
    {
        ($this->headerSender)('Content-Type: application/json');

        // Determine which origin to allow
        if (in_array($origin, $this->allowedOrigins, true)) {
            ($this->headerSender)('Access-Control-Allow-Origin: ' . $origin);
        } elseif ($origin === '') {
            // Same-origin requests don't have Origin header - use default
            ($this->headerSender)('Access-Control-Allow-Origin: ' . $this->defaultOrigin);
        }
        // Unknown origins: don't set Access-Control-Allow-Origin

        ($this->headerSender)('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        ($this->headerSender)('Access-Control-Allow-Headers: Content-Type, Authorization');
        ($this->headerSender)('Access-Control-Allow-Credentials: true');
    }
}
