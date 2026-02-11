<?php

declare(strict_types=1);

namespace HotTub\Controllers;

use HotTub\Services\SchedulerService;
use HotTub\Services\DtdtService;
use HotTub\Services\HeatTargetSettingsService;
use InvalidArgumentException;

/**
 * Controller for schedule management endpoints.
 */
class ScheduleController
{
    public function __construct(
        private SchedulerService $scheduler,
        private ?DtdtService $dtdtService = null,
        private ?HeatTargetSettingsService $settings = null
    ) {
    }

    /**
     * POST /api/schedule - Create a new scheduled job.
     *
     * @param array{action?: string, scheduledTime?: string, recurring?: bool, target_temp_f?: float} $data Request body
     * @return array{status: int, body: array}
     */
    public function create(array $data): array
    {
        // Validate required fields
        if (empty($data['action'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required field: action'],
            ];
        }

        if (empty($data['scheduledTime'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required field: scheduledTime'],
            ];
        }

        // Extract action-specific params (e.g., target_temp_f for heat-to-target)
        $params = [];
        if ($data['action'] === 'heat-to-target') {
            if (!isset($data['target_temp_f'])) {
                return [
                    'status' => 400,
                    'body' => ['error' => 'Missing required field: target_temp_f for heat-to-target action'],
                ];
            }
            $params['target_temp_f'] = (float) $data['target_temp_f'];
        }

        try {
            $recurring = isset($data['recurring']) && $data['recurring'] === true;

            // DTDT transformation: ready_by + heat-to-target + recurring
            if ($data['action'] === 'heat-to-target'
                && $recurring
                && $this->dtdtService !== null
                && $this->settings?->getScheduleMode() === 'ready_by'
            ) {
                $result = $this->dtdtService->createReadyBySchedule($data['scheduledTime'], $params);
                return [
                    'status' => 201,
                    'body' => $result,
                ];
            }

            $result = $this->scheduler->scheduleJob($data['action'], $data['scheduledTime'], $recurring, $params);

            return [
                'status' => 201,
                'body' => $result,
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        } catch (\RuntimeException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => ['error' => 'Failed to schedule job: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * GET /api/schedule - List all scheduled jobs.
     *
     * @return array{status: int, body: array{jobs: array}}
     */
    public function list(): array
    {
        try {
            $jobs = $this->scheduler->listJobs();

            return [
                'status' => 200,
                'body' => ['jobs' => $jobs],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => ['error' => 'Failed to list jobs: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * DELETE /api/schedule/{id} - Cancel a scheduled job.
     *
     * @param string $jobId The job ID to cancel
     * @return array{status: int, body: array}
     */
    public function cancel(string $jobId): array
    {
        try {
            $this->scheduler->cancelJob($jobId);

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Job cancelled'],
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'status' => 404,
                'body' => ['error' => $e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => ['error' => 'Failed to cancel job: ' . $e->getMessage()],
            ];
        }
    }
}
