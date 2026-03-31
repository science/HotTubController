<?php

declare(strict_types=1);

namespace HotTub\Services;

/**
 * Piecewise linear interpolation for dynamic heat-to-target temperature.
 *
 * Given 3 calibration points (cold, comfort, hot) mapping ambient air temps
 * to desired water temps, calculates the target water temp for any ambient temp.
 * Clamps to endpoint values outside the calibration range.
 */
class DynamicTargetCalculator
{
    private const MIN_WATER_TEMP_F = 80.0;
    private const MAX_WATER_TEMP_F = 110.0;

    /**
     * Calculate dynamic water target temperature from ambient temperature.
     *
     * @param float $ambientTempF Current ambient air temperature in Fahrenheit
     * @param array $calibrationPoints Array with 'cold', 'comfort', 'hot' keys,
     *              each containing 'ambient_f' and 'water_target_f'
     * @return array{target_f: float, clamped: bool, segment: string}
     */
    public static function calculate(float $ambientTempF, array $calibrationPoints): array
    {
        $cold = $calibrationPoints['cold'];
        $comfort = $calibrationPoints['comfort'];
        $hot = $calibrationPoints['hot'];

        // Clamp below cold
        if ($ambientTempF < $cold['ambient_f']) {
            return [
                'target_f' => $cold['water_target_f'],
                'clamped' => true,
                'segment' => 'clamp_low',
            ];
        }

        // Clamp above hot
        if ($ambientTempF > $hot['ambient_f']) {
            return [
                'target_f' => $hot['water_target_f'],
                'clamped' => true,
                'segment' => 'clamp_high',
            ];
        }

        // Interpolate on cold segment (cold → comfort)
        if ($ambientTempF <= $comfort['ambient_f']) {
            $t = ($ambientTempF - $cold['ambient_f']) / ($comfort['ambient_f'] - $cold['ambient_f']);
            $target = $cold['water_target_f'] + $t * ($comfort['water_target_f'] - $cold['water_target_f']);
            return [
                'target_f' => round($target, 2),
                'clamped' => false,
                'segment' => 'cold',
            ];
        }

        // Interpolate on hot segment (comfort → hot)
        $t = ($ambientTempF - $comfort['ambient_f']) / ($hot['ambient_f'] - $comfort['ambient_f']);
        $target = $comfort['water_target_f'] + $t * ($hot['water_target_f'] - $comfort['water_target_f']);
        return [
            'target_f' => round($target, 2),
            'clamped' => false,
            'segment' => 'hot',
        ];
    }

    /**
     * Validate calibration points structure and values.
     *
     * @param array $points Calibration points to validate
     * @return array<string> Array of error messages (empty = valid)
     */
    public static function validateCalibrationPoints(array $points): array
    {
        $errors = [];
        $requiredKeys = ['cold', 'comfort', 'hot'];

        foreach ($requiredKeys as $key) {
            if (!isset($points[$key])) {
                $errors[] = "Missing required calibration point: {$key}";
                continue;
            }
            if (!isset($points[$key]['ambient_f'])) {
                $errors[] = "Missing ambient_f for {$key} calibration point";
            }
            if (!isset($points[$key]['water_target_f'])) {
                $errors[] = "Missing water_target_f for {$key} calibration point";
            }
        }

        if (!empty($errors)) {
            return $errors;
        }

        // Validate ambient temps are strictly ordered: cold < comfort < hot
        if ($points['cold']['ambient_f'] >= $points['comfort']['ambient_f']) {
            $errors[] = 'Cold ambient temp must be less than comfort ambient temp';
        }
        if ($points['comfort']['ambient_f'] >= $points['hot']['ambient_f']) {
            $errors[] = 'Comfort ambient temp must be less than hot ambient temp';
        }

        // Validate water targets are within bounds
        foreach ($requiredKeys as $key) {
            $waterTemp = $points[$key]['water_target_f'];
            if ($waterTemp < self::MIN_WATER_TEMP_F || $waterTemp > self::MAX_WATER_TEMP_F) {
                $errors[] = sprintf(
                    '%s water target (%.1f°F) must be between %.0f and %.0f°F',
                    ucfirst($key),
                    $waterTemp,
                    self::MIN_WATER_TEMP_F,
                    self::MAX_WATER_TEMP_F
                );
            }
        }

        return $errors;
    }
}
