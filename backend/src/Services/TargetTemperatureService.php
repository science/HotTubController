<?php

declare(strict_types=1);

namespace HotTub\Services;

use HotTub\Contracts\CrontabAdapterInterface;

class TargetTemperatureService
{
    public const MIN_TARGET_TEMP_F = 80.0;
    public const MAX_TARGET_TEMP_F = 110.0;
    public const CRON_JOB_PREFIX = 'heat-target';
    public const WATCHDOG_JOB_PREFIX = 'watchdog';
    public const WATCHDOG_MARGIN_MINUTES = 10;
    // Temperature tolerance for floating-point comparison (0.1°F)
    // This accounts for C→F conversion precision and sensor accuracy
    private const TEMP_TOLERANCE_F = 0.1;

    private string $stateFile;
    private ?HeaterControlService $heaterControl;
    private ?EquipmentStatusService $equipmentStatus;
    private ?Esp32TemperatureService $esp32Temp;
    private ?CrontabAdapterInterface $crontabAdapter;
    private ?CronSchedulingService $cronSchedulingService;
    private ?string $cronRunnerPath;
    private ?string $apiBaseUrl;
    private ?Esp32SensorConfigService $esp32Config;
    private ?HeatTargetSettingsService $heatTargetSettings;
    private ?string $stallEventFile;
    private ?string $equipmentEventLogFile;
    private ?string $heatingCharacteristicsFile;

