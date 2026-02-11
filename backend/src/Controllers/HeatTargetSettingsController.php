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
                'message' => 'Settings updated',
            ],
        ];
    }
}
