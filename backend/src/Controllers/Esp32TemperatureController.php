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
     * Accepts two formats:
     * 1. Multi-sensor: {device_id, sensors: [{address, temp_c, temp_f?}, ...], uptime_seconds}
     * 2. Legacy single: {device_id, temp_c, temp_f, uptime_seconds}
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

        // Validate device_id is always required
        if (!isset($data['device_id'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required field: device_id'],
            ];
        }

        // Check for multi-sensor format
        if (isset($data['sensors']) && is_array($data['sensors'])) {
            // Validate sensors array
            foreach ($data['sensors'] as $sensor) {
                if (!isset($sensor['address']) || !isset($sensor['temp_c'])) {
                    return [
                        'status' => 400,
                        'body' => ['error' => 'Each sensor must have address and temp_c'],
                    ];
                }
            }
        } elseif (isset($data['temp_c']) && isset($data['temp_f'])) {
            // Legacy single-sensor format - convert to sensors array
            $data['sensors'] = [
                [
                    'address' => 'legacy',
                    'temp_c' => $data['temp_c'],
                    'temp_f' => $data['temp_f'],
                ]
            ];
        } else {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required fields: sensors array or temp_c/temp_f'],
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
