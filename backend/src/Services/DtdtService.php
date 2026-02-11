<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * DTDT (Dynamic Time to Desired Temperature) Service.
 *
 * Implements "ready by" scheduling: instead of starting the heater at a scheduled time,
 * wakes up earlier to read current temperature and calculates the optimal start time
 * to reach target temperature by the desired time.
 */
class DtdtService
{
    /** Worst-case cold temperature for max heat time calculation */
    private const COLD_START_TEMP_F = 58.0;

    /** Buffer for estimation errors */
    private const SAFETY_MARGIN_MINUTES = 15;

    public function __construct(
        private SchedulerService $schedulerService,
        private ?TargetTemperatureService $targetTemperatureService,
        private ?Esp32CalibratedTemperatureService $calibratedTempService,
        private string $heatingCharsFile
    ) {}

    /**
     * Create a recurring ready-by schedule.
     *
     * Calculates the worst-case wake-up time and schedules a cron job that fires
     * early to read current temperature and optimize the actual heat start time.
     *
     * @param string $readyByTime Time in HH:MM+/-HH:MM format (e.g., "06:30-08:00")
     * @param array $params Must include 'target_temp_f'
     * @return array Job data from scheduler
     * @throws \RuntimeException if heating characteristics are not available
     */
    public function createReadyBySchedule(string $readyByTime, array $params): array
    {
        $chars = $this->loadHeatingCharacteristics();

        $targetTempF = (float) ($params['target_temp_f'] ?? 103.0);
        $maxHeatMinutes = $this->calculateMaxHeatMinutes($targetTempF, $chars);

        // Calculate wake-up time by shifting readyByTime back by maxHeatMinutes
        $wakeUpTime = $this->shiftTimeBack($readyByTime, (int) ceil($maxHeatMinutes));

        // Schedule with the wake-up endpoint and earlier cron time
        return $this->schedulerService->scheduleJob(
            'heat-to-target',
            $readyByTime,                    // display time (ready-by)
            recurring: true,
            params: array_merge($params, ['ready_by_time' => $readyByTime]),
            endpointOverride: '/api/maintenance/dtdt-wakeup',
            cronTime: $wakeUpTime            // actual cron fire time
        );
    }

    /**
     * Handle a wake-up call from the DTDT cron job.
     *
     * Reads current temperature, projects cooling, and either starts heating
     * immediately or schedules a precision one-off cron for optimal start time.
     *
     * @param array $params Must include 'ready_by_time' and 'target_temp_f'
     * @return array Status response
     */
    public function handleWakeUp(array $params): array
    {
        $readyByTime = $params['ready_by_time'] ?? null;
        $targetTempF = (float) ($params['target_temp_f'] ?? 103.0);

        if ($readyByTime === null) {
            return ['error' => 'Missing ready_by_time parameter'];
        }

        // Convert ready_by_time to today's Unix timestamp (next occurrence)
        $readyByTimestamp = $this->resolveNextOccurrence($readyByTime);
        $now = time();

        // Try to read current temperature
        $temps = $this->calibratedTempService?->getTemperatures();
        $waterTempF = $temps['water_temp_f'] ?? null;
        $ambientTempF = $temps['ambient_temp_f'] ?? null;

        // Load heating characteristics
        $chars = $this->loadHeatingCharacteristicsOrNull();

        // Fallback: no data → start immediately (conservative)
        if ($waterTempF === null || $chars === null) {
            return $this->startImmediately($targetTempF, 'No temperature data or heating characteristics');
        }

        // Already at target → nothing to do
        if ($waterTempF >= $targetTempF) {
            return [
                'status' => 'already_at_target',
                'water_temp_f' => $waterTempF,
                'target_temp_f' => $targetTempF,
            ];
        }

        // Project cooling to ready_by time
        $minutesRemaining = ($readyByTimestamp - $now) / 60.0;
        $coolingK = $chars['cooling_coefficient_k'] ?? $chars['max_cooling_k'] ?? 0.0;

        if ($ambientTempF !== null && $coolingK > 0 && $minutesRemaining > 0) {
            $projectedTempF = $ambientTempF + ($waterTempF - $ambientTempF) * exp(-$coolingK * $minutesRemaining);
        } else {
            $projectedTempF = $waterTempF; // No cooling projection possible
        }

        // If projected temp stays above target → no heating needed
        if ($projectedTempF >= $targetTempF) {
            return [
                'status' => 'stays_warm',
                'water_temp_f' => $waterTempF,
                'projected_temp_f' => round($projectedTempF, 1),
                'target_temp_f' => $targetTempF,
            ];
        }

        // Calculate heat time needed
        $velocity = $chars['heating_velocity_f_per_min'];
        $lag = $chars['startup_lag_minutes'] ?? 0;
        $heatMinutes = ($targetTempF - $projectedTempF) / $velocity + $lag;
        $startTimestamp = $readyByTimestamp - (int) ceil($heatMinutes * 60);

        // If start time is now or in the past → start immediately
        if ($startTimestamp <= $now) {
            return $this->startImmediately($targetTempF, 'Calculated start time is now or past');
        }

        // Schedule a precision one-off cron at the calculated start time
        $startDateTime = new \DateTime('@' . $startTimestamp);
        $startDateTime->setTimezone(new \DateTimeZone('UTC'));

        $result = $this->schedulerService->scheduleJob(
            'heat-to-target',
            $startDateTime->format(\DateTime::ATOM),
            recurring: false,
            params: ['target_temp_f' => $targetTempF]
        );

        return [
            'status' => 'precision_scheduled',
            'water_temp_f' => $waterTempF,
            'projected_temp_f' => round($projectedTempF, 1),
            'target_temp_f' => $targetTempF,
            'heat_minutes' => round($heatMinutes, 1),
            'start_time' => $startDateTime->format(\DateTime::ATOM),
            'jobId' => $result['jobId'],
        ];
    }

