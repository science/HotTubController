<?php

declare(strict_types=1);

namespace HotTub\Tests\PreProduction\Helpers;

/**
 * Simulates ESP32 temperature sensor for E2E tests.
 *
 * Reports temperature via the REAL ESP32 API endpoint, ensuring
 * E2E tests use identical code paths as production hardware.
 */
class Esp32Simulator
{
    private string $apiBaseUrl;
    private string $apiKey;
    private string $deviceId;

    public function __construct(
        string $apiBaseUrl,
        string $apiKey,
        string $deviceId = 'E2E:SIM:AA:BB:CC:DD'
    ) {
        $this->apiBaseUrl = rtrim($apiBaseUrl, '/');
        $this->apiKey = $apiKey;
        $this->deviceId = $deviceId;
    }

    /**
     * Report temperature via the real ESP32 API endpoint.
     *
     * This calls POST /api/esp32/temperature exactly as the real
     * ESP32 hardware does, ensuring the full code path is tested.
     *
     * @param float $tempF Temperature in Fahrenheit
     * @param int|null $uptimeSeconds Optional uptime (defaults to simulated value)
     * @return array{status: int, body: array}
     */
    public function reportTemperature(float $tempF, ?int $uptimeSeconds = null): array
    {
        $tempC = ($tempF - 32) * 5 / 9;

        $payload = [
            'device_id' => $this->deviceId,
            'sensors' => [
                [
                    'address' => '28:E2:E2:SI:MU:LA:TE:01',
                    'temp_c' => round($tempC, 2),
                ],
            ],
            'uptime_seconds' => $uptimeSeconds ?? $this->generateUptime(),
        ];

        return $this->postToApi('/api/esp32/temperature', $payload);
    }

    /**
     * Report temperature with a specific "age" (for testing stale data scenarios).
     *
     * This writes directly to the state file with a backdated received_at,
     * simulating an ESP32 that reported N seconds ago.
     *
     * Note: This bypasses the API for testing edge cases only.
     *
     * @param float $tempF Temperature in Fahrenheit
     * @param int $secondsAgo How old the reading should appear
     * @param string $stateFilePath Path to ESP32 state file
     */
    public function reportTemperatureWithAge(
        float $tempF,
        int $secondsAgo,
        string $stateFilePath
    ): void {
        $tempC = ($tempF - 32) * 5 / 9;
        $receivedAt = time() - $secondsAgo;

        $data = [
            'device_id' => $this->deviceId,
            'sensors' => [
                [
                    'address' => '28:E2:E2:SI:MU:LA:TE:01',
                    'temp_c' => round($tempC, 2),
                    'temp_f' => round($tempF, 2),
                ],
            ],
            'uptime_seconds' => $this->generateUptime(),
            'timestamp' => date('c', $receivedAt),
            'received_at' => $receivedAt,
            'temp_c' => round($tempC, 2),
            'temp_f' => round($tempF, 2),
        ];

        $dir = dirname($stateFilePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($stateFilePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Simulate gradual temperature change (heating or cooling).
     *
     * Yields temperature values for each step, allowing tests to
     * intersperse cron executions between temperature reports.
     *
     * @param float $startF Starting temperature
     * @param float $endF Target temperature
     * @param int $steps Number of intermediate readings
     * @return \Generator<float> Yields temperature values
     */
    public function simulateTemperatureChange(float $startF, float $endF, int $steps): \Generator
    {
        $increment = ($endF - $startF) / $steps;

        for ($i = 0; $i <= $steps; $i++) {
            yield $startF + ($increment * $i);
        }
    }

    /**
     * Make HTTP POST request to the API.
     *
     * @return array{status: int, body: array}
     */
    private function postToApi(string $endpoint, array $payload): array
    {
        $url = $this->apiBaseUrl . $endpoint;

        $opts = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'X-ESP32-API-KEY: ' . $this->apiKey,
                ]),
                'content' => json_encode($payload),
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ];

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        // Parse status code
        $status = 500;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\d\.\d\s+(\d+)/', $http_response_header[0], $matches);
            $status = (int) ($matches[1] ?? 500);
        }

        return [
            'status' => $status,
            'body' => json_decode($response ?: '{}', true) ?? [],
        ];
    }

    /**
     * Generate a realistic-looking uptime value.
     */
    private function generateUptime(): int
    {
        static $baseUptime = null;
        static $startTime = null;

        if ($baseUptime === null) {
            $baseUptime = random_int(1000, 100000);
            $startTime = time();
        }

        return $baseUptime + (time() - $startTime);
    }
}
