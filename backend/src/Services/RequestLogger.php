<?php

declare(strict_types=1);

namespace HotTub\Services;

use RuntimeException;

/**
 * Service for logging API requests in JSON Lines format.
 *
 * Logs: timestamp, IP, method, URI, status code, response time, username
 * Does NOT log: request bodies (may contain passwords/tokens)
 */
class RequestLogger
{
    public function __construct(
        private string $logFile
    ) {
    }

    /**
     * Log an API request.
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $uri Request URI
     * @param int $statusCode HTTP response status code
     * @param string $ip Client IP address
     * @param float|null $responseTimeMs Response time in milliseconds
     * @param string|null $username Authenticated username (if any)
     * @param string|null $error Error message for failed requests
     */
    public function log(
        string $method,
        string $uri,
        int $statusCode,
        string $ip,
        ?float $responseTimeMs = null,
        ?string $username = null,
        ?string $error = null
    ): void {
        // Ensure log directory exists
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                throw new RuntimeException('Failed to create log directory: ' . $dir);
            }
        }

        // Build log entry
        $entry = [
            'timestamp' => date('c'),
            'ip' => $ip,
            'method' => $method,
            'uri' => $uri,
            'status' => $statusCode,
        ];

        // Add optional fields
        if ($responseTimeMs !== null) {
            $entry['response_time_ms'] = $responseTimeMs;
        }

        if ($username !== null) {
            $entry['user'] = $username;
        }

        if ($error !== null) {
            $entry['error'] = $error;
        }

        // Write as JSON line
        $line = json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n";
        file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get the configured log file path.
     */
    public function getLogPath(): string
    {
        return $this->logFile;
    }
}
