<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\Esp32FirmwareService;
use PHPUnit\Framework\TestCase;

class Esp32FirmwareServiceTest extends TestCase
{
    private string $tempDir;
    private string $firmwareDir;
    private string $configFile;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/esp32-firmware-test-' . uniqid();
        $this->firmwareDir = $this->tempDir . '/firmware';
        $this->configFile = $this->tempDir . '/firmware-config.json';

        mkdir($this->firmwareDir, 0755, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $this->recursiveDelete($this->tempDir);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    // === Tests for getting current firmware info ===

    public function testGetCurrentVersionReturnsNullWhenNoConfigExists(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertNull($service->getCurrentVersion());
    }

    public function testGetCurrentVersionReturnsVersionFromConfig(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware-1.2.0.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertEquals('1.2.0', $service->getCurrentVersion());
    }

    public function testGetFirmwareFilenameReturnsNullWhenNoConfig(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertNull($service->getFirmwareFilename());
    }

    public function testGetFirmwareFilenameReturnsFilenameFromConfig(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware-1.2.0.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertEquals('firmware-1.2.0.bin', $service->getFirmwareFilename());
    }

    // === Tests for checking if update is available ===

    public function testIsUpdateAvailableReturnsFalseWhenNoFirmwareConfigured(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertFalse($service->isUpdateAvailable('1.0.0'));
    }

    public function testIsUpdateAvailableReturnsTrueWhenDeviceVersionIsOlder(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertTrue($service->isUpdateAvailable('1.0.0'));
        $this->assertTrue($service->isUpdateAvailable('1.1.0'));
        $this->assertTrue($service->isUpdateAvailable('1.1.9'));
    }

    public function testIsUpdateAvailableReturnsTrueWhenDeviceVersionIsNewer(): void
    {
        // This enables rollback - device can "downgrade" to server version
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertTrue($service->isUpdateAvailable('1.3.0'));
        $this->assertTrue($service->isUpdateAvailable('2.0.0'));
    }

    public function testIsUpdateAvailableReturnsFalseWhenDeviceVersionIsSame(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertFalse($service->isUpdateAvailable('1.2.0'));
    }

    // === Tests for getting firmware path ===

    public function testGetFirmwarePathReturnsNullWhenNoConfig(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertNull($service->getFirmwarePath());
    }

    public function testGetFirmwarePathReturnsFullPath(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware-1.2.0.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertEquals($this->firmwareDir . '/firmware-1.2.0.bin', $service->getFirmwarePath());
    }

    public function testFirmwareExistsReturnsFalseWhenFileDoesNotExist(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware-1.2.0.bin']);

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertFalse($service->firmwareExists());
    }

    public function testFirmwareExistsReturnsTrueWhenFileExists(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware-1.2.0.bin']);
        file_put_contents($this->firmwareDir . '/firmware-1.2.0.bin', 'fake firmware data');

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $this->assertTrue($service->firmwareExists());
    }

    // === Tests for getting firmware info for API response ===

    public function testGetFirmwareInfoForApiReturnsEmptyArrayWhenNoFirmware(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $info = $service->getFirmwareInfoForApi('1.0.0', 'https://example.com');

        $this->assertEquals([], $info);
    }

    public function testGetFirmwareInfoForApiReturnsEmptyArrayWhenVersionsMatch(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);
        file_put_contents($this->firmwareDir . '/firmware.bin', 'fake firmware');

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        // Same version - no update needed
        $info = $service->getFirmwareInfoForApi('1.2.0', 'https://example.com');

        $this->assertEquals([], $info);
    }

    public function testGetFirmwareInfoForApiReturnsInfoForRollback(): void
    {
        // Device has newer version than server - this is a rollback scenario
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);
        file_put_contents($this->firmwareDir . '/firmware.bin', 'fake firmware');

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $info = $service->getFirmwareInfoForApi('1.5.0', 'https://example.com/api');

        $this->assertEquals('1.2.0', $info['firmware_version']);
        $this->assertEquals('https://example.com/api/esp32/firmware/download/', $info['firmware_url']);
    }

    public function testGetFirmwareInfoForApiReturnsUpdateInfoWhenAvailable(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);
        file_put_contents($this->firmwareDir . '/firmware.bin', 'fake firmware');

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $info = $service->getFirmwareInfoForApi('1.0.0', 'https://example.com/api');

        $this->assertEquals('1.2.0', $info['firmware_version']);
        $this->assertEquals('https://example.com/api/esp32/firmware/download/', $info['firmware_url']);
    }

    public function testGetFirmwareInfoForApiReturnsEmptyWhenFirmwareFileMissing(): void
    {
        $this->createConfig(['version' => '1.2.0', 'filename' => 'firmware.bin']);
        // Note: NOT creating the actual firmware file

        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $info = $service->getFirmwareInfoForApi('1.0.0', 'https://example.com');

        $this->assertEquals([], $info);
    }

    // === Tests for setting firmware config ===

    public function testSetFirmwareConfigCreatesConfigFile(): void
    {
        $service = new Esp32FirmwareService($this->firmwareDir, $this->configFile);

        $service->setFirmwareConfig('2.0.0', 'new-firmware.bin');

        $this->assertTrue(file_exists($this->configFile));
        $this->assertEquals('2.0.0', $service->getCurrentVersion());
        $this->assertEquals('new-firmware.bin', $service->getFirmwareFilename());
    }

    // === Helper methods ===

    private function createConfig(array $config): void
    {
        file_put_contents($this->configFile, json_encode($config, JSON_PRETTY_PRINT));
    }
}
