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
            $timezone = $data['timezone'] ?? null;

            // DTDT transformation: ready_by + heat-to-target + recurring
            if ($data['action'] === 'heat-to-target'
                && $recurring
                && $this->dtdtService !== null
                && $this->settings?->getScheduleMode() === 'ready_by'
            ) {
                $result = $this->dtdtService->createReadyBySchedule($data['scheduledTime'], $params, $timezone);
                return [
                    'status' => 201,
                    'body' => $result,
                ];
            }

            $result = $this->scheduler->scheduleJob($data['action'], $data['scheduledTime'], $recurring, $params, timezone: $timezone);

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
     * POST /api/schedule/{id}/skip - Skip the next occurrence of a recurring job.
     *
     * @param string $jobId The job ID to skip
     * @return array{status: int, body: array}
     */
    public function skip(string $jobId): array
    {
        try {
            $this->scheduler->skipNextOccurrence($jobId);

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Next occurrence skipped'],
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => ['error' => 'Failed to skip job: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * DELETE /api/schedule/{id}/skip - Unskip the next occurrence of a recurring job.
     *
     * @param string $jobId The job ID to unskip
     * @return array{status: int, body: array}
     */
    public function unskip(string $jobId): array
    {
        try {
            $this->scheduler->unskipNextOccurrence($jobId);

            return [
                'status' => 200,
                'body' => ['success' => true, 'message' => 'Skip removed'],
            ];
        } catch (InvalidArgumentException $e) {
            return [
                'status' => 400,
                'body' => ['error' => $e->getMessage()],
            ];
        } catch (\Exception $e) {
            return [
                'status' => 500,
                'body' => ['error' => 'Failed to unskip job: ' . $e->getMessage()],
            ];
        }
    }

    /**
     * PUT /api/schedule/{id}/target-temp - Update target temperature for a heat-to-target job.
     *
     * @param string $jobId The job ID to update
     * @param array{target_temp_f?: float} $data Request body
     * @return array{status: int, body: array}
     */
    public function updateTargetTemp(string $jobId, array $data): array
    {
        if (!isset($data['target_temp_f'])) {
            return [
                'status' => 400,
                'body' => ['error' => 'Missing required field: target_temp_f'],
            ];
        }

        try {
            $updated = $this->scheduler->updateJobTargetTemp($jobId, (float) $data['target_temp_f']);

            return [
                'status' => 200,
                'body' => $updated,
            ];
        } catch (InvalidArgumentException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 400;
            return [
                'status' => $status,
                'body' => ['error' => $e->getMessage()],
            ];
        }
    }

    /**
     * PUT /api/schedule/{id}/reschedule — move a one-off to a new time (and optional temp).
     *
     * Atomic and in-place (the job id is preserved), so a heat is never dropped by a
     * failed recreate. Recurring jobs are rejected here — adjust their next run via
     * override-next, or their everyday default by recreating.
     *
     * @param array{scheduledTime?: string, target_temp_f?: float|int} $data
     * @return array{status: int, body: array}
     */
    public function reschedule(string $jobId, array $data): array
    {
        if (empty($data['scheduledTime'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: scheduledTime']];
        }

        $tempF = null;
        if (isset($data['target_temp_f'])) {
            if (!is_numeric($data['target_temp_f'])) {
                return ['status' => 400, 'body' => ['error' => 'Invalid target_temp_f']];
            }
            $tempF = (float) $data['target_temp_f'];
        }

        try {
            $updated = $this->scheduler->rescheduleOneOff($jobId, (string) $data['scheduledTime'], $tempF);
            return ['status' => 200, 'body' => ['success' => true, 'job' => $updated]];
        } catch (InvalidArgumentException $e) {
            $status = str_contains(strtolower($e->getMessage()), 'not found') ? 404 : 400;
            return ['status' => $status, 'body' => ['error' => $e->getMessage()]];
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

    /**
     * POST /api/schedule/{id}/override-next — change just the next run of a recurring job.
     *
     * Atomically skips the recurring job's next occurrence and (re)creates a single
     * one-off override for that date at the new time/temp, inheriting the parent's
     * ready-by vs start-at mode. Idempotent: repeated calls replace the same override.
     *
     * @param array{scheduledTime?: string, target_temp_f?: float} $data HH:MM time + temp
     */
    public function overrideNext(string $jobId, array $data): array
    {
        if (empty($data['scheduledTime'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing required field: scheduledTime']];
        }
        if (!isset($data['target_temp_f']) || !is_numeric($data['target_temp_f'])) {
            return ['status' => 400, 'body' => ['error' => 'Missing or invalid target_temp_f']];
        }

        $newTime = (string) $data['scheduledTime']; // HH:MM
        $newTempF = (float) $data['target_temp_f'];
        if ($newTempF < 80.0 || $newTempF > 110.0) {
            return ['status' => 400, 'body' => ['error' => 'Target temperature must be between 80.0°F and 110.0°F']];
        }

        $parent = $this->scheduler->getJob($jobId);
        if ($parent === null) {
            return ['status' => 404, 'body' => ['error' => 'Job not found: ' . $jobId]];
        }
        if (!($parent['recurring'] ?? false)) {
            return ['status' => 400, 'body' => ['error' => 'Can only override recurring jobs']];
        }

        try {
            // Skip the parent's next occurrence (idempotent — leave an existing skip in place).
            if (!$this->scheduler->isSkipped($jobId)) {
                $this->scheduler->skipNextOccurrence($jobId);
            }
            $skip = $this->scheduler->getSkipData($jobId);
            $overrideDate = $skip['skip_date']; // Y-m-d (system timezone)

            // Replace any prior override for this parent.
            $this->scheduler->cancelOverrideFor($jobId);

            $timezone = $parent['params']['timezone'] ?? $parent['timezone'] ?? $this->settings?->getTimezone();
            $overrideParams = ['target_temp_f' => $newTempF, 'override_of' => $jobId];

            if (isset($parent['params']['ready_by_time']) && $this->dtdtService !== null) {
                $job = $this->dtdtService->createReadyByOverride($overrideDate, $newTime, $overrideParams, $timezone);
            } else {
                $dt = new \DateTime("{$overrideDate} {$newTime}:00", new \DateTimeZone($timezone ?? 'UTC'));
                $job = $this->scheduler->scheduleJob(
                    'heat-to-target',
                    $dt->format(\DateTime::ATOM),
                    recurring: false,
                    params: $overrideParams
                );
            }

            return ['status' => 200, 'body' => ['success' => true, 'override' => $job]];
        } catch (InvalidArgumentException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        } catch (\RuntimeException $e) {
            return ['status' => 400, 'body' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * DELETE /api/schedule/{id}/override-next — undo the override, back to the daily default.
     *
     * Cancels the override one-off and unskips the parent's next occurrence.
     */
    public function clearOverride(string $jobId): array
    {
        $parent = $this->scheduler->getJob($jobId);
        if ($parent === null) {
            return ['status' => 404, 'body' => ['error' => 'Job not found: ' . $jobId]];
        }

        $this->scheduler->cancelOverrideFor($jobId);
        if ($this->scheduler->isSkipped($jobId)) {
            $this->scheduler->unskipNextOccurrence($jobId);
        }

        return ['status' => 200, 'body' => ['success' => true, 'message' => 'Override cleared']];
    }
}
