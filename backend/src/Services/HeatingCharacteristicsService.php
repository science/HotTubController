<?php

declare(strict_types=1);

namespace HotTub\Services;

class HeatingCharacteristicsService
{
    private float $startupLagThresholdF;
    private int $cooldownThresholdMinutes;
    private int $coolingSettleMinutes;
    private int $coolingStride;

    public function __construct(
        float $startupLagThresholdF = 0.2,
        int $cooldownThresholdMinutes = 30,
        int $coolingSettleMinutes = 15,
        int $coolingStride = 12
    ) {
        $this->startupLagThresholdF = $startupLagThresholdF;
        $this->cooldownThresholdMinutes = $cooldownThresholdMinutes;
        $this->coolingSettleMinutes = $coolingSettleMinutes;
        $this->coolingStride = $coolingStride;
    }

    /**
     * Generate aggregate heating characteristics from log files.
     *
     * @param string[] $tempLogFiles Array of temperature log file paths
     * @param string $eventLogFile Equipment events log file path
     */
    public function generate(array $tempLogFiles, string $eventLogFile, ?string $startDate = null, ?string $endDate = null): array
    {
        $allTemps = [];
        foreach ($tempLogFiles as $file) {
            $allTemps = array_merge($allTemps, $this->parseJsonlFile($file));
        }
        usort($allTemps, fn($a, $b) => strtotime($a['timestamp']) - strtotime($b['timestamp']));

        $events = $this->parseJsonlFile($eventLogFile);

        // Filter events by date range if specified
        if ($startDate !== null || $endDate !== null) {
            $startTs = $startDate ? strtotime($startDate . 'T00:00:00+00:00') : 0;
            $endTs = $endDate ? strtotime($endDate . 'T23:59:59+00:00') : PHP_INT_MAX;
            $events = array_values(array_filter($events, function ($e) use ($startTs, $endTs) {
                $ts = strtotime($e['timestamp']);
                return $ts >= $startTs && $ts <= $endTs;
            }));
        }

        if (empty($events) || empty($allTemps)) {
            return $this->emptyResults();
        }

        $sessions = $this->buildSessions($allTemps, $events);

        // Filter garbage sessions: heater was "on" but no actual heating occurred
        $sessions = array_values(array_filter($sessions, function ($s) {
            return $s['heating_velocity_f_per_min'] > 0;
        }));

        if (empty($sessions)) {
            return $this->emptyResults();
        }

        $velocities = array_column($sessions, 'heating_velocity_f_per_min');
        $lags = array_column($sessions, 'startup_lag_minutes');
        $overshoots = array_column($sessions, 'overshoot_degrees_f');

        // Newton's Law cooling coefficient analysis
        $coolingResults = $this->fitNewtonCoolingCoefficient($allTemps, $events);

        return [
            'heating_velocity_f_per_min' => round($this->mean($velocities), 3),
            'startup_lag_minutes' => round($this->mean($lags), 1),
            'overshoot_degrees_f' => round($this->mean($overshoots), 2),
            'cooling_coefficient_k' => $coolingResults['cooling_coefficient_k'],
            'cooling_data_points' => $coolingResults['cooling_data_points'],
            'cooling_r_squared' => $coolingResults['cooling_r_squared'],
            'sessions_analyzed' => count($sessions),
            'sessions' => $sessions,
            'generated_at' => date('c'),
        ];
    }

    /**
     * Extract heating sessions from a single temperature log + events log.
     */
    public function extractSessions(string $tempLogFile, string $eventLogFile): array
    {
        $temps = $this->parseJsonlFile($tempLogFile);
        $events = $this->parseJsonlFile($eventLogFile);

        usort($temps, fn($a, $b) => strtotime($a['timestamp']) - strtotime($b['timestamp']));

        return $this->buildSessions($temps, $events);
    }

