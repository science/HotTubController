<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\Esp32ThinHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the ultra-lightweight ESP32 temperature handler.
 *
 * This handler is designed to minimize server load for frequent ESP32 pings.
 */
class Esp32ThinHandlerTest extends TestCase
{
    private string $tempStorageFile;
    private string $tempEnvFile;
    private string $tempEquipmentStatusFile;
    private Esp32ThinHandler $handler;

    protected function setUp(): void
    {
        $this->tempStorageFile = sys_get_temp_dir() . '/esp32-thin-test-' . uniqid() . '.json';
        $this->tempEnvFile = sys_get_temp_dir() . '/esp32-thin-env-' . uniqid() . '.env';
        $this->tempEquipmentStatusFile = sys_get_temp_dir() . '/esp32-thin-equipment-' . uniqid() . '.json';

        // Create test .env with known API key
        file_put_contents($this->tempEnvFile, "ESP32_API_KEY=test-api-key-12345\n");

        $this->handler = new Esp32ThinHandler($this->tempStorageFile, $this->tempEnvFile, $this->tempEquipmentStatusFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->tempStorageFile);
        @unlink($this->tempEnvFile);
        @unlink($this->tempEquipmentStatusFile);
    }

    // ========== API Key Validation Tests ==========

    public function testReturns401WhenApiKeyMissing(): void
    {
        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            null  // No API key
        );

