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

    // ==================== Stall Detection Settings Tests ====================

    /**
     * @test
     */
    public function getReturnsStallDetectionDefaults(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('stall_grace_period_minutes', $response['body']);
        $this->assertArrayHasKey('stall_timeout_minutes', $response['body']);
        $this->assertEquals(15, $response['body']['stall_grace_period_minutes']);
        $this->assertEquals(5, $response['body']['stall_timeout_minutes']);
    }

    /**
     * @test
     */
    public function updateAcceptsStallDetectionSettings(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'stall_grace_period_minutes' => 20,
            'stall_timeout_minutes' => 10,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(20, $response['body']['stall_grace_period_minutes']);
        $this->assertEquals(10, $response['body']['stall_timeout_minutes']);
    }

    /**
     * @test
     */
    public function updatePreservesStallSettingsWhenNotProvided(): void
    {
        // Set stall settings first
        $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'stall_grace_period_minutes' => 20,
            'stall_timeout_minutes' => 10,
        ]);

        // Update without stall settings
        $response = $this->controller->update([
            'enabled' => false,
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(20, $response['body']['stall_grace_period_minutes']);
        $this->assertEquals(10, $response['body']['stall_timeout_minutes']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidStallGracePeriod(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'stall_grace_period_minutes' => 0,
            'stall_timeout_minutes' => 5,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('grace period', $response['body']['error']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidStallTimeout(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'stall_grace_period_minutes' => 15,
            'stall_timeout_minutes' => 0,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertStringContainsString('timeout', $response['body']['error']);
    }

    // ==================== Dynamic Mode Tests ====================

    /**
     * @test
     */
    public function getReturnsDynamicModeAndCalibrationPoints(): void
    {
        $response = $this->controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertArrayHasKey('dynamic_mode', $response['body']);
        $this->assertFalse($response['body']['dynamic_mode']);
        $this->assertArrayHasKey('calibration_points', $response['body']);
        $this->assertArrayHasKey('cold', $response['body']['calibration_points']);
        $this->assertArrayHasKey('comfort', $response['body']['calibration_points']);
        $this->assertArrayHasKey('hot', $response['body']['calibration_points']);
    }

    /**
     * @test
     */
    public function updateAcceptsDynamicMode(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'dynamic_mode' => true,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['dynamic_mode']);
    }

    /**
     * @test
     */
    public function updateAcceptsCalibrationPoints(): void
    {
        $calibration = [
            'cold'    => ['ambient_f' => 40.0, 'water_target_f' => 105.0],
            'comfort' => ['ambient_f' => 55.0, 'water_target_f' => 103.0],
            'hot'     => ['ambient_f' => 70.0, 'water_target_f' => 101.0],
        ];

        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'dynamic_mode' => true,
            'calibration_points' => $calibration,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(40.0, $response['body']['calibration_points']['cold']['ambient_f']);
        $this->assertEquals(105.0, $response['body']['calibration_points']['cold']['water_target_f']);
    }

    /**
     * @test
     */
    public function updateReturns400ForInvalidCalibrationPoints(): void
    {
        $calibration = [
            'cold'    => ['ambient_f' => 60.0, 'water_target_f' => 104.0],
            'comfort' => ['ambient_f' => 45.0, 'water_target_f' => 102.0],
            'hot'     => ['ambient_f' => 75.0, 'water_target_f' => 100.5],
        ];

        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'dynamic_mode' => true,
            'calibration_points' => $calibration,
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updatePreservesDynamicSettingsWhenNotProvided(): void
    {
        // Set dynamic settings first
        $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'dynamic_mode' => true,
            'calibration_points' => [
                'cold'    => ['ambient_f' => 40.0, 'water_target_f' => 105.0],
                'comfort' => ['ambient_f' => 55.0, 'water_target_f' => 103.0],
                'hot'     => ['ambient_f' => 70.0, 'water_target_f' => 101.0],
            ],
        ]);

        // Update without dynamic settings
        $response = $this->controller->update([
            'enabled' => false,
            'target_temp_f' => 104.0,
        ]);

        $this->assertEquals(200, $response['status']);
        $this->assertTrue($response['body']['dynamic_mode']);
        $this->assertEquals(40.0, $response['body']['calibration_points']['cold']['ambient_f']);
    }

    /**
     * @test
     */
    public function updateReturns400ForNonBoolDynamicMode(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'dynamic_mode' => 'yes',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }

    /**
     * @test
     */
    public function updateReturns400ForNonArrayCalibrationPoints(): void
    {
        $response = $this->controller->update([
            'enabled' => true,
            'target_temp_f' => 104.0,
            'calibration_points' => 'invalid',
        ]);

        $this->assertEquals(400, $response['status']);
        $this->assertArrayHasKey('error', $response['body']);
    }
}
