<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\Esp32TemperatureService;

/**
 * Controller for receiving temperature data from ESP32 devices.
 */
class Esp32TemperatureController
{
    private Esp32TemperatureService $service;
    private string $apiKey;

    public function __construct(Esp32TemperatureService $service, string $apiKey)
    {
        $this->service = $service;
        $this->apiKey = $apiKey;
    }

    /**
     * Receive temperature data from ESP32.
     *
     * @param array $data Request body data
     * @param string|null $providedApiKey API key from request header
     * @return array Response with status and body
     */
    public function receive(array $data, ?string $providedApiKey): array
    {
        // Validate API key
        if ($providedApiKey === null || $providedApiKey !== $this->apiKey) {
            return [
                'status' => 401,
                'body' => ['error' => 'Invalid or missing API key'],
            ];
        }

        // Validate required fields
        if (!isset($data['device_id']) || !isset($data['temp_c']) || !isset($data['temp_f'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required fields: device_id, temp_c, temp_f'],
            ];
        }

        // Store the temperature data
        $this->service->store($data);

        // Return success with interval for next callback
        return [
            'status' => 200,
            'body' => [
                'status' => 'ok',
                'interval_seconds' => $this->service->getInterval(),
            ],
        ];
    }
}
