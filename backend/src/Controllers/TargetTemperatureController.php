<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\TargetTemperatureService;

class TargetTemperatureController
{
    private TargetTemperatureService $service;

    public function __construct(TargetTemperatureService $service)
    {
        $this->service = $service;
    }

    /**
     * Start heating to a target temperature.
     * POST /api/equipment/heat-to-target
     */
    public function start(array $input): array
    {
        if (!isset($input['target_temp_f'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required field: target_temp_f'],
            ];
        }

        $targetTempF = (float) $input['target_temp_f'];

        try {
            $this->service->start($targetTempF);
        } catch (\InvalidArgumentException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        }

        $state = $this->service->getState();

        return [
            'status' => 200,
            'body' => $state,
        ];
    }

    /**
     * Get current target heating status.
     * GET /api/equipment/heat-to-target
     */
    public function status(): array
    {
        $state = $this->service->getState();

        return [
            'status' => 200,
            'body' => $state,
        ];
    }

    /**
     * Cancel target heating.
     * DELETE /api/equipment/heat-to-target
     */
    public function cancel(): array
    {
        $this->service->cleanupCronJobs();
        $this->service->stop();

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'message' => 'Target heating cancelled',
            ],
        ];
    }

    /**
     * Check temperature and adjust heater.
     * Called by cron job.
     * POST /api/maintenance/heat-target-check
     */
    public function check(): array
    {
        $result = $this->service->checkAndAdjust();

        return [
            'status' => 200,
            'body' => $result,
        ];
    }
}
