<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use PHPUnit\Framework\TestCase;
use HotTub\Services\HeatTargetSettingsService;

/**
 * Unit tests for HeatTargetSettingsService.
 */
class HeatTargetSettingsServiceTest extends TestCase
{
    private string $settingsFile;
    private HeatTargetSettingsService $service;

    protected function setUp(): void
    {
        $this->settingsFile = sys_get_temp_dir() . '/test_heat_target_settings_' . uniqid() . '.json';
        $this->service = new HeatTargetSettingsService($this->settingsFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->settingsFile)) {
            unlink($this->settingsFile);
        }
    }

    // ==================== Default Values Tests ====================

    /**
     * @test
     */
    public function getSettingsReturnsDefaultsWhenNoFileExists(): void
    {
        $settings = $this->service->getSettings();

        $this->assertIsArray($settings);
        $this->assertFalse($settings['enabled']);
        $this->assertEquals(103.0, $settings['target_temp_f']);
    }

    /**
     * @test
     */
    public function isEnabledReturnsFalseByDefault(): void
    {
        $this->assertFalse($this->service->isEnabled());
    }

    /**
     * @test
     */
    public function getTargetTempFReturns103ByDefault(): void
    {
        $this->assertEquals(103.0, $this->service->getTargetTempF());
    }

    // ==================== Update Settings Tests ====================

    /**
     * @test
     */
    public function updateSettingsStoresEnabledState(): void
    {
        $this->service->updateSettings(true, 105.0);

        $this->assertTrue($this->service->isEnabled());
    }

    /**
     * @test
     */
    public function updateSettingsStoresTargetTemperature(): void
    {
        $this->service->updateSettings(true, 105.5);

        $this->assertEquals(105.5, $this->service->getTargetTempF());
    }

    /**
     * @test
     */
    public function updateSettingsCanDisable(): void
    {
        // Enable first
        $this->service->updateSettings(true, 105.0);
        $this->assertTrue($this->service->isEnabled());

        // Then disable
        $this->service->updateSettings(false, 105.0);
        $this->assertFalse($this->service->isEnabled());
    }

    /**
     * @test
     */
    public function updateSettingsPreservesTemperatureWhenDisabling(): void
    {
        $this->service->updateSettings(true, 106.0);
        $this->service->updateSettings(false, 106.0);

        $this->assertEquals(106.0, $this->service->getTargetTempF());
    }

    // ==================== Validation Tests ====================

    /**
     * @test
     */
    public function updateSettingsRejectsTemperatureBelowMinimum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target temperature must be between');

        $this->service->updateSettings(true, 79.0);
    }

    /**
     * @test
     */
    public function updateSettingsRejectsTemperatureAboveMaximum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Target temperature must be between');

        $this->service->updateSettings(true, 111.0);
    }

    /**
     * @test
     */
    public function updateSettingsAcceptsBoundaryValues(): void
    {
        $this->service->updateSettings(true, 80.0);
        $this->assertEquals(80.0, $this->service->getTargetTempF());

        $this->service->updateSettings(true, 110.0);
        $this->assertEquals(110.0, $this->service->getTargetTempF());
    }

    /**
     * @test
     */
    public function updateSettingsAcceptsQuarterDegreeValues(): void
    {
        $this->service->updateSettings(true, 103.25);
        $this->assertEquals(103.25, $this->service->getTargetTempF());

        $this->service->updateSettings(true, 103.5);
        $this->assertEquals(103.5, $this->service->getTargetTempF());

        $this->service->updateSettings(true, 103.75);
        $this->assertEquals(103.75, $this->service->getTargetTempF());
    }

    // ==================== Persistence Tests ====================

    /**
     * @test
     */
    public function settingsPersistAcrossInstances(): void
    {
        $this->service->updateSettings(true, 104.5);

        // Create new instance with same file
        $newService = new HeatTargetSettingsService($this->settingsFile);

        $this->assertTrue($newService->isEnabled());
        $this->assertEquals(104.5, $newService->getTargetTempF());
    }

    /**
     * @test
     */
    public function getSettingsIncludesUpdatedAt(): void
    {
        $this->service->updateSettings(true, 105.0);
        $settings = $this->service->getSettings();

        $this->assertArrayHasKey('updated_at', $settings);
        $this->assertNotNull($settings['updated_at']);
    }

    // ==================== getSettings Full Response Tests ====================

    /**
     * @test
     */
    public function getSettingsReturnsAllFields(): void
    {
        $this->service->updateSettings(true, 105.0);
        $settings = $this->service->getSettings();

        $this->assertArrayHasKey('enabled', $settings);
        $this->assertArrayHasKey('target_temp_f', $settings);
        $this->assertArrayHasKey('updated_at', $settings);

        $this->assertTrue($settings['enabled']);
        $this->assertEquals(105.0, $settings['target_temp_f']);
    }

    // ==================== Timezone Tests ====================

    /**
     * @test
     */
    public function getTimezoneReturnsDefaultUsPacific(): void
    {
        $this->assertEquals('America/Los_Angeles', $this->service->getTimezone());
    }

    /**
     * @test
     */
    public function getSettingsIncludesTimezone(): void
    {
        $settings = $this->service->getSettings();
        $this->assertArrayHasKey('timezone', $settings);
        $this->assertEquals('America/Los_Angeles', $settings['timezone']);
    }

    /**
     * @test
     */
    public function updateTimezoneStoresValidTimezone(): void
    {
        $this->service->updateTimezone('America/New_York');
        $this->assertEquals('America/New_York', $this->service->getTimezone());
    }

    /**
     * @test
     */
    public function updateTimezoneRejectsInvalidTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid timezone');

        $this->service->updateTimezone('Fake/Timezone');
    }

    /**
     * @test
     */
    public function timezonePersistsAcrossInstances(): void
    {
        $this->service->updateTimezone('America/Chicago');

        $newService = new HeatTargetSettingsService($this->settingsFile);
        $this->assertEquals('America/Chicago', $newService->getTimezone());
    }

    /**
     * @test
     */
    public function updateSettingsPreservesTimezone(): void
    {
        $this->service->updateTimezone('America/Denver');
        $this->service->updateSettings(true, 105.0);

        $this->assertEquals('America/Denver', $this->service->getTimezone());
    }

    // ==================== Schedule Mode Tests ====================

    /**
     * @test
     */
    public function scheduleModeDefaultsToStartAt(): void
    {
        $this->assertEquals('start_at', $this->service->getScheduleMode());
    }

    /**
     * @test
     */
    public function scheduleModeCanBeSetToReadyBy(): void
    {
        $this->service->updateScheduleMode('ready_by');
        $this->assertEquals('ready_by', $this->service->getScheduleMode());
    }

    /**
     * @test
     */
    public function scheduleModeIncludedInGetSettings(): void
    {
        $settings = $this->service->getSettings();
        $this->assertArrayHasKey('schedule_mode', $settings);
        $this->assertEquals('start_at', $settings['schedule_mode']);
    }

    /**
     * @test
     */
    public function invalidScheduleModeRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid schedule mode');

        $this->service->updateScheduleMode('invalid');
    }

    /**
     * @test
     */
    public function scheduleModePersistsAcrossInstances(): void
    {
        $this->service->updateScheduleMode('ready_by');

        $newService = new HeatTargetSettingsService($this->settingsFile);
        $this->assertEquals('ready_by', $newService->getScheduleMode());
    }

    /**
     * @test
     */
    public function updateSettingsPreservesScheduleMode(): void
    {
        $this->service->updateScheduleMode('ready_by');
        $this->service->updateSettings(true, 105.0);

        $this->assertEquals('ready_by', $this->service->getScheduleMode());
    }

    // ==================== Stall Detection Settings Tests ====================

    /**
     * @test
     */
    public function stallGracePeriodDefaultsTo15Minutes(): void
    {
        $this->assertEquals(15, $this->service->getStallGracePeriodMinutes());
    }

    /**
     * @test
     */
    public function stallTimeoutDefaultsTo5Minutes(): void
    {
        $this->assertEquals(5, $this->service->getStallTimeoutMinutes());
    }

    /**
     * @test
     */
    public function getSettingsIncludesStallDetectionDefaults(): void
    {
        $settings = $this->service->getSettings();

        $this->assertArrayHasKey('stall_grace_period_minutes', $settings);
        $this->assertArrayHasKey('stall_timeout_minutes', $settings);
        $this->assertEquals(15, $settings['stall_grace_period_minutes']);
        $this->assertEquals(5, $settings['stall_timeout_minutes']);
    }

    /**
     * @test
     */
    public function updateStallSettingsStoresValues(): void
    {
        $this->service->updateStallSettings(20, 10);

        $this->assertEquals(20, $this->service->getStallGracePeriodMinutes());
        $this->assertEquals(10, $this->service->getStallTimeoutMinutes());
    }

    /**
     * @test
     */
    public function updateStallSettingsPersistsAcrossInstances(): void
    {
        $this->service->updateStallSettings(25, 8);

        $newService = new HeatTargetSettingsService($this->settingsFile);

        $this->assertEquals(25, $newService->getStallGracePeriodMinutes());
        $this->assertEquals(8, $newService->getStallTimeoutMinutes());
    }

    /**
     * @test
     */
    public function updateStallSettingsRejectsNegativeGracePeriod(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stall grace period must be at least 1 minute');

        $this->service->updateStallSettings(0, 5);
    }

    /**
     * @test
     */
    public function updateStallSettingsRejectsNegativeTimeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Stall timeout must be at least 1 minute');

        $this->service->updateStallSettings(15, 0);
    }

    /**
     * @test
     */
    public function updateSettingsPreservesStallSettings(): void
    {
        $this->service->updateStallSettings(20, 10);
        $this->service->updateSettings(true, 105.0);

        $this->assertEquals(20, $this->service->getStallGracePeriodMinutes());
        $this->assertEquals(10, $this->service->getStallTimeoutMinutes());
    }
}