    /**
     * Calculate maximum heating time from worst-case cold start.
     *
     * @param float $targetTempF Target temperature
     * @param array $chars Heating characteristics with velocity and lag
     * @return float Minutes needed for worst-case heating
     */
    public function calculateMaxHeatMinutes(float $targetTempF, array $chars): float
    {
        $velocity = $chars['heating_velocity_f_per_min'];
        $lag = $chars['startup_lag_minutes'] ?? 0;

        return ($targetTempF - self::COLD_START_TEMP_F) / $velocity + $lag + self::SAFETY_MARGIN_MINUTES;
    }

    /**
     * Load heating characteristics from file, throw if not available.
     */
    private function loadHeatingCharacteristics(): array
    {
        $chars = $this->loadHeatingCharacteristicsOrNull();

        if ($chars === null) {
            throw new \RuntimeException(
                'No heating characteristics available. Generate heating characteristics first.'
            );
        }

        return $chars;
    }

    /**
     * Load heating characteristics from file, return null if not available.
     */
    private function loadHeatingCharacteristicsOrNull(): ?array
    {
        if (!file_exists($this->heatingCharsFile)) {
            return null;
        }

        $content = file_get_contents($this->heatingCharsFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || !isset($data['heating_velocity_f_per_min'])) {
            return null;
        }

        return $data;
    }

    /**
     * Shift a time string back by a number of minutes.
     *
     * @param string $time Time in HH:MM+/-HH:MM format (e.g., "06:30-08:00")
     * @param int $minutes Minutes to shift back
     * @return string Shifted time in same format
     */
    private function shiftTimeBack(string $time, int $minutes): string
    {
        // Parse HH:MM+/-HH:MM format
        if (!preg_match('/^(\d{2}):(\d{2})([+-]\d{2}:\d{2})$/', $time, $m)) {
            throw new \InvalidArgumentException("Invalid time format: {$time}");
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $offset = $m[3];

        // Use a reference date for arithmetic
        $dt = new \DateTime("2030-01-01T{$hour}:{$minute}:00{$offset}");
        $dt->modify("-{$minutes} minutes");

        // Extract HH:MM in the same offset
        return $dt->format('H:i') . $offset;
    }

    /**
     * Resolve a time-with-offset to the next occurrence as a Unix timestamp.
     */
    private function resolveNextOccurrence(string $timeWithOffset): int
    {
        if (!preg_match('/^(\d{2}):(\d{2})([+-]\d{2}:\d{2})$/', $timeWithOffset, $m)) {
            throw new \InvalidArgumentException("Invalid time format: {$timeWithOffset}");
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];
        $offset = $m[3];

        // Build today's timestamp at that time
        $today = new \DateTime('now', new \DateTimeZone('UTC'));
        $candidate = new \DateTime($today->format('Y-m-d') . "T{$hour}:{$minute}:00{$offset}");

        // If it's in the past, use tomorrow
        if ($candidate->getTimestamp() <= time()) {
            $candidate->modify('+1 day');
        }

        return $candidate->getTimestamp();
    }

    /**
     * Start heating immediately by calling TargetTemperatureService.
     */
    private function startImmediately(float $targetTempF, string $reason): array
    {
        if ($this->targetTemperatureService !== null) {
            $this->targetTemperatureService->start($targetTempF);
        }

        return [
            'status' => 'started_immediately',
            'target_temp_f' => $targetTempF,
            'reason' => $reason,
        ];
    }
}
