<?php

declare(strict_types=1);

namespace HotTub\Services;

use DateTime;

class EquipmentStatusService
{
    private const PUMP_AUTO_OFF_HOURS = 2;

    private ?string $eventLogFile;
    private ?Esp32TemperatureService $temperatureService;

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
     */
    public function enableEventLogging(string $eventLogFile, Esp32TemperatureService $temperatureService): void
    {
        $this->eventLogFile = $eventLogFile;
        $this->temperatureService = $temperatureService;
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
     * Append an equipment event to the JSONL event log.
     */
    private function logEquipmentEvent(string $equipment, string $action): void
    {
        if ($this->eventLogFile === null) {
            return;
        }

        $waterTempF = null;
        if ($this->temperatureService !== null) {
            $latest = $this->temperatureService->getLatest();
            if ($latest !== null) {
                $waterTempF = $latest['temp_f'] ?? null;
            }
        }

        $logEntry = json_encode([
            'timestamp' => date('c'),
            'equipment' => $equipment,
            'action' => $action,
            'water_temp_f' => $waterTempF,
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