    private function buildSessions(array $temps, array $events): array
    {
        // Pair heater on/off events
        $pairs = [];
        $pendingOn = null;

        foreach ($events as $event) {
            if (($event['equipment'] ?? '') !== 'heater') {
                continue;
            }
            if ($event['action'] === 'on') {
                $pendingOn = $event;
            } elseif ($event['action'] === 'off' && $pendingOn !== null) {
                $pairs[] = ['on' => $pendingOn, 'off' => $event];
                $pendingOn = null;
            }
        }

        $sessions = [];
        foreach ($pairs as $pair) {
            $onTime = strtotime($pair['on']['timestamp']);
            $offTime = strtotime($pair['off']['timestamp']);

            // Get temperature readings during heating and after
            $heatingReadings = [];
            $afterReadings = [];

            foreach ($temps as $t) {
                $ts = strtotime($t['timestamp']);
                if ($ts >= $onTime && $ts <= $offTime) {
                    $heatingReadings[] = $t;
                } elseif ($ts > $offTime && $ts <= $offTime + 600) {
                    // 10 minutes after heater off for overshoot detection
                    $afterReadings[] = $t;
                }
            }

            if (count($heatingReadings) < 2) {
                continue;
            }

            $startTempF = $pair['on']['water_temp_f'];
            $endTempF = $pair['off']['water_temp_f'];

            // Detect startup lag: minutes until temp rises by threshold
            $lagMinutes = $this->detectStartupLag($heatingReadings, $onTime);

            // Calculate heating velocity using readings after startup lag
            $velocity = $this->calculateVelocity($heatingReadings, $onTime, $lagMinutes);

            // Calculate overshoot
            $overshoot = $this->calculateOvershoot($endTempF, $afterReadings);

            $sessions[] = [
                'heater_on_at' => $pair['on']['timestamp'],
                'heater_off_at' => $pair['off']['timestamp'],
                'start_temp_f' => $startTempF,
                'end_temp_f' => $endTempF,
                'heating_velocity_f_per_min' => round($velocity, 3),
                'startup_lag_minutes' => round($lagMinutes, 1),
                'overshoot_degrees_f' => round($overshoot, 2),
                'duration_minutes' => round(($offTime - $onTime) / 60, 1),
            ];
        }

        return $sessions;
    }

    /**
     * Detect startup lag: time until temperature rises consistently.
     */
    private function detectStartupLag(array $readings, int $onTime): float
    {
        $startTemp = $readings[0]['water_temp_f'];

        foreach ($readings as $reading) {
            $tempRise = $reading['water_temp_f'] - $startTemp;
            if ($tempRise >= $this->startupLagThresholdF) {
                $ts = strtotime($reading['timestamp']);
                return ($ts - $onTime) / 60.0;
            }
        }

        return 0.0;
    }

    /**
     * Calculate heating velocity using linear regression on steady-state readings.
     */
    private function calculateVelocity(array $readings, int $onTime, float $lagMinutes): float
    {
        $lagSeconds = $lagMinutes * 60;

        // Filter to steady-state readings (after startup lag)
        $steadyState = [];
        foreach ($readings as $r) {
            $ts = strtotime($r['timestamp']);
            if ($ts >= $onTime + $lagSeconds) {
                $steadyState[] = [
                    'minutes' => ($ts - $onTime) / 60.0,
                    'temp_f' => $r['water_temp_f'],
                ];
            }
        }

        if (count($steadyState) < 2) {
            // Fallback: use all readings
            $first = $readings[0];
            $last = end($readings);
            $dt = (strtotime($last['timestamp']) - strtotime($first['timestamp'])) / 60.0;
            return $dt > 0 ? ($last['water_temp_f'] - $first['water_temp_f']) / $dt : 0.0;
        }

        // Linear regression: slope = velocity
        return $this->linearRegressionSlope($steadyState);
    }

    /**
     * Least-squares linear regression slope.
     * Input: array of ['minutes' => x, 'temp_f' => y]
     */
    private function linearRegressionSlope(array $points): float
    {
        $n = count($points);
        if ($n < 2) {
            return 0.0;
        }

        $sumX = 0.0;
        $sumY = 0.0;
        $sumXY = 0.0;
        $sumX2 = 0.0;

        foreach ($points as $p) {
            $x = $p['minutes'];
            $y = $p['temp_f'];
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }

        $denom = $n * $sumX2 - $sumX * $sumX;
        if (abs($denom) < 1e-10) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denom;
    }

    /**
     * Calculate overshoot: max temp after heater off minus temp at heater off.
     */
    private function calculateOvershoot(float $offTemp, array $afterReadings): float
    {
        if (empty($afterReadings)) {
            return 0.0;
        }

        $maxTemp = $offTemp;
        foreach ($afterReadings as $r) {
            if ($r['water_temp_f'] > $maxTemp) {
                $maxTemp = $r['water_temp_f'];
            }
        }

        return $maxTemp - $offTemp;
    }

