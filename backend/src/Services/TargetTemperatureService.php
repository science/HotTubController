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
    private const CHECK_BUFFER_SECONDS = 5;
    // Temperature tolerance for floating-point comparison (0.1°F)
    // This accounts for C→F conversion precision and sensor accuracy
    private const TEMP_TOLERANCE_F = 0.1;

    private string $stateFile;
    private ?IftttClientInterface $iftttClient;
    private ?EquipmentStatusService $equipmentStatus;
    private ?Esp32TemperatureService $esp32Temp;
    private ?CrontabAdapterInterface $crontabAdapter;
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
        ?Esp32SensorConfigService $esp32Config = null
    ) {
        $this->stateFile = $stateFile;
        $this->iftttClient = $iftttClient;
        $this->equipmentStatus = $equipmentStatus;
        $this->esp32Temp = $esp32Temp;
        $this->crontabAdapter = $crontabAdapter;
        $this->cronRunnerPath = $cronRunnerPath;
        $this->apiBaseUrl = $apiBaseUrl;
        $this->esp32Config = $esp32Config;
    }

    /**
     * Start heating to target temperature.
     *
     * @return array Result of initial checkAndAdjust (includes heater state, cron scheduled, etc.)
     */
    public function start(float $targetTempF): array
    {
        if ($targetTempF < self::MIN_TARGET_TEMP_F || $targetTempF > self::MAX_TARGET_TEMP_F) {
            throw new \InvalidArgumentException(
                sprintf('Target temperature must be between %.0f and %.0f°F', self::MIN_TARGET_TEMP_F, self::MAX_TARGET_TEMP_F)
            );
        }

        $state = [
            'active' => true,
            'target_temp_f' => $targetTempF,
            'started_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('c'),
        ];

        $this->saveState($state);

        // Immediately check temperature and turn on heater if needed
        return $this->checkAndAdjust();
    }

    public function stop(): void
    {
        if (file_exists($this->stateFile)) {
            unlink($this->stateFile);
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
     * @return array Status of the check operation
     */
    public function checkAndAdjust(): array
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

        // Clean up cron jobs
        $this->cleanupCronJobs();

        // Clear state - target reached
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
     * Calculate when the next check should occur.
     * Returns Unix timestamp for 5 seconds after the next expected ESP32 report.
     */
    public function calculateNextCheckTime(): int
    {
        $latest = $this->esp32Temp?->getLatest();
        $receivedAt = $latest['received_at'] ?? time();
        $interval = $this->esp32Temp?->getInterval() ?? Esp32TemperatureService::DEFAULT_INTERVAL;

        $nextReport = $receivedAt + $interval;
        $checkTime = $nextReport + self::CHECK_BUFFER_SECONDS;

        // If checkTime is in the past, add one interval
        if ($checkTime <= time()) {
            $checkTime += $interval;
        }

        // CRITICAL: Ensure we're at least 60 seconds in the future.
        // Cron daemon fires at :00 of each minute. If we schedule for the current
        // minute (e.g., add entry at 11:22:10 for minute 22), the daemon already
        // fired at 11:22:00 and won't fire again. The cron will NEVER execute!
        // By ensuring 60+ seconds, we guarantee scheduling for the NEXT minute.
        return max($checkTime, time() + 60);
    }

    /**
     * Schedule the next temperature check via cron.
     *
     * Creates a job file and crontab entry that uses cron-runner.sh for
     * proper JWT authentication.
     */
    private function scheduleNextCheck(): bool
    {
        if ($this->crontabAdapter === null || $this->cronRunnerPath === null || $this->apiBaseUrl === null) {
            return false;
        }

        $checkTime = $this->calculateNextCheckTime();
        $dateTime = new \DateTime('@' . $checkTime);
        $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $minute = $dateTime->format('i');
        $hour = $dateTime->format('H');
        $day = $dateTime->format('d');
        $month = $dateTime->format('m');

        $jobId = self::CRON_JOB_PREFIX . '-' . bin2hex(random_bytes(4));

        // Create job file for cron-runner.sh to read
        $this->createJobFile($jobId);

        // Build cron entry using cron-runner.sh for proper JWT authentication
        $cronExpression = sprintf('%d %d %d %d *', (int)$minute, (int)$hour, (int)$day, (int)$month);
        $command = sprintf(
            '%s %s',
            escapeshellarg($this->cronRunnerPath),
            escapeshellarg($jobId)
        );
        $comment = sprintf('HOTTUB:%s:HEAT-TARGET:ONCE', $jobId);

        $entry = sprintf('%s %s # %s', $cronExpression, $command, $comment);

        $this->crontabAdapter->addEntry($entry);

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