        $this->assertEquals(401, $result['status']);
        $this->assertEquals('Invalid or missing API key', $result['body']['error']);
    }

    public function testReturns401WhenApiKeyInvalid(): void
    {
        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            'wrong-api-key'
        );

        $this->assertEquals(401, $result['status']);
        $this->assertEquals('Invalid or missing API key', $result['body']['error']);
    }

    public function testReturns200WhenApiKeyValid(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('ok', $result['body']['status']);
    }

    public function testReturns500WhenEnvFileMissing(): void
    {
        @unlink($this->tempEnvFile);

        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            'test-api-key-12345'
        );

        $this->assertEquals(500, $result['status']);
        $this->assertStringContainsString('configuration', $result['body']['error']);
    }

    public function testReturns500WhenApiKeyNotInEnv(): void
    {
        file_put_contents($this->tempEnvFile, "OTHER_VAR=value\n");

        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            'test-api-key-12345'
        );

        $this->assertEquals(500, $result['status']);
    }

    // ========== Input Validation Tests ==========

    public function testReturns400WhenDeviceIdMissing(): void
    {
        $result = $this->handler->handle(
            ['sensors' => []],
            'test-api-key-12345'
        );

        $this->assertEquals(400, $result['status']);
        $this->assertStringContainsString('device_id', $result['body']['error']);
    }

    public function testReturns400WhenSensorsMissing(): void
    {
        $result = $this->handler->handle(
            ['device_id' => 'esp32-01'],
            'test-api-key-12345'
        );

        $this->assertEquals(400, $result['status']);
        $this->assertStringContainsString('sensors', $result['body']['error']);
    }

    public function testReturns400WhenSensorMissingAddress(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['temp_c' => 38.5],  // Missing address
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(400, $result['status']);
        $this->assertStringContainsString('address', $result['body']['error']);
    }

    public function testReturns400WhenSensorMissingTempC(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123'],  // Missing temp_c
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(400, $result['status']);
        $this->assertStringContainsString('temp_c', $result['body']['error']);
    }

    // ========== Method Validation Tests ==========

    public function testReturns405ForGetRequest(): void
    {
        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            'test-api-key-12345',
            'GET'
        );

        $this->assertEquals(405, $result['status']);
    }

    public function testReturns405ForPutRequest(): void
    {
        $result = $this->handler->handle(
            ['device_id' => 'esp32-01', 'sensors' => []],
            'test-api-key-12345',
            'PUT'
        );

        $this->assertEquals(405, $result['status']);
    }

    // ========== Data Storage Tests ==========

    public function testStoresTemperatureDataToFile(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
                'uptime_seconds' => 3600,
            ],
            'test-api-key-12345'
        );

        $this->assertFileExists($this->tempStorageFile);

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertEquals('esp32-01', $stored['device_id']);
        $this->assertEquals(3600, $stored['uptime_seconds']);
        $this->assertCount(1, $stored['sensors']);
        $this->assertEquals('28-abc123', $stored['sensors'][0]['address']);
        $this->assertEquals(38.5, $stored['sensors'][0]['temp_c']);
    }

    public function testCalculatesTempFWhenNotProvided(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 100.0],  // Boiling point
                ],
            ],
            'test-api-key-12345'
        );

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertEquals(212.0, $stored['sensors'][0]['temp_f']);  // 100°C = 212°F
    }

    public function testUsesTempFWhenProvided(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5, 'temp_f' => 101.3],
                ],
            ],
            'test-api-key-12345'
        );

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertEquals(101.3, $stored['sensors'][0]['temp_f']);
    }

    public function testIncludesTimestampInStoredData(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertArrayHasKey('timestamp', $stored);
        $this->assertArrayHasKey('received_at', $stored);
        $this->assertNotFalse(strtotime($stored['timestamp']));
    }

    public function testIncludesLegacyTopLevelTempFields(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5, 'temp_f' => 101.3],
                    ['address' => '28-def456', 'temp_c' => 25.0, 'temp_f' => 77.0],
                ],
            ],
            'test-api-key-12345'
        );

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        // Legacy fields should use first sensor
        $this->assertEquals(38.5, $stored['temp_c']);
        $this->assertEquals(101.3, $stored['temp_f']);
    }

    public function testCreatesStorageDirectoryIfMissing(): void
    {
        $nestedPath = sys_get_temp_dir() . '/esp32-test-nested-' . uniqid() . '/state/temp.json';
        $handler = new Esp32ThinHandler($nestedPath, $this->tempEnvFile);

        $handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertFileExists($nestedPath);

        // Cleanup
        @unlink($nestedPath);
        @rmdir(dirname($nestedPath));
        @rmdir(dirname(dirname($nestedPath)));
    }

    // ========== Response Format Tests ==========

    public function testReturnsIntervalInResponse(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(300, $result['body']['interval_seconds']);
    }

    public function testHandlesMultipleSensors(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                    ['address' => '28-def456', 'temp_c' => 25.0],
                    ['address' => '28-ghi789', 'temp_c' => 30.0],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertCount(3, $stored['sensors']);
    }

    public function testHandlesEmptySensorsArray(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertEmpty($stored['sensors']);
        $this->assertArrayNotHasKey('temp_c', $stored);  // No legacy fields when no sensors
    }

    public function testDefaultsUptimeToZero(): void
    {
        $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
                // No uptime_seconds provided
            ],
            'test-api-key-12345'
        );

        $stored = json_decode(file_get_contents($this->tempStorageFile), true);
        $this->assertEquals(0, $stored['uptime_seconds']);
    }

    // ========== Dynamic Interval Tests ==========

    public function testReturns60SecondIntervalWhenHeaterIsOn(): void
    {
        // Set heater to on
        file_put_contents($this->tempEquipmentStatusFile, json_encode([
            'heater' => ['on' => true, 'lastChangedAt' => date('c')],
            'pump' => ['on' => false, 'lastChangedAt' => date('c')],
        ]));

        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(60, $result['body']['interval_seconds']);
    }

    public function testReturns300SecondIntervalWhenHeaterIsOff(): void
    {
        // Set heater to off
        file_put_contents($this->tempEquipmentStatusFile, json_encode([
            'heater' => ['on' => false, 'lastChangedAt' => date('c')],
            'pump' => ['on' => false, 'lastChangedAt' => date('c')],
        ]));

        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(300, $result['body']['interval_seconds']);
    }

    public function testReturns300SecondIntervalWhenEquipmentStatusFileMissing(): void
    {
        // Don't create the equipment status file
        @unlink($this->tempEquipmentStatusFile);

        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(300, $result['body']['interval_seconds']);
    }

    public function testReturns300SecondIntervalWhenEquipmentStatusFileInvalid(): void
    {
        // Write invalid JSON
        file_put_contents($this->tempEquipmentStatusFile, 'not valid json');

        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(300, $result['body']['interval_seconds']);
    }

    public function testReturns300SecondIntervalWhenNoEquipmentStatusFileProvided(): void
    {
        // Create handler without equipment status file
        $handler = new Esp32ThinHandler($this->tempStorageFile, $this->tempEnvFile, null);

        $result = $handler->handle(
            [
                'device_id' => 'esp32-01',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(300, $result['body']['interval_seconds']);
    }

    // ========== Firmware Update Info Tests ==========

    public function testResponseDoesNotIncludeFirmwareInfoWhenNoFirmwareServiceConfigured(): void
    {
        $result = $this->handler->handle(
            [
                'device_id' => 'esp32-01',
                'firmware_version' => '1.0.0',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertArrayNotHasKey('firmware_version', $result['body']);
        $this->assertArrayNotHasKey('firmware_url', $result['body']);
    }

    public function testResponseIncludesFirmwareInfoWhenUpdateAvailable(): void
    {
        // Set up firmware service
        $firmwareDir = sys_get_temp_dir() . '/esp32-firmware-test-' . uniqid();
        $firmwareConfig = $firmwareDir . '/config.json';
        mkdir($firmwareDir, 0755, true);

        // Create firmware config and file
        file_put_contents($firmwareConfig, json_encode([
            'version' => '2.0.0',
            'filename' => 'firmware.bin',
        ]));
        file_put_contents($firmwareDir . '/firmware.bin', 'fake firmware data');

        $handler = new Esp32ThinHandler(
            $this->tempStorageFile,
            $this->tempEnvFile,
            $this->tempEquipmentStatusFile,
            $firmwareDir,
            $firmwareConfig,
            'https://example.com/api'
        );

        $result = $handler->handle(
            [
                'device_id' => 'esp32-01',
                'firmware_version' => '1.0.0',
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertEquals('2.0.0', $result['body']['firmware_version']);
        $this->assertEquals('https://example.com/api/esp32/firmware/download/', $result['body']['firmware_url']);

        // Cleanup
        @unlink($firmwareDir . '/firmware.bin');
        @unlink($firmwareConfig);
        @rmdir($firmwareDir);
    }

    public function testResponseDoesNotIncludeFirmwareInfoWhenDeviceIsUpToDate(): void
    {
        // Set up firmware service
        $firmwareDir = sys_get_temp_dir() . '/esp32-firmware-test-' . uniqid();
        $firmwareConfig = $firmwareDir . '/config.json';
        mkdir($firmwareDir, 0755, true);

        // Create firmware config and file
        file_put_contents($firmwareConfig, json_encode([
            'version' => '1.0.0',
            'filename' => 'firmware.bin',
        ]));
        file_put_contents($firmwareDir . '/firmware.bin', 'fake firmware data');

        $handler = new Esp32ThinHandler(
            $this->tempStorageFile,
            $this->tempEnvFile,
            $this->tempEquipmentStatusFile,
            $firmwareDir,
            $firmwareConfig,
            'https://example.com/api'
        );

        $result = $handler->handle(
            [
                'device_id' => 'esp32-01',
                'firmware_version' => '1.0.0',  // Same as available
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);
        $this->assertArrayNotHasKey('firmware_version', $result['body']);
        $this->assertArrayNotHasKey('firmware_url', $result['body']);

        // Cleanup
        @unlink($firmwareDir . '/firmware.bin');
        @unlink($firmwareConfig);
        @rmdir($firmwareDir);
    }

    public function testResponseDoesNotIncludeFirmwareInfoWhenDeviceDoesNotReportVersion(): void
    {
        // Set up firmware service
        $firmwareDir = sys_get_temp_dir() . '/esp32-firmware-test-' . uniqid();
        $firmwareConfig = $firmwareDir . '/config.json';
        mkdir($firmwareDir, 0755, true);

        // Create firmware config and file
        file_put_contents($firmwareConfig, json_encode([
            'version' => '2.0.0',
            'filename' => 'firmware.bin',
        ]));
        file_put_contents($firmwareDir . '/firmware.bin', 'fake firmware data');

        $handler = new Esp32ThinHandler(
            $this->tempStorageFile,
            $this->tempEnvFile,
            $this->tempEquipmentStatusFile,
            $firmwareDir,
            $firmwareConfig,
            'https://example.com/api'
        );

        $result = $handler->handle(
            [
                'device_id' => 'esp32-01',
                // No firmware_version reported
                'sensors' => [
                    ['address' => '28-abc123', 'temp_c' => 38.5],
                ],
            ],
            'test-api-key-12345'
        );

        $this->assertEquals(200, $result['status']);
        // Should not include firmware info if device doesn't report its version
        $this->assertArrayNotHasKey('firmware_version', $result['body']);
        $this->assertArrayNotHasKey('firmware_url', $result['body']);

        // Cleanup
        @unlink($firmwareDir . '/firmware.bin');
        @unlink($firmwareConfig);
        @rmdir($firmwareDir);
    }
}