    public function __construct(
        string $stateFile,
        ?HeaterControlService $heaterControl = null,
        ?EquipmentStatusService $equipmentStatus = null,
        ?Esp32TemperatureService $esp32Temp = null,
        ?CrontabAdapterInterface $crontabAdapter = null,
        ?string $cronRunnerPath = null,
        ?string $apiBaseUrl = null,
        ?Esp32SensorConfigService $esp32Config = null,
        ?CronSchedulingService $cronSchedulingService = null,
        ?HeatTargetSettingsService $heatTargetSettings = null,
        ?string $stallEventFile = null,
        ?string $equipmentEventLogFile = null,
        ?string $heatingCharacteristicsFile = null
    ) {
        $this->stateFile = $stateFile;
        $this->heaterControl = $heaterControl;
        $this->equipmentStatus = $equipmentStatus;
        $this->esp32Temp = $esp32Temp;
        $this->crontabAdapter = $crontabAdapter;
        $this->cronRunnerPath = $cronRunnerPath;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->esp32Config = $esp32Config;
        $this->heatTargetSettings = $heatTargetSettings;
        $this->stallEventFile = $stallEventFile;
        $this->equipmentEventLogFile = $equipmentEventLogFile;
        $this->heatingCharacteristicsFile = $heatingCharacteristicsFile;
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

            // Resolve dynamic target if enabled
            $dynamicResult = $this->resolveDynamicTarget($targetTempF);
            $effectiveTargetF = $dynamicResult['target_f'];

            $state = [
                'active' => true,
                'target_temp_f' => $effectiveTargetF,
                'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
            ];

            if ($dynamicResult['dynamic_target_info'] !== null) {
                $state['dynamic_target_info'] = $dynamicResult['dynamic_target_info'];

                // Log the dynamic target decision
                $calibrationPoints = $this->heatTargetSettings !== null
                    ? $this->heatTargetSettings->getCalibrationPoints()
                    : [];
                $this->logDynamicTargetEvent($dynamicResult['dynamic_target_info'], $calibrationPoints);
            }

            $this->saveState($state);

            // Clear any previous stall event
            $this->clearStallEventFile();
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
            $this->heaterControl?->heaterOff();
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
            // Need to heat — check for stall
            $stallResult = $this->checkForStall($state, $currentTempF, $targetTempF);
            if ($stallResult !== null) {
                return $stallResult;
            }

            $heaterTurnedOn = false;

            if (!$heaterIsOn) {
                // Turn heater on
                $this->heaterControl?->heaterOn();
                $heaterTurnedOn = true;
            }

            // Schedule next check — use smart approach scheduling on first check
            $cronScheduled = $this->scheduleApproachCheckIfEligible($state, $currentTempF, $targetTempF)
                ?: $this->scheduleNextCheck();

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
            $this->heaterControl?->heaterOff();
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

    /**
     * Check for heating stall and take action if detected.
     *
     * @return array|null Stall result if detected, null to continue heating
     */
    private function checkForStall(array &$state, float $currentTempF, float $targetTempF): ?array
    {
        $now = time();
        $startedAt = isset($state['started_at'])
            ? (new \DateTimeImmutable($state['started_at']))->getTimestamp()
            : $now;

        // Read settings
        $gracePeriodMinutes = $this->heatTargetSettings?->getStallGracePeriodMinutes()
            ?? HeatTargetSettingsService::DEFAULT_STALL_GRACE_PERIOD_MINUTES;
        $stallTimeoutMinutes = $this->heatTargetSettings?->getStallTimeoutMinutes()
            ?? HeatTargetSettingsService::DEFAULT_STALL_TIMEOUT_MINUTES;

        // 1. Initialize stall reference if not set
        if (!isset($state['stall_reference_temp_f']) || !isset($state['stall_reference_at'])) {
            $state['stall_reference_temp_f'] = $currentTempF;
            $state['stall_reference_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $this->saveState($state);
            return null;
        }

        $stallRefTempF = (float) $state['stall_reference_temp_f'];
        $stallRefAt = (new \DateTimeImmutable($state['stall_reference_at']))->getTimestamp();

        // 2. If current_temp > stall_reference_temp → progress! Update both fields
        if ($currentTempF > $stallRefTempF) {
            $state['stall_reference_temp_f'] = $currentTempF;
            $state['stall_reference_at'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c');
            $this->saveState($state);
            return null;
        }

        // 3. No progress — check timing
        $sessionAgeMinutes = ($now - $startedAt) / 60;
        $stallRefAgeMinutes = ($now - $stallRefAt) / 60;

        // 3a. Within grace period — continue waiting
        if ($sessionAgeMinutes < $gracePeriodMinutes) {
            return null;
        }

        // 3b. Stall timeout not yet reached — continue waiting
        if ($stallRefAgeMinutes < $stallTimeoutMinutes) {
            return null;
        }

        // STALL DETECTED
        return $this->handleStallDetected($currentTempF, $targetTempF, $stallRefTempF);
    }

    /**
     * Handle a detected heating stall: log, write event file, stop heating.
     */
    private function handleStallDetected(float $currentTempF, float $targetTempF, float $stallRefTempF): array
    {
        $reason = sprintf(
            'Temperature stalled at %.1f°F (target: %.1f°F, last progress at: %.1f°F)',
            $currentTempF,
            $targetTempF,
            $stallRefTempF
        );

        // Log to equipment event log
        $this->logStallEvent($currentTempF);

        // Write stall event file
        $this->writeStallEventFile($currentTempF, $targetTempF, $reason);

        // Stop heating (turns off heater, clears state, cleans up cron)
        $this->stop();

        return [
            'active' => false,
            'heating' => false,
            'heater_turned_on' => false,
            'heater_turned_off' => true,
            'stall_detected' => true,
            'current_temp_f' => $currentTempF,
            'target_temp_f' => $targetTempF,
            'error' => $reason,
        ];
    }

    /**
     * Log a stall event to the equipment event log (JSONL).
     */
    private function logStallEvent(float $currentTempF): void
    {
        if ($this->equipmentEventLogFile === null) {
            return;
        }

        $logEntry = json_encode([
            'timestamp' => date('c'),
            'equipment' => 'heater',
            'action' => 'stall_detected',
            'water_temp_f' => $currentTempF,
        ]) . "\n";

        $dir = dirname($this->equipmentEventLogFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->equipmentEventLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Write the last stall event file for the health endpoint.
     */
    private function writeStallEventFile(float $currentTempF, float $targetTempF, string $reason): void
    {
        if ($this->stallEventFile === null) {
            return;
        }

        $dir = dirname($this->stallEventFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $event = [
            'timestamp' => date('c'),
            'current_temp_f' => $currentTempF,
            'target_temp_f' => $targetTempF,
            'reason' => $reason,
        ];

        file_put_contents($this->stallEventFile, json_encode($event, JSON_PRETTY_PRINT));
    }

    /**
     * Clear the stall event file (called when a new session starts).
     */
    private function clearStallEventFile(): void
    {
        if ($this->stallEventFile !== null && file_exists($this->stallEventFile)) {
            unlink($this->stallEventFile);
        }
    }

    private const CRON_SAFETY_MARGIN_SECONDS = 5;
    private const MIN_SMART_SCHEDULING_MINUTES = 3;

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
     * On the first check of a session, schedule a single approach check near
     * the predicted target arrival time instead of starting 1-minute checks
     * immediately. This reduces cron count from ~30 to ~10 per session.
     *
     * Returns true if an approach check was scheduled, false to fall through
     * to legacy 1-minute scheduling.
     */
    private function scheduleApproachCheckIfEligible(array &$state, float $currentTempF, float $targetTempF): bool
    {
        // Only on the first check (approach not yet scheduled)
        if (isset($state['approach_check_at'])) {
            return false;
        }

        // Need heating characteristics for prediction
        if ($this->heatingCharacteristicsFile === null || !file_exists($this->heatingCharacteristicsFile)) {
            return false;
        }
        $chars = json_decode(file_get_contents($this->heatingCharacteristicsFile), true);
        if (!is_array($chars) || empty($chars['heating_velocity_f_per_min'])) {
            return false;
        }

        $velocity = (float) $chars['heating_velocity_f_per_min'];
        if ($velocity <= 0) {
            return false;
        }

        // Calculate approach time WITHOUT startup lag — this is the earliest
        // the tub could reach target (if hot water is already in pipes)
        $approachMinutes = ($targetTempF - $currentTempF) / $velocity;

        if ($approachMinutes < self::MIN_SMART_SCHEDULING_MINUTES) {
            return false;
        }

        $approachTimestamp = $this->roundToMinuteBoundary(
            time() + (int) ($approachMinutes * 60)
        );

        $scheduled = $this->scheduleCheckAt($approachTimestamp);
        if (!$scheduled) {
            return false;
        }

        // Schedule watchdog: approach + startup lag + margin
        $startupLag = (float) ($chars['startup_lag_minutes'] ?? 0);
        $watchdogMinutes = $approachMinutes + $startupLag + self::WATCHDOG_MARGIN_MINUTES;
        $watchdogTimestamp = $this->roundToMinuteBoundary(
            time() + (int) ($watchdogMinutes * 60)
        );
        $this->scheduleWatchdog($watchdogTimestamp);

        // Record in state so subsequent checks use 1-minute scheduling
        $state['approach_check_at'] = (new \DateTimeImmutable(
            '@' . $approachTimestamp
        ))->format('c');
        $this->saveState($state);

        return true;
    }

    /**
     * Round a Unix timestamp up to the next minute boundary with safety margin.
     */
    private function roundToMinuteBoundary(int $timestamp): int
    {
        $boundary = (int) ceil($timestamp / 60) * 60;

        if (($boundary - time()) < self::CRON_SAFETY_MARGIN_SECONDS) {
            $boundary += 60;
        }

        return $boundary;
    }

    /**
     * Schedule the next temperature check at the next available minute.
     */
    private function scheduleNextCheck(): bool
    {
        return $this->scheduleCheckAt($this->calculateNextCheckTime());
    }

    /**
     * Schedule a temperature check at a specific time.
     *
     * Uses CronSchedulingService to ensure correct timezone handling.
     * Creates a job file and crontab entry that uses cron-runner.sh for
     * proper JWT authentication.
     *
     * @param int $timestamp Unix timestamp for when the check should fire
     */
    private function scheduleCheckAt(int $timestamp): bool
    {
        if ($this->cronSchedulingService === null || $this->cronRunnerPath === null || $this->apiBaseUrl === null) {
            return false;
        }

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
        $this->cronSchedulingService->scheduleAt($timestamp, $command, $comment);

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
     * Schedule a watchdog cron that will turn off the heater if the normal
     * check chain fails. Uses the full /api/equipment/heater/off endpoint
     * for logging and status updates.
     *
     * The watchdog is NOT cleaned up by stop() — it intentionally survives
     * normal session completion as a second IFTTT off attempt. It IS cleaned
     * up by HeaterControlService::heaterOn() (any new heater-on event).
     */
    private function scheduleWatchdog(int $timestamp): void
    {
        if ($this->cronSchedulingService === null || $this->cronRunnerPath === null || $this->apiBaseUrl === null) {
            return;
        }

        $jobId = self::WATCHDOG_JOB_PREFIX . '-' . bin2hex(random_bytes(4));

        $this->createWatchdogJobFile($jobId);

        $command = sprintf(
            '%s %s',
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId)
        );
        $comment = sprintf('HOTTUB:%s:%s:ONCE', $jobId, 'WATCHDOG');

        $this->cronSchedulingService->scheduleAt($timestamp, $command, $comment);
    }

    /**
     * Create a watchdog job file that calls the heater-off endpoint.
     */
    private function createWatchdogJobFile(string $jobId): void
    {
        $jobsDir = dirname(dirname($this->stateFile)) . '/scheduled-jobs';
        if (!is_dir($jobsDir)) {
            mkdir($jobsDir, 0755, true);
        }

        $jobData = [
            'jobId' => $jobId,
            'endpoint' => '/api/equipment/heater/off?source=watchdog',
            'apiBaseUrl' => rtrim($this->apiBaseUrl, '/'),
            'recurring' => false,
            'createdAt' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        $jobFile = $jobsDir . '/' . $jobId . '.json';
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Remove all watchdog cron jobs and job files.
     * Called by HeaterControlService::heaterOn() to clear watchdogs
     * when a new heater-on event occurs.
     */
    public static function cleanupWatchdogCrons(CrontabAdapterInterface $crontabAdapter, ?string $jobsDir = null): void
    {
        $crontabAdapter->removeByPattern('HOTTUB:' . self::WATCHDOG_JOB_PREFIX);

        if ($jobsDir !== null && is_dir($jobsDir)) {
            foreach (glob($jobsDir . '/' . self::WATCHDOG_JOB_PREFIX . '-*.json') as $jobFile) {
                unlink($jobFile);
            }
        }
    }

    /**
     * Compute estimated time of arrival at target temperature.
     *
     * Returns null if not actively heating, no characteristics data,
     * no temperature data, or target already reached.
     */
    public function computeEta(): ?array
    {
        $state = $this->getState();
        if (!($state['active'] ?? false)) {
            return null;
        }

        // Load heating characteristics
        if ($this->heatingCharacteristicsFile === null || !file_exists($this->heatingCharacteristicsFile)) {
            return null;
        }
        $chars = json_decode(file_get_contents($this->heatingCharacteristicsFile), true);
        if (!is_array($chars) || empty($chars['heating_velocity_f_per_min'])) {
            return null;
        }

        $currentTempF = $this->getCalibratedWaterTempF();
        if ($currentTempF === null) {
            return null;
        }

        $targetTempF = (float) $state['target_temp_f'];
        if ($currentTempF >= $targetTempF) {
            return null;
        }

        $velocity = (float) $chars['heating_velocity_f_per_min'];
        $startupLag = (float) ($chars['startup_lag_minutes'] ?? 0);

        // Calculate remaining startup lag based on elapsed time
        $now = time();
        $startedAt = isset($state['started_at'])
            ? (new \DateTimeImmutable($state['started_at']))->getTimestamp()
            : $now;
        $elapsedMinutes = ($now - $startedAt) / 60.0;
        $remainingLag = max(0.0, $startupLag - $elapsedMinutes);

        $heatingMinutes = ($targetTempF - $currentTempF) / $velocity + $remainingLag;
        $etaTimestamp = $now + (int) ceil($heatingMinutes * 60);

        return [
            'eta_timestamp' => (new \DateTimeImmutable('@' . $etaTimestamp))->format('c'),
            'minutes_remaining' => round($heatingMinutes, 1),
            'heating_velocity' => $velocity,
            'target_temp_f' => $targetTempF,
            'projected' => false,
        ];
    }

    /**
     * Compute projected ETA as if heating started right now.
     *
     * Works without an active session. Uses configured target temp
     * (dynamic if enabled, static otherwise) and full startup lag.
     */
    public function computeProjectedEta(): ?array
    {
        if ($this->heatTargetSettings === null || !$this->heatTargetSettings->isEnabled()) {
            return null;
        }

        if ($this->heatingCharacteristicsFile === null || !file_exists($this->heatingCharacteristicsFile)) {
            return null;
        }
        $chars = json_decode(file_get_contents($this->heatingCharacteristicsFile), true);
        if (!is_array($chars) || empty($chars['heating_velocity_f_per_min'])) {
            return null;
        }

        $currentTempF = $this->getCalibratedWaterTempF();
        if ($currentTempF === null) {
            return null;
        }

        // Determine target: dynamic (from ambient) or static
        $targetTempF = $this->heatTargetSettings->getTargetTempF();
        if ($this->heatTargetSettings->isDynamicMode()) {
            $ambientTempF = $this->getCalibratedAmbientTempF();
            if ($ambientTempF !== null) {
                $result = DynamicTargetCalculator::calculate(
                    $ambientTempF,
                    $this->heatTargetSettings->getCalibrationPoints()
                );
                $targetTempF = $result['target_f'];
            }
        }

        if ($currentTempF >= $targetTempF) {
            return null;
        }

        $velocity = (float) $chars['heating_velocity_f_per_min'];
        $startupLag = (float) ($chars['startup_lag_minutes'] ?? 0);

        $heatingMinutes = ($targetTempF - $currentTempF) / $velocity + $startupLag;
        $now = time();
        $etaTimestamp = $now + (int) ceil($heatingMinutes * 60);

        return [
            'eta_timestamp' => (new \DateTimeImmutable('@' . $etaTimestamp))->format('c'),
            'minutes_remaining' => round($heatingMinutes, 1),
            'heating_velocity' => $velocity,
            'target_temp_f' => $targetTempF,
            'projected' => true,
        ];
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

    /**
     * Get the calibrated ambient temperature in Fahrenheit.
     *
     * Mirrors getCalibratedWaterTempF() but for the ambient sensor role.
     */
    private function getCalibratedAmbientTempF(): ?float
    {
        $latest = $this->esp32Temp?->getLatest();
        if ($latest === null || $this->esp32Config === null) {
            return null;
        }

        $ambientAddress = $this->esp32Config->getSensorByRole('ambient');
        if ($ambientAddress === null) {
            return null;
        }

        foreach ($latest['sensors'] as $sensor) {
            if ($sensor['address'] === $ambientAddress) {
                $rawTempC = (float) $sensor['temp_c'];
                $calibratedTempC = $this->esp32Config->getCalibratedTemperature($ambientAddress, $rawTempC);
                return $this->celsiusToFahrenheit($calibratedTempC);
            }
        }

        return null;
    }

    /**
     * Resolve the effective target temperature, applying dynamic calculation if enabled.
     *
     * @param float $staticTargetF The static target temperature passed by the caller
     * @return array{target_f: float, dynamic_target_info: ?array}
     */
    private function resolveDynamicTarget(float $staticTargetF): array
    {
        if ($this->heatTargetSettings === null || !$this->heatTargetSettings->isDynamicMode()) {
            return ['target_f' => $staticTargetF, 'dynamic_target_info' => null];
        }

        $ambientTempF = $this->getCalibratedAmbientTempF();
        $calibrationPoints = $this->heatTargetSettings->getCalibrationPoints();

        if ($ambientTempF === null) {
            // Fallback to static target
            return [
                'target_f' => $staticTargetF,
                'dynamic_target_info' => [
                    'dynamic_mode' => true,
                    'ambient_temp_f' => null,
                    'computed_target_f' => $staticTargetF,
                    'static_target_f' => $staticTargetF,
                    'clamped' => false,
                    'fallback' => true,
                    'fallback_reason' => 'ambient_sensor_unavailable',
                ],
            ];
        }

        $result = DynamicTargetCalculator::calculate($ambientTempF, $calibrationPoints);

        return [
            'target_f' => $result['target_f'],
            'dynamic_target_info' => [
                'dynamic_mode' => true,
                'ambient_temp_f' => $ambientTempF,
                'computed_target_f' => $result['target_f'],
                'static_target_f' => $staticTargetF,
                'segment' => $result['segment'],
                'clamped' => $result['clamped'],
                'fallback' => false,
            ],
        ];
    }

    /**
     * Log dynamic target decision to the equipment event log.
     */
    private function logDynamicTargetEvent(array $dynamicTargetInfo, array $calibrationPoints): void
    {
        if ($this->equipmentEventLogFile === null) {
            return;
        }

        $action = $dynamicTargetInfo['fallback']
            ? 'dynamic_heat_target_fallback'
            : 'dynamic_heat_target_start';

        $logEntry = [
            'timestamp' => date('c'),
            'equipment' => 'heater',
            'action' => $action,
            'ambient_temp_f' => $dynamicTargetInfo['ambient_temp_f'],
            'computed_target_f' => $dynamicTargetInfo['computed_target_f'],
            'static_target_f' => $dynamicTargetInfo['static_target_f'],
            'calibration_points' => $calibrationPoints,
            'clamped' => $dynamicTargetInfo['clamped'],
            'fallback' => $dynamicTargetInfo['fallback'],
        ];

        if ($dynamicTargetInfo['fallback'] && isset($dynamicTargetInfo['fallback_reason'])) {
            $logEntry['fallback_reason'] = $dynamicTargetInfo['fallback_reason'];
        }

        $dir = dirname($this->equipmentEventLogFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents(
            $this->equipmentEventLogFile,
            json_encode($logEntry) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}
