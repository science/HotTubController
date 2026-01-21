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

    public function start(float $targetTempF): void
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

        // Ensure we're at least 10 seconds in the future (cron granularity)
        return max($checkTime, time() + 10);
    }

    /**
     * Schedule the next temperature check via cron.
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

        // Build cron entry - directly call the API endpoint
        $cronExpression = sprintf('%d %d %d %d *', (int)$minute, (int)$hour, (int)$day, (int)$month);
        $command = sprintf(
            "curl -s -X POST '%s/maintenance/heat-target-check' -H 'Content-Type: application/json'",
            rtrim($this->apiBaseUrl, '/')
        );
        $comment = sprintf('HOTTUB:%s:HEAT-TARGET:ONCE', $jobId);

        $entry = sprintf('%s %s # %s', $cronExpression, $command, $comment);

        $this->crontabAdapter->addEntry($entry);

        return true;
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
