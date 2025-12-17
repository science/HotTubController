<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\WirelessTagHttpClientInterface;
use RuntimeException;

/**
 * Stub HTTP client for WirelessTag API.
 *
 * Returns realistic simulated responses without making network calls.
 * Useful for testing and development without hitting the real API.
 */
class StubWirelessTagHttpClient implements WirelessTagHttpClientInterface
{
    private const SIMULATED_DELAY_MS = 50;

    // Realistic default values based on actual sensor data
    private float $waterTempC = 36.5;     // ~97.7°F - typical hot tub temp

    public function __construct()
    {
        // Tripwire: Stub client should never be instantiated in live mode
        $apiMode = getenv('EXTERNAL_API_MODE') ?: ($_ENV['EXTERNAL_API_MODE'] ?? 'auto');
        if ($apiMode === 'live') {
            throw new RuntimeException(
                'StubWirelessTagHttpClient instantiated while EXTERNAL_API_MODE=live. ' .
                'This indicates a configuration bug - the factory should have created a live client.'
            );
        }
    }
    private float $ambientTempC = 15.0;   // ~59°F - typical outdoor temp
    private float $batteryVoltage = 3.5;
    private int $signalDbm = -65;
    private string $deviceUuid = 'stub-device-uuid-12345';
    private string $deviceName = 'Hot tub temperature (stub)';

    /**
     * Configure stub temperature for testing specific scenarios.
     */
    public function setWaterTemperature(float $celsius): self
    {
        $this->waterTempC = $celsius;
        return $this;
    }

    /**
     * Configure stub ambient temperature.
     */
    public function setAmbientTemperature(float $celsius): self
    {
        $this->ambientTempC = $celsius;
        return $this;
    }

    /**
     * Configure stub battery voltage.
     */
    public function setBatteryVoltage(float $voltage): self
    {
        $this->batteryVoltage = $voltage;
        return $this;
    }

    /**
     * Configure stub signal strength.
     */
    public function setSignalStrength(int $dbm): self
    {
        $this->signalDbm = $dbm;
        return $this;
    }

    /**
     * Configure stub device info.
     */
    public function setDeviceInfo(string $uuid, string $name): self
    {
        $this->deviceUuid = $uuid;
        $this->deviceName = $name;
        return $this;
    }

    /**
     * Simulate POST request to WirelessTag API.
     */
    public function post(string $endpoint, array $payload): array
    {
        // Simulate network latency
        usleep(self::SIMULATED_DELAY_MS * 1000);

        // Return response based on endpoint
        return match ($endpoint) {
            '/GetTagList' => $this->getTagListResponse(),
            '/RequestImmediatePostback' => $this->getPostbackResponse(),
            default => ['d' => []],
        };
    }

    /**
     * Generate realistic GetTagList response.
     */
    private function getTagListResponse(): array
    {
        return [
            'd' => [
                [
                    'uuid' => $this->deviceUuid,
                    'name' => $this->deviceName,
                    'temperature' => $this->waterTempC,
                    'cap' => $this->ambientTempC,
                    'batteryVolt' => $this->batteryVoltage,
                    'signaldBm' => $this->signalDbm,
                    'lastComm' => $this->generateNetTimestamp(),
                    'alive' => true,
                ],
            ],
        ];
    }

    /**
     * Generate RequestImmediatePostback response.
     */
    private function getPostbackResponse(): array
    {
        return ['d' => true];
    }

    /**
     * Generate .NET timestamp (ticks since 0001-01-01).
     */
    private function generateNetTimestamp(): int
    {
        // Convert current Unix timestamp to .NET ticks
        $unixTimestamp = time();
        $dotNetEpochOffset = 621355968000000000;
        return ($unixTimestamp * 10000000) + $dotNetEpochOffset;
    }
}
