<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Service for storing and retrieving ESP32 temperature sensor data.
 */
class Esp32TemperatureService
{
    private string $storageFile;
    private ?EquipmentStatusService $equipmentStatus;
    private ?Esp32SensorConfigService $sensorConfigService;
    private ?string $temperatureLogDir;

    // Interval bounds (seconds)
    public const MIN_INTERVAL = 10;
    public const MAX_INTERVAL = 1800;  // 30 minutes
    public const DEFAULT_INTERVAL = 300;  // 5 minutes
    public const HEATING_INTERVAL = 60;   // 1 minute when heater is on

    public function __construct(
        string $storageFile,
        ?EquipmentStatusService $equipmentStatus = null,
        ?Esp32SensorConfigService $sensorConfigService = null,
        ?string $temperatureLogDir = null
    ) {
        $this->storageFile = $storageFile;
        $this->equipmentStatus = $equipmentStatus;
        $this->sensorConfigService = $sensorConfigService;
        $this->temperatureLogDir = $temperatureLogDir;
    }

    /**
     * Store a temperature reading from ESP32.
     *
     * Expects data with 'sensors' array from controller.
     */
    public function store(array $data): void
    {
        // Process sensors array - calculate temp_f if not provided
        $sensors = [];
        foreach ($data['sensors'] as $sensor) {
            $tempC = (float) $sensor['temp_c'];
            $tempF = isset($sensor['temp_f'])
                ? (float) $sensor['temp_f']
                : $tempC * 9.0 / 5.0 + 32.0;

            $sensors[] = [
                'address' => $sensor['address'],
                'temp_c' => $tempC,
                'temp_f' => $tempF,
            ];
        }

        $record = [
            'device_id' => $data['device_id'],
            'sensors' => $sensors,
            'uptime_seconds' => (int) ($data['uptime_seconds'] ?? 0),
            'timestamp' => date('c'),
            'received_at' => time(),
        ];

        // Keep legacy fields for backward compatibility (use first sensor)
        if (!empty($sensors)) {
            $record['temp_c'] = $sensors[0]['temp_c'];
            $record['temp_f'] = $sensors[0]['temp_f'];
        }

        $this->ensureDirectory();
        file_put_contents($this->storageFile, json_encode($record, JSON_PRETTY_PRINT));

        // Append to daily temperature history log
        if ($this->temperatureLogDir !== null && $this->sensorConfigService !== null) {
            $this->appendTemperatureLog($sensors);
        }
    }

    /**
     * Get the latest temperature reading.
     */
    public function getLatest(): ?array
    {
        if (!file_exists($this->storageFile)) {
            return null;
        }

        $content = file_get_contents($this->storageFile);
        if ($content === false) {
            return null;
        }

        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Get the interval to return to ESP32.
     * Returns shorter interval when heater is on for faster temperature updates.
     */
    public function getInterval(): int
    {
        if ($this->equipmentStatus !== null) {
            $status = $this->equipmentStatus->getStatus();
            if ($status['heater']['on']) {
                return self::HEATING_INTERVAL;
            }
        }

        return self::DEFAULT_INTERVAL;
    }

    /**
     * Clamp an interval value to valid bounds.
     */
    public static function clampInterval(int $interval): int
    {
        return max(self::MIN_INTERVAL, min(self::MAX_INTERVAL, $interval));
    }

    /**
     * Append a temperature reading to the daily JSONL history log.
     * Resolves sensor roles from config to record water/ambient temps.
     */
    private function appendTemperatureLog(array $sensors): void
    {
        $waterTempC = null;
        $waterTempF = null;
        $ambientTempC = null;
        $ambientTempF = null;

        foreach ($sensors as $sensor) {
            $config = $this->sensorConfigService->getSensorConfig($sensor['address']);
            $role = $config['role'] ?? 'unassigned';
            if ($role === 'water') {
                $waterTempC = $sensor['temp_c'];
                $waterTempF = $sensor['temp_f'];
            } elseif ($role === 'ambient') {
                $ambientTempC = $sensor['temp_c'];
                $ambientTempF = $sensor['temp_f'];
            }
        }

        $heaterOn = false;
        if ($this->equipmentStatus !== null) {
            $status = $this->equipmentStatus->getStatus();
            $heaterOn = $status['heater']['on'] ?? false;
        }

        $logEntry = json_encode([
            'timestamp' => date('c'),
            'water_temp_f' => $waterTempF,
            'water_temp_c' => $waterTempC,
            'ambient_temp_f' => $ambientTempF,
            'ambient_temp_c' => $ambientTempC,
            'heater_on' => $heaterOn,
        ]) . "\n";

        $logFile = $this->temperatureLogDir . '/temperature-' . date('Y-m-d') . '.log';
        if (!is_dir($this->temperatureLogDir)) {
            mkdir($this->temperatureLogDir, 0755, true);
        }
        file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->storageFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
