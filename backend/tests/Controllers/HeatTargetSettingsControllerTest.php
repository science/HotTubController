<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use PHPUnit\Framework\TestCase;
use HotTub\Controllers\HeatTargetSettingsController;
use HotTub\Services\HeatTargetSettingsService;

/**
 * Unit tests for HeatTargetSettingsController.
 *
 * Tests the API endpoints for managing heat-target settings.
 */
class HeatTargetSettingsControllerTest extends TestCase
{
    private string $settingsFile;
    private HeatTargetSettingsService $service;
    private HeatTargetSettingsController $controller;

    protected function setUp(): void
    {
        $this->settingsFile = sys_get_temp_dir() . '/test_heat_target_settings_' . uniqid() . '.json';
        $this->service = new HeatTargetSettingsService($this->settingsFile);
        $this->controller = new HeatTargetSettingsController($this->service);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->settingsFile)) {
            unlink($this->settingsFile);
        }
    }

    // ==================== Get Settings Tests ====================

    /**
     * @test
     */
    public function getReturnsDefaultSettings(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('enabled', $response['body']);
        $this->assertArrayHasKey('target_temp_f', $response['body']);
        $this->assertFalse($response['body']['enabled']);
        $this->assertEquals(103.0, $response['body']['target_temp_f']);
    }

    /**
     * @test
     */
    public function getReturnsUpdatedSettings(): void
    {
        $this->service->updateSettings(true, 105.5);

        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['enabled']);
        $this->assertEquals(105.5, $response['body']['target_temp_f']);
    }

    // ==================== Update Settings Tests ====================

    /**
     * @test
     */
    public function updateSetsEnabledAndTemperature(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['enabled']);
        $this->assertEquals(104.0, $response['body']['target_temp_f']);
        $this->assertArrayHasKey('message', $response['body']);
    }

    /**
     * @test
     */
    public function updatePersistsSettings(): void
    {
        $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 106.0,
        ]);

        $this->assertTrue($this->service->isEnabled());
        $this->assertEquals(106.0, $this->service->getTargetTempF());
    }

    /**
     * @test
     */
    public function updateCanDisable(): void
    {
        // Enable first
        $this->service->updateSettings(true, 105.0);

        // Disable via controller
        $response = $this->controller->update([
            'enabled' => false,
            'target_temp_f' => 105.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertFalse($response['body']['enabled']);
        $this->assertFalse($this->service->isEnabled());
    }

    // ==================== Validation Tests ====================

    /**
     * @test
     */
    public function updateReturns400WhenEnabledMissing(): void
    {
        $response = $this->controller->update([
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenTargetTempMissing(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenEmptyData(): void
    {
        $response = $this->controller->update([]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenTemperatureTooLow(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 79.0,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
        $this->assertStringContainsString('between', $response['body']['error']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenTemperatureTooHigh(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 111.0,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenEnabledNotBoolean(): void
    {
        $response = $this->controller->update([
            'enabled' => 'yes',
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400WhenTargetTempNotNumeric(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 'hot',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateAcceptsIntegerTemperature(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(104.0, $response['body']['target_temp_f']);
    }

    /**
     * @test
     */
    public function updateAcceptsQuarterDegreeTemperature(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 103.25,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(103.25, $response['body']['target_temp_f']);
    }

    // ==================== Timezone Tests ====================

    /**
     * @test
     */
    public function getReturnsTimezone(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('timezone', $response['body']);
        $this->assertEquals('America/Los_Angeles', $response['body']['timezone']);
    }

    /**
     * @test
     */
    public function updateAcceptsTimezone(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'timezone' => 'America/New_York',
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('America/New_York', $response['body']['timezone']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidTimezone(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'timezone' => 'Fake/Zone',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('Invalid timezone', $response['body']['error']);
    }

    /**
     * @test
     */
    public function updatePreservesTimezoneWhenNotProvided(): void
    {
        // Set timezone first
        $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'timezone' => 'America/Chicago',
        ]);

        // Update without timezone
        $response = $this->controller->update([
            'enabled' => false,
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('America/Chicago', $response['body']['timezone']);
    }

    // ==================== Schedule Mode Tests ====================

    /**
     * @test
     */
    public function getReturnsScheduleMode(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('schedule_mode', $response['body']);
        $this->assertEquals('start_at', $response['body']['schedule_mode']);
    }

    /**
     * @test
     */
    public function updateAcceptsScheduleMode(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'schedule_mode' => 'ready_by',
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('ready_by', $response['body']['schedule_mode']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidScheduleMode(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'schedule_mode' => 'invalid',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('Invalid schedule mode', $response['body']['error']);
    }

    /**
     * @test
     */
    public function updatePreservesScheduleModeWhenNotProvided(): void
    {
        // Set schedule_mode first
        $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'schedule_mode' => 'ready_by',
        ]);

        // Update without schedule_mode
        $response = $this->controller->update([
            'enabled' => false,
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals('ready_by', $response['body']['schedule_mode']);
    }
}
