<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * The one place that decides how a sensor temperature is written to a log.
 *
 * Convention (2026-11, issue #154): every log records the RAW sensor reading plus
 * the calibration offset in force at that moment, in a sibling
 * `*_calibration_offset_c` field. Offsets are degrees CELSIUS, as stored in
 * esp32-sensor-config.json, so the value the controller acted on is
 *
 *     calibrated °C = raw °C + offset °C      (°F: raw °F + offset × 1.8)
 *
 * Logging the raw value keeps history stable if the offset is later changed;
 * logging the offset next to it keeps the controller's decisions reconstructible.
 * An offset of null means "unknown" (no sensor config was available), which is
 * different from 0.
 *
 * Deliberately dependency-free: the ESP32 thin endpoint
 * (public/api/esp32/temperature/index.php) runs without the autoloader and
 * require_once's this file directly.
 */
final class TemperatureLogFields
{
    /**
     * Raw reading and calibration offset of the sensor assigned to $role.
     *
     * @param array<int, array{address: string, temp_c: float|int, temp_f?: float|int}> $sensors
     *        Sensors from one ESP32 reading.
     * @param array<string, array{role?: string, calibration_offset?: float|int}> $sensorConfig
     *        Map of address => config, i.e. the `sensors` object of esp32-sensor-config.json.
     * @return array{temp_f: ?float, temp_c: ?float, calibration_offset_c: ?float}
     *         All null when no sensor has that role or it is missing from the reading.
     */
    public static function forRole(array $sensors, array $sensorConfig, string $role): array
    {
        foreach ($sensors as $sensor) {
            $address = $sensor['address'] ?? '';
            $config = $sensorConfig[$address] ?? null;
            if ($config === null || ($config['role'] ?? null) !== $role) {
                continue;
            }

            $tempC = (float) $sensor['temp_c'];

            return [
                'temp_f' => isset($sensor['temp_f']) ? (float) $sensor['temp_f'] : $tempC * 9.0 / 5.0 + 32.0,
                'temp_c' => $tempC,
                'calibration_offset_c' => (float) ($config['calibration_offset'] ?? 0.0),
            ];
        }

        return ['temp_f' => null, 'temp_c' => null, 'calibration_offset_c' => null];
    }

    /**
     * One line of the daily storage/logs/temperature-YYYY-MM-DD.log history.
     *
     * @param array<int, array{address: string, temp_c: float|int, temp_f?: float|int}> $sensors
     * @param array<string, array{role?: string, calibration_offset?: float|int}> $sensorConfig
     * @return array<string, mixed>
     */
    public static function temperatureLogEntry(array $sensors, array $sensorConfig, bool $heaterOn): array
    {
        $water = self::forRole($sensors, $sensorConfig, 'water');
        $ambient = self::forRole($sensors, $sensorConfig, 'ambient');

        return [
            'timestamp' => date('c'),
            'water_temp_f' => $water['temp_f'],
            'water_temp_c' => $water['temp_c'],
            'water_calibration_offset_c' => $water['calibration_offset_c'],
            'ambient_temp_f' => $ambient['temp_f'],
            'ambient_temp_c' => $ambient['temp_c'],
            'ambient_calibration_offset_c' => $ambient['calibration_offset_c'],
            'heater_on' => $heaterOn,
        ];
    }
}
