<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\HeatTargetSettingsService;

/**
 * Controller for managing heat-target settings.
 *
 * Provides endpoints to get and update the shared heat-target settings.
 * GET is available to any authenticated user.
 * PUT requires admin role (enforced by router middleware).
 */
class HeatTargetSettingsController
{
    public function __construct(
        private HeatTargetSettingsService $settingsService
    ) {}

    /**
     * Get current heat-target settings.
     *
     * Available to any authenticated user.
     */
    public function get(): array
    {
        $settings = $this->settingsService->getSettings();

        return [
            'status' => 200,
            'body' => [
                'enabled' => $settings['enabled'],
                'target_temp_f' => $settings['target_temp_f'],
                'timezone' => $settings['timezone'],
                'schedule_mode' => $settings['schedule_mode'],
                'stall_grace_period_minutes' => $settings['stall_grace_period_minutes'],
                'stall_timeout_minutes' => $settings['stall_timeout_minutes'],
                'dynamic_mode' => $settings['dynamic_mode'],
                'calibration_points' => $settings['calibration_points'],
            ],
        ];
    }

    /**
     * Update heat-target settings.
     *
     * Requires admin role (enforced by router middleware).
     *
     * @param array $data Request data with 'enabled' (bool) and 'target_temp_f' (float)
     */
    public function update(array $data): array
    {
        // Validate required fields
        if (!isset($data['enabled']) || !isset($data['target_temp_f'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Both enabled and target_temp_f are required'],
            ];
        }

        // Validate types
        if (!is_bool($data['enabled'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'enabled must be a boolean'],
            ];
        }

        if (!is_numeric($data['target_temp_f'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'target_temp_f must be a number'],
            ];
        }

        $enabled = (bool) $data['enabled'];
        $targetTempF = (float) $data['target_temp_f'];

        try {
            $this->settingsService->updateSettings($enabled, $targetTempF);

            if (isset($data['timezone']) && is_string($data['timezone'])) {
                $this->settingsService->updateTimezone($data['timezone']);
            }

            if (isset($data['schedule_mode']) && is_string($data['schedule_mode'])) {
                $this->settingsService->updateScheduleMode($data['schedule_mode']);
            }

            if (isset($data['stall_grace_period_minutes']) || isset($data['stall_timeout_minutes'])) {
                $gracePeriod = isset($data['stall_grace_period_minutes']) && is_numeric($data['stall_grace_period_minutes'])
                    ? (int) $data['stall_grace_period_minutes']
                    : $this->settingsService->getStallGracePeriodMinutes();
                $timeout = isset($data['stall_timeout_minutes']) && is_numeric($data['stall_timeout_minutes'])
                    ? (int) $data['stall_timeout_minutes']
                    : $this->settingsService->getStallTimeoutMinutes();
                $this->settingsService->updateStallSettings($gracePeriod, $timeout);
            }

            if (isset($data['dynamic_mode']) || isset($data['calibration_points'])) {
                if (isset($data['dynamic_mode']) && !is_bool($data['dynamic_mode'])) {
                    return [
                        'status' => 400,
                        'body' => ['error' => 'dynamic_mode must be a boolean'],
                    ];
                }
                if (isset($data['calibration_points']) && !is_array($data['calibration_points'])) {
                    return [
                        'status' => 400,
                        'body' => ['error' => 'calibration_points must be an object'],
                    ];
                }

                $dynamicMode = isset($data['dynamic_mode'])
                    ? (bool) $data['dynamic_mode']
                    : $this->settingsService->isDynamicMode();
                $calibrationPoints = isset($data['calibration_points'])
                    ? $data['calibration_points']
                    : $this->settingsService->getCalibrationPoints();

                $this->settingsService->updateDynamicSettings($dynamicMode, $calibrationPoints);
            }
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        }

        $settings = $this->settingsService->getSettings();

        return [
            'status' => 200,
            'body' => [
                'enabled' => $settings['enabled'],
                'target_temp_f' => $settings['target_temp_f'],
                'timezone' => $settings['timezone'],
                'schedule_mode' => $settings['schedule_mode'],
                'stall_grace_period_minutes' => $settings['stall_grace_period_minutes'],
                'stall_timeout_minutes' => $settings['stall_timeout_minutes'],
                'dynamic_mode' => $settings['dynamic_mode'],
                'calibration_points' => $settings['calibration_points'],
                'message' => 'Settings updated',
            ],
        ];
    }
}
