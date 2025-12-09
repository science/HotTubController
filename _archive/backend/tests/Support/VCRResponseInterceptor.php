<?php

declare(strict_types=1);

namespace HotTubController\Tests\Support;

use VCR\Request;
use VCR\Response;
use InvalidArgumentException;

/**
 * VCR Response Interceptor
 *
 * Intercepts VCR responses before playback to inject dynamic temperature
 * values based on test context while preserving response structure.
 */
class VCRResponseInterceptor
{
    private array $temperatureData = [];
    private int $currentReadingIndex = 0;
    private bool $interceptEnabled = false;

    /**
     * Enable interception with temperature data
     *
     * @param array $temperatureSequence Array of temperature readings to inject
     */
    public function enable(array $temperatureSequence): void
    {
        $this->temperatureData = $temperatureSequence;
        $this->currentReadingIndex = 0;
        $this->interceptEnabled = true;
    }

    /**
     * Disable interception
     */
    public function disable(): void
    {
        $this->interceptEnabled = false;
        $this->temperatureData = [];
        $this->currentReadingIndex = 0;
    }

    /**
     * Intercept and modify VCR response
     *
     * This method is called by VCR before returning a recorded response
     *
     * @param Request $request The HTTP request being made
     * @param Response $response The recorded response from cassette
     * @return Response Modified response with injected temperature data
     */
    public function intercept(Request $request, Response $response): Response
    {
        if (!$this->interceptEnabled) {
            return $response;
        }

        // Only intercept WirelessTag API calls
        if (!$this->isWirelessTagRequest($request)) {
            return $response;
        }

        // Get the next temperature reading
        $temperatureReading = $this->getNextTemperatureReading();
        if (!$temperatureReading) {
            // No more temperature data, return original response
            return $response;
        }

        // Inject temperature data into response body
        $modifiedBody = $this->injectTemperatureData(
            $response->getBody(),
            $temperatureReading
        );

        // Create new response with modified body
        return new Response(
            (string) $response->getStatusCode(),
            $response->getHeaders(),
            $modifiedBody
        );
    }

    /**
     * Check if request is to WirelessTag API
     */
    private function isWirelessTagRequest(Request $request): bool
    {
        $url = $request->getUrl();
        return $url !== null && strpos($url, 'wirelesstag.net') !== false;
    }

    /**
     * Get the next temperature reading from the sequence
     */
    private function getNextTemperatureReading(): ?array
    {
        if ($this->currentReadingIndex >= count($this->temperatureData)) {
            return null;
        }

        $reading = $this->temperatureData[$this->currentReadingIndex];
        $this->currentReadingIndex++;

        return $reading;
    }

    /**
     * Inject temperature data into response body
     *
     * @param string $originalBody Original JSON response body
     * @param array $temperatureReading Temperature data to inject
     * @return string Modified JSON response body
     */
    private function injectTemperatureData(string $originalBody, array $temperatureReading): string
    {
        $responseData = json_decode($originalBody, true);

        if (!$responseData || !isset($responseData['d'])) {
            // Can't parse or unexpected format, return original
            return $originalBody;
        }

        // Inject temperature data into the device data
        if (isset($responseData['d'][0])) {
            $device = &$responseData['d'][0];

            // Update temperature fields
            if (isset($temperatureReading['water_temp_c'])) {
                $device['temperature'] = $temperatureReading['water_temp_c'];
            }

            if (isset($temperatureReading['ambient_temp_c'])) {
                $device['cap'] = $temperatureReading['ambient_temp_c'];
            }

            // Update timestamp
            if (isset($temperatureReading['timestamp_ticks'])) {
                $device['lastComm'] = $temperatureReading['timestamp_ticks'];
            }

            // Update battery voltage
            if (isset($temperatureReading['battery_voltage'])) {
                $device['batteryVolt'] = $temperatureReading['battery_voltage'];
            }

            // Update signal strength
            if (isset($temperatureReading['signal_strength'])) {
                $device['signaldBm'] = $temperatureReading['signal_strength'];
            }

            // Handle failure scenarios
            if (isset($temperatureReading['is_failure']) && $temperatureReading['is_failure']) {
                $this->injectFailureData($device, $temperatureReading);
            }
        }

        $encodedResponse = json_encode($responseData);
        return $encodedResponse !== false ? $encodedResponse : $originalBody;
    }

    /**
     * Inject failure data into device response
     */
    private function injectFailureData(array &$device, array $temperatureReading): void
    {
        $failureType = $temperatureReading['failure_type'] ?? 'timeout';

        switch ($failureType) {
            case 'timeout':
                // Simulate communication timeout by returning old timestamp
                $device['lastComm'] = $device['lastComm'] - (5 * 60 * 10000000); // 5 minutes ago
                $device['alive'] = false;
                break;

            case 'invalid_reading':
                // Set invalid temperature values
                $device['temperature'] = -999.0;
                $device['cap'] = -999.0;
                break;

            case 'battery_low':
                // Set very low battery
                $device['batteryVolt'] = 2.1;
                $device['lowBattery'] = true;
                break;
        }
    }

    /**
     * Reset reading index to replay sequence
     */
    public function resetSequence(): void
    {
        $this->currentReadingIndex = 0;
    }

    /**
     * Get current reading index
     */
    public function getCurrentReadingIndex(): int
    {
        return $this->currentReadingIndex;
    }

    /**
     * Check if more readings are available
     */
    public function hasMoreReadings(): bool
    {
        return $this->currentReadingIndex < count($this->temperatureData);
    }

    /**
     * Set specific reading index (for testing specific scenarios)
     */
    public function setReadingIndex(int $index): void
    {
        if ($index < 0 || $index >= count($this->temperatureData)) {
            throw new InvalidArgumentException("Reading index out of range");
        }

        $this->currentReadingIndex = $index;
    }

    /**
     * Get total number of readings in sequence
     */
    public function getTotalReadings(): int
    {
        return count($this->temperatureData);
    }

    /**
     * Peek at next reading without consuming it
     */
    public function peekNextReading(): ?array
    {
        if ($this->currentReadingIndex >= count($this->temperatureData)) {
            return null;
        }

        return $this->temperatureData[$this->currentReadingIndex];
    }

    /**
     * Get all temperature data for inspection
     */
    public function getTemperatureData(): array
    {
        return $this->temperatureData;
    }
}
