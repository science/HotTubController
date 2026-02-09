<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Contracts\IftttClientInterface;

class TargetTemperatureService
{
    public const MIN_TARGET_TEMP_F = 80.0;
    public const MAX_TARGET_TEMP_F = 110.0;
    public const CRON_JOB_PREFIX = 'heat-target';
    // Temperature tolerance for floating-point comparison (0.1°F)
    // This accounts for C→F conversion precision and sensor accuracy
    private const TEMP_TOLERANCE_F = 0.1;

    private string $stateFile;
    private ?IftttClientInterface $iftttClient;
    private ?EquipmentStatusService $equipmentStatus;
    private ?Esp32TemperatureService $esp32Temp;
    private ?CrontabAdapterInterface $crontabAdapter;
    private ?CronSchedulingService $cronSchedulingService;
    private ?string $cronRunnerPath;
    private ?string $apiBaseUrl;
    private ?Esp32SensorConfigService $esp32Config;

    public function __construct(
        string $stateFile,
        ?IftttClientInterface $iftttClient = null,
        ?EquipmentStatusService $equipmentStatus = null,
        ?Esp32TemperatureService $esp32Temp = null,
        ?CrontabAdapterInterface $crontabAdapter = null,
        ?string $cronRunnerPath = null,
        ?string $apiBaseUrl = null,
        ?Esp32SensorConfigService $esp32Config = null,
        ?CronSchedulingService $cronSchedulingService = null
    ) {
        $this->stateFile = $stateFile;
        $this->iftttClient = $iftttClient;
        $this->equipmentStatus = $equipmentStatus;
        $this->esp32Temp = $esp32Temp;
        $this->crontabAdapter = $crontabAdapter;
        $this->cronRunnerPath = $cronRunnerPath;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->esp32Config = $esp32Config;
        // Use provided CronSchedulingService, or create one if crontabAdapter is available
        $this->cronSchedulingService = $cronSchedulingService
            ?? ($crontabAdapter !== null ? new CronSchedulingService($crontabAdapter) : null);
    }

    /**
     * Acquire an exclusive lock with a single random backoff retry.
     *
     * @return resource|false File handle on success, false if lock unavailable
     */
    private function acquireLock(): mixed
    {
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        $dir = dirname($lockFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return false;
        }

        // First attempt
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            return $fp;
        }

        // Single random backoff, then one retry
        usleep(random_int(50000, 200000)); // 50-200ms
        if (flock($fp, LOCK_EX | LOCK_NB)) {
            return $fp;
        }

