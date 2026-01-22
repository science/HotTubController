<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Manages ESP32 firmware files for HTTP-based OTA updates.
 *
 * This service handles:
 * - Tracking the current available firmware version
 * - Checking if updates are available for devices
 * - Providing firmware info for API responses
 * - Locating firmware files for download
 */
class Esp32FirmwareService
{
    private string $firmwareDir;
    private string $configFile;

    public function __construct(string $firmwareDir, string $configFile)
    {
        $this->firmwareDir = $firmwareDir;
        $this->configFile = $configFile;
    }

    /**
     * Get the current available firmware version.
     */
    public function getCurrentVersion(): ?string
    {
        $config = $this->loadConfig();
        return $config['version'] ?? null;
    }

    /**
     * Get the current firmware filename.
     */
    public function getFirmwareFilename(): ?string
    {
        $config = $this->loadConfig();
        return $config['filename'] ?? null;
    }

    /**
     * Get the full path to the firmware file.
     */
    public function getFirmwarePath(): ?string
    {
        $filename = $this->getFirmwareFilename();
        if ($filename === null) {
            return null;
        }
        return $this->firmwareDir . '/' . $filename;
    }

    /**
     * Check if the firmware file exists on disk.
     */
    public function firmwareExists(): bool
    {
        $path = $this->getFirmwarePath();
        return $path !== null && file_exists($path);
    }

    /**
     * Check if an update is available for the given device version.
     *
     * @param string $deviceVersion Current version running on the device
     */
    public function isUpdateAvailable(string $deviceVersion): bool
    {
        $currentVersion = $this->getCurrentVersion();
        if ($currentVersion === null) {
            return false;
        }

        return version_compare($deviceVersion, $currentVersion, '<');
    }

    /**
     * Get firmware info to include in API response.
     *
     * Returns an array with firmware_version and firmware_url if an update
     * is available, or an empty array if no update is needed.
     *
     * @param string $deviceVersion Current version running on the device
     * @param string $baseUrl The base URL for the API (e.g., https://example.com/api)
     */
    public function getFirmwareInfoForApi(string $deviceVersion, string $baseUrl): array
    {
        if (!$this->isUpdateAvailable($deviceVersion)) {
            return [];
        }

        if (!$this->firmwareExists()) {
            return [];
        }

        $version = $this->getCurrentVersion();
        $url = rtrim($baseUrl, '/') . '/esp32/firmware/download';

        return [
            'firmware_version' => $version,
            'firmware_url' => $url,
        ];
    }

    /**
     * Set the firmware configuration.
     *
     * @param string $version The firmware version
     * @param string $filename The firmware filename
     */
    public function setFirmwareConfig(string $version, string $filename): void
    {
        $config = [
            'version' => $version,
            'filename' => $filename,
            'updated_at' => date('c'),
        ];

        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
    }

    /**
     * Load the firmware configuration from file.
     */
    private function loadConfig(): array
    {
        if (!file_exists($this->configFile)) {
            return [];
        }

        $content = file_get_contents($this->configFile);
        if ($content === false) {
            return [];
        }

        $config = json_decode($content, true);
        return is_array($config) ? $config : [];
    }
}
