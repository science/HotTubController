<?php

declare(strict_types=1);

namespace HotTub\Services;

use DateTime;

class EquipmentStatusService
{
    private const PUMP_AUTO_OFF_HOURS = 2;

    private ?string $eventLogFile;
    private ?Esp32TemperatureService $temperatureService;
    private ?Esp32SensorConfigService $sensorConfig = null;

    public function __construct(
        private string $statusFile,
        ?string $eventLogFile = null,
        ?Esp32TemperatureService $temperatureService = null
    ) {
        $this->eventLogFile = $eventLogFile;
        $this->temperatureService = $temperatureService;
    }

    /**
     * Enable equipment event logging (for heater on/off events).
     * Called after construction to break circular dependency with Esp32TemperatureService.
     *
     * Pass the sensor config so entries record the water-role sensor and its offset;
     * without it the legacy top-level temp_f (= first sensor) is logged with a null offset.
     */
    public function enableEventLogging(
        string $eventLogFile,
        Esp32TemperatureService $temperatureService,
        ?Esp32SensorConfigService $sensorConfig = null
    ): void {
        $this->eventLogFile = $eventLogFile;
        $this->temperatureService = $temperatureService;
        $this->sensorConfig = $sensorConfig;
    }

    public function getStatus(): array
    {
        $status = $this->loadState();

        // Auto-off pump if running > 2 hours
        if ($status['pump']['on'] && $status['pump']['lastChangedAt'] !== null) {
            $lastChanged = new DateTime($status['pump']['lastChangedAt']);
            $now = new DateTime();
            $twoHoursLater = (clone $lastChanged)->modify('+' . self::PUMP_AUTO_OFF_HOURS . ' hours');

            if ($now >= $twoHoursLater) {
                $status['pump']['on'] = false;
                $status['pump']['lastChangedAt'] = $twoHoursLater->format('c');
                $this->saveState($status);
            }
        }

        return $status;
    }

    public function setHeaterOn(): void
    {
        $status = $this->loadState();
        $status['heater']['on'] = true;
        $status['heater']['lastChangedAt'] = (new DateTime())->format('c');
        $this->saveState($status);
        $this->logEquipmentEvent('heater', 'on');
    }

    public function setHeaterOff(): void
    {
        $status = $this->loadState();
        $status['heater']['on'] = false;
        $status['heater']['lastChangedAt'] = (new DateTime())->format('c');
        $this->saveState($status);
        $this->logEquipmentEvent('heater', 'off');
    }

    public function setPumpOn(): void
    {
        $status = $this->loadState();
        $status['pump']['on'] = true;
        $status['pump']['lastChangedAt'] = (new DateTime())->format('c');
        $this->saveState($status);
    }

    public function setPumpOff(): void
    {
        $status = $this->loadState();
        $status['pump']['on'] = false;
        $status['pump']['lastChangedAt'] = (new DateTime())->format('c');
        $this->saveState($status);
    }

    /**
     * Raw water reading + offset in force, per the TemperatureLogFields convention.
     *
     * @return array{temp_f: ?float, calibration_offset_c: ?float}
     */
    private function currentWaterReading(): array
    {
        $latest = $this->temperatureService?->getLatest();
        if ($latest === null) {
            return ['temp_f' => null, 'calibration_offset_c' => null];
        }

        if ($this->sensorConfig !== null) {
            $water = TemperatureLogFields::forRole(
                $latest['sensors'] ?? [],
                $this->sensorConfig->getAllSensors(),
                'water'
            );
            if ($water['temp_f'] !== null) {
                return $water;
            }
        }

        // No config, or no water sensor in this reading: legacy first-sensor value,
        // offset unknown.
        return ['temp_f' => $latest['temp_f'] ?? null, 'calibration_offset_c' => null];
    }

    /**
     * Append an equipment event to the JSONL event log.
     */
    private function logEquipmentEvent(string $equipment, string $action): void
    {
        if ($this->eventLogFile === null) {
            return;
        }

        $water = $this->currentWaterReading();

        $logEntry = json_encode([
            'timestamp' => date('c'),
            'equipment' => $equipment,
            'action' => $action,
            'water_temp_f' => $water['temp_f'],
            'water_calibration_offset_c' => $water['calibration_offset_c'],
        ]) . "\n";

        $dir = dirname($this->eventLogFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->eventLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function loadState(): array
    {
        if (!file_exists($this->statusFile)) {
            $default = $this->getDefaultState();
            $this->saveState($default);
            return $default;
        }

        $contents = file_get_contents($this->statusFile);
        $data = json_decode($contents, true);

        if (!is_array($data)) {
            return $this->getDefaultState();
        }

        return $data;
    }

    private function saveState(array $status): void
    {
        $dir = dirname($this->statusFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->statusFile, json_encode($status, JSON_PRETTY_PRINT));
    }

    private function getDefaultState(): array
    {
        return [
            'heater' => [
                'on' => false,
                'lastChangedAt' => null,
            ],
            'pump' => [
                'on' => false,
                'lastChangedAt' => null,
            ],
        ];
    }
}