    private function parseJsonlFile(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $content = file_get_contents($file);
        if (empty(trim($content))) {
            return [];
        }

        $lines = explode("\n", trim($content));
        $records = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $record = json_decode($line, true);
            if (is_array($record)) {
                $records[] = $record;
            }
        }

        return $records;
    }

    private function mean(array $values): float
    {
        if (empty($values)) {
            return 0.0;
        }
        return array_sum($values) / count($values);
    }

    /**
     * Fit Newton's Law cooling coefficient from temperature data.
     *
     * Newton's Law: dT/dt = -k × (T_water - T_ambient)
     * Fits k via regression through origin: k = Σ(x·y) / Σ(x²)
     * where x = ΔT (water - ambient), y = cooling_rate (positive when cooling)
     */
    private function fitNewtonCoolingCoefficient(array $temps, array $events): array
    {
        $emptyResult = [
            'cooling_coefficient_k' => null,
            'cooling_data_points' => 0,
            'cooling_r_squared' => null,
        ];

        if (empty($temps) || empty($events)) {
            return $emptyResult;
        }

        // Build list of heater off→on windows
        $offEvents = [];
        $onEvents = [];
        foreach ($events as $event) {
            if (($event['equipment'] ?? '') !== 'heater') {
                continue;
            }
            if ($event['action'] === 'off') {
                $offEvents[] = strtotime($event['timestamp']);
            } elseif ($event['action'] === 'on') {
                $onEvents[] = strtotime($event['timestamp']);
            }
        }

        if (empty($offEvents)) {
            return $emptyResult;
        }

        $settleSeconds = $this->coolingSettleMinutes * 60;

        // Collect (delta_temp, cooling_rate) data points from all cooling windows
        $dataPoints = []; // [{x: delta_temp, y: cooling_rate}, ...]

        foreach ($offEvents as $offTime) {
            $coolStart = $offTime + $settleSeconds;

            // Find next heater-on event after this off event
            $coolEnd = PHP_INT_MAX;
            foreach ($onEvents as $onTime) {
                if ($onTime > $offTime) {
                    $coolEnd = $onTime;
                    break;
                }
            }

            // Collect readings in the cooling window
            $coolReadings = [];
            foreach ($temps as $t) {
                $ts = strtotime($t['timestamp']);
                if ($ts >= $coolStart && $ts < $coolEnd) {
                    $coolReadings[] = $t;
                }
            }

            if (count($coolReadings) < $this->coolingStride) {
                continue;
            }

            // Non-overlapping window regression: fit a line to each window of
            // readings to get dT/dt. This averages out DS18B20 quantization noise
            // (0.0625°C / 0.1125°F resolution). Non-overlapping windows produce
            // independent data points, avoiding correlated noise from overlap.
            $windowSize = $this->coolingStride;
            $maxWindowMinutes = $windowSize * 10; // reject if time gaps inflate window

            for ($i = 0; $i <= count($coolReadings) - $windowSize; $i += $windowSize) {
                $window = array_slice($coolReadings, $i, $windowSize);

                $ts0 = strtotime($window[0]['timestamp']);
                $tsLast = strtotime($window[$windowSize - 1]['timestamp']);
                $windowSpan = ($tsLast - $ts0) / 60.0;

                // Skip windows with missing data (time gaps)
                if ($windowSpan <= 0 || $windowSpan > $maxWindowMinutes) {
                    continue;
                }

                // Linear regression: water_temp_f = intercept + slope * time_minutes
                $n = count($window);
                $sumT = 0.0;
                $sumY = 0.0;
                $sumTY = 0.0;
                $sumT2 = 0.0;
                $sumDeltaTemp = 0.0;

                foreach ($window as $r) {
                    $t = (strtotime($r['timestamp']) - $ts0) / 60.0;
                    $y = $r['water_temp_f'];
                    $sumT += $t;
                    $sumY += $y;
                    $sumTY += $t * $y;
                    $sumT2 += $t * $t;
                    $sumDeltaTemp += $r['water_temp_f'] - $r['ambient_temp_f'];
                }

                $denom = $n * $sumT2 - $sumT * $sumT;
                if (abs($denom) < 1e-10) {
                    continue;
                }

                $slope = ($n * $sumTY - $sumT * $sumY) / $denom; // °F/min
                $coolingRate = -$slope; // positive when cooling

                $meanDeltaTemp = $sumDeltaTemp / $n;

                // Skip near-equilibrium noise
                if (abs($meanDeltaTemp) < 1.0) {
                    continue;
                }

                $dataPoints[] = ['x' => $meanDeltaTemp, 'y' => $coolingRate];
            }
        }

        if (empty($dataPoints)) {
            return $emptyResult;
        }

        // Prune outliers: remove points with anomalously high k (pump, cover off, etc.)
        // Cooling can only be artificially fast, never artificially slow.
        $dataPoints = $this->pruneHighKOutliers($dataPoints);

        if (empty($dataPoints)) {
            return $emptyResult;
        }

        // Fit k via regression through origin: k = Σ(x·y) / Σ(x²)
        $sumXY = 0.0;
        $sumX2 = 0.0;
        foreach ($dataPoints as $p) {
            $sumXY += $p['x'] * $p['y'];
            $sumX2 += $p['x'] * $p['x'];
        }

        if ($sumX2 < 1e-10) {
            return $emptyResult;
        }

        $k = $sumXY / $sumX2;

        // Compute R² for regression through origin: 1 - SS_res / SS_tot
        // For through-origin models (y = kx), the null model is y = 0,
        // so SS_tot = Σ(yi²), not Σ(yi - ȳ)².
        $ssRes = 0.0;
        $ssTot = 0.0;
        foreach ($dataPoints as $p) {
            $predicted = $k * $p['x'];
            $ssRes += ($p['y'] - $predicted) ** 2;
            $ssTot += $p['y'] ** 2;
        }

        $rSquared = $ssTot > 1e-10 ? 1.0 - ($ssRes / $ssTot) : null;

        return [
            'cooling_coefficient_k' => round($k, 6),
            'cooling_data_points' => count($dataPoints),
            'cooling_r_squared' => $rSquared !== null ? round($rSquared, 4) : null,
        ];
    }

    /**
     * Remove data points with anomalously high per-point k values.
     *
     * Cooling can only be artificially fast (pump running, cover off, person in tub),
     * never artificially slow. So we keep the low-k points and discard high-k outliers.
     *
     * @param array $dataPoints [{x: delta_temp, y: cooling_rate}, ...]
     * @return array Filtered data points
     */
    private function pruneHighKOutliers(array $dataPoints, float $threshold = 2.0): array
    {
        // Compute per-point k = cooling_rate / delta_temp
        $perPointK = [];
        foreach ($dataPoints as $i => $p) {
            if (abs($p['x']) > 1e-10) {
                $perPointK[$i] = $p['y'] / $p['x'];
            }
        }

        // Keep only positive k values for median calculation
        $positiveK = array_filter($perPointK, fn($k) => $k > 0);

        if (count($positiveK) < 3) {
            return $dataPoints; // Not enough data to detect outliers
        }

        // Find median k
        $sorted = array_values($positiveK);
        sort($sorted);
        $mid = intdiv(count($sorted), 2);
        $medianK = count($sorted) % 2 === 0
            ? ($sorted[$mid - 1] + $sorted[$mid]) / 2.0
            : $sorted[$mid];

        // Keep points where k >= 0 and k <= threshold * median.
        // Zero-k points are legitimate (sensor quantization), not outliers.
        // Only discard negative k (warming) and high k (pump/cover/human).
        $filtered = [];
        foreach ($dataPoints as $i => $p) {
            if (!isset($perPointK[$i])) {
                continue;
            }
            $ki = $perPointK[$i];
            if ($ki >= 0 && $ki <= $threshold * $medianK) {
                $filtered[] = $p;
            }
        }

        return $filtered;
    }

    private function emptyResults(): array
    {
        return [
            'heating_velocity_f_per_min' => null,
            'startup_lag_minutes' => null,
            'overshoot_degrees_f' => null,
            'cooling_coefficient_k' => null,
            'cooling_data_points' => 0,
            'cooling_r_squared' => null,
            'sessions_analyzed' => 0,
            'sessions' => [],
            'generated_at' => date('c'),
        ];
    }
}