        fclose($fp);
        return false;
    }

    /**
     * Start heating to target temperature.
     *
     * Acquires lock, checks for active session, saves state, releases lock,
     * then calls checkAndAdjust() (which acquires its own lock).
     *
     * @return array Result of initial checkAndAdjust (includes heater state, cron scheduled, etc.)
     * @throws \RuntimeException If a heating session is already active
     */
    public function start(float $targetTempF): array
    {
        if ($targetTempF < self::MIN_TARGET_TEMP_F || $targetTempF > self::MAX_TARGET_TEMP_F) {
            throw new \InvalidArgumentException(
                sprintf('Target temperature must be between %.0f and %.0f°F', self::MIN_TARGET_TEMP_F, self::MAX_TARGET_TEMP_F)
            );
        }

        $lock = $this->acquireLock();
        if ($lock === false) {
            throw new \RuntimeException('Heat-to-target is already active (could not acquire lock)');
        }

        try {
            $currentState = $this->getState();
            if ($currentState['active']) {
                throw new \RuntimeException('Heat-to-target is already active');
            }

            $state = [
                'active' => true,
                'target_temp_f' => $targetTempF,
                'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            ];

            $this->saveState($state);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        // checkAndAdjust acquires its own lock — call after releasing ours
        return $this->checkAndAdjust();
    }

    public function stop(): void
    {
        // Turn off heater if it's currently on
        $equipmentState = $this->equipmentStatus?->getStatus() ?? ['heater' => ['on' => false]];
        if ($equipmentState['heater']['on'] === true) {
            $this->iftttClient?->trigger('hot-tub-heat-off');
            $this->equipmentStatus?->setHeaterOff();
        }

        // Delete the state file FIRST - this prevents any concurrent cron job
        // from scheduling a new check (checkAndAdjust checks state['active'])
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
        }

        // Clean up cron jobs
        $this->cleanupCronJobs();

        // Race condition protection: A cron job might have been executing
        // concurrently and scheduled a new check before we deleted the state.
        // Wait briefly and clean up again to catch any stragglers.
        usleep(100000); // 100ms - enough for concurrent cron to finish scheduling
        $this->cleanupCronJobs();

        // Clean up any orphaned heat-target job files
        $this->cleanupJobFiles();

        // Remove lock file to keep state directory clean
        $lockFile = dirname($this->stateFile) . '/target-temperature.lock';
        if (file_exists($lockFile)) {
            @unlink($lockFile);
        }
    }

    /**
     * Clean up heat-target job files from scheduled-jobs directory.
     */
    private function cleanupJobFiles(): void
    {
        $jobsDir = dirname(dirname($this->stateFile)) . '/scheduled-jobs';
        if (!is_dir($jobsDir)) {
            return;
        }

        $pattern = $jobsDir . '/' . self::CRON_JOB_PREFIX . '-*.json';
        foreach (glob($pattern) as $jobFile) {
            unlink($jobFile);
        }
    }

    private function saveState(array $state): void
    {
        $dir = dirname($this->stateFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->stateFile, json_encode($state, JSON_PRETTY_PRINT));
    }

    public function getState(): array
    {
        if (!file_exists($this->stateFile)) {
            return [
                'active' => false,
                'target_temp_f' => null,
            ];
        }

        $content = file_get_contents($this->stateFile);
        $state = json_decode($content, true);

        if (!is_array($state)) {
            return [
                'active' => false,
                'target_temp_f' => null,
            ];
        }

        return $state;
    }

    /**
     * Check current temperature and adjust heater state.
     *
     * Acquires lock to serialize concurrent cron checks. If lock can't be
     * acquired after a single backoff retry, returns early with 'skipped' field.
     *
     * @return array Status of the check operation
     */
    public function checkAndAdjust(): array
    {
        $lock = $this->acquireLock();
        if ($lock === false) {
            return [
                'skipped' => true,
                'reason' => 'Could not acquire lock',
            ];
        }

        try {
            return $this->doCheckAndAdjust();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function doCheckAndAdjust(): array
    {
        $state = $this->getState();

        if (!$state['active']) {
            return [
                'active' => false,
                'heating' => false,
                'heater_turned_on' => false,
                'heater_turned_off' => false,
            ];
        }

        $targetTempF = $state['target_temp_f'];
        $currentTempF = $this->getCalibratedWaterTempF();

        if ($currentTempF === null) {
            return [
                'active' => true,
                'target_temp_f' => $targetTempF,
                'heating' => false,
                'heater_turned_on' => false,
                'heater_turned_off' => false,
                'error' => 'No temperature data available',
            ];
        }

        $equipmentState = $this->equipmentStatus?->getStatus() ?? ['heater' => ['on' => false]];
        $heaterIsOn = $equipmentState['heater']['on'];

        // Use tolerance for comparison to handle floating-point precision issues
        if ($currentTempF < ($targetTempF - self::TEMP_TOLERANCE_F)) {
            // Need to heat
            $heaterTurnedOn = false;

            if (!$heaterIsOn) {
                // Turn heater on
                $this->iftttClient?->trigger('hot-tub-heat-on');
                $this->equipmentStatus?->setHeaterOn();
                $heaterTurnedOn = true;
            }

            // Schedule next check
            $cronScheduled = $this->scheduleNextCheck();

            return [
                'active' => true,
                'heating' => true,
                'heater_turned_on' => $heaterTurnedOn,
                'heater_turned_off' => false,
                'cron_scheduled' => $cronScheduled,
                'current_temp_f' => $currentTempF,
                'target_temp_f' => $targetTempF,
            ];
        }

        // Target reached or exceeded
        $heaterTurnedOff = false;

        if ($heaterIsOn) {
            // Turn heater off
            $this->iftttClient?->trigger('hot-tub-heat-off');
            $this->equipmentStatus?->setHeaterOff();
            $heaterTurnedOff = true;
        }

        // Clear state - target reached
        // Note: stop() handles cron cleanup and job file cleanup
        $this->stop();

        return [
            'active' => false,
            'heating' => false,
            'heater_turned_on' => false,
            'heater_turned_off' => $heaterTurnedOff,
            'target_reached' => true,
            'current_temp_f' => $currentTempF,
            'target_temp_f' => $targetTempF,
        ];
    }

    private const CRON_SAFETY_MARGIN_SECONDS = 5;

    /**
     * Calculate when the next check should occur.
     *
     * Returns a Unix timestamp that is:
     * 1. At a minute boundary (:00 seconds)
     * 2. In the next available minute
     * 3. At least CRON_SAFETY_MARGIN_SECONDS from now
     *
     * This ensures cron daemon will fire the job. The daemon fires at :00 of
     * each minute - if we add an entry for the current minute after :00, it
     * will never execute.
     *
     * Note: ESP32 prereport alignment (`:53` or `:55`) ensures temperature data
     * is fresh when each check runs. The backend does NOT sync to ESP32 timing.
     */
    public function calculateNextCheckTime(): int
    {
        $now = time();

        // Round UP to the next minute boundary
        // Example: 5:01:24 → 5:02:00
        $nextMinuteBoundary = (int) ceil($now / 60) * 60;

        // If less than safety margin until that minute, skip to the one after
        // Example: At 5:01:57, next boundary is 5:02:00 (3 seconds away)
        //          That's too close, so skip to 5:03:00
        if (($nextMinuteBoundary - $now) < self::CRON_SAFETY_MARGIN_SECONDS) {
            $nextMinuteBoundary += 60;
        }

        return $nextMinuteBoundary;
    }

    /**
     * Schedule the next temperature check via cron.
     *
     * Uses CronSchedulingService to ensure correct timezone handling.
     * Creates a job file and crontab entry that uses cron-runner.sh for
     * proper JWT authentication.
     */
    private function scheduleNextCheck(): bool
    {
        if ($this->cronSchedulingService === null || $this->cronRunnerPath === null || $this->apiBaseUrl === null) {
            return false;
        }

        $checkTime = $this->calculateNextCheckTime();
        $jobId = self::CRON_JOB_PREFIX . '-' . bin2hex(random_bytes(4));

        // Create job file for cron-runner.sh to read
        $this->createJobFile($jobId);

        // Build command and comment for cron entry
        $command = sprintf(
            '%s %s',
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId)
        );
        $comment = sprintf('HOTTUB:%s:HEAT-TARGET:ONCE', $jobId);

        // Use CronSchedulingService for correct timezone handling
        // This ensures cron fires at the right time regardless of PHP timezone
        $this->cronSchedulingService->scheduleAt($checkTime, $command, $comment);

        return true;
    }

    /**
     * Create a job file for cron-runner.sh to execute.
     */
    private function createJobFile(string $jobId): void
    {
        // Job files go in the same directory as other scheduled jobs
        $jobsDir = dirname(dirname($this->stateFile)) . '/scheduled-jobs';
        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0755, true);
        }

        $jobData = [
            'jobId' => $jobId,
            'endpoint' => '/api/maintenance/heat-target-check',
            'apiBaseUrl' => rtrim($this->apiBaseUrl, '/'),
            'recurring' => false,
            'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        $jobFile = $jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove all heat-target cron jobs.
     */
    public function cleanupCronJobs(): void
    {
        $this->crontabAdapter?->removeByPattern('HOTTUB:' . self::CRON_JOB_PREFIX);
    }

    /**
     * Get the calibrated water temperature in Fahrenheit.
     *
     * Uses sensor config to find the water sensor and apply calibration offset.
     * Falls back to raw temp_f if no config is available.
     */
    private function getCalibratedWaterTempF(): ?float
    {
        $latest = $this->esp32Temp?->getLatest();
        if ($latest === null) {
            return null;
        }

        // If no config service, fall back to raw temp_f
        if ($this->esp32Config === null) {
            return $latest['temp_f'] ?? null;
        }

        // Find the water sensor address
        $waterAddress = $this->esp32Config->getSensorByRole('water');
        if ($waterAddress === null) {
            // No water sensor configured, fall back to raw temp_f
            return $latest['temp_f'] ?? null;
        }

        // Find the sensor data for the water sensor
        foreach ($latest['sensors'] as $sensor) {
            if ($sensor['address'] === $waterAddress) {
                $rawTempC = (float) $sensor['temp_c'];
                $calibratedTempC = $this->esp32Config->getCalibratedTemperature($waterAddress, $rawTempC);
                return $this->celsiusToFahrenheit($calibratedTempC);
            }
        }

        // Water sensor not found in latest reading, fall back to raw temp_f
        return $latest['temp_f'] ?? null;
    }

    private function celsiusToFahrenheit(float $celsius): float
    {
        return $celsius * 9.0 / 5.0 + 32.0;
    }
}
