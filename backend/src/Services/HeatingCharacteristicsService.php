<?php

declare(strict_types=1);

namespace HotTub\Services;

class HeatingCharacteristicsService
{
    private float $startupLagThresholdF;
    private int $cooldownThresholdMinutes;

    public function __construct(
        float $startupLagThresholdF = 0.2,
        int $cooldownThresholdMinutes = 30
    ) {
        $this->startupLagThresholdF = $startupLagThresholdF;
        $this->cooldownThresholdMinutes = $cooldownThresholdMinutes;
    }

    /**
     * Generate aggregate heating characteristics from log files.
     *
     * @param string[] $tempLogFiles Array of temperature log file paths
     * @param string $eventLogFile Equipment events log file path
     */
    public function generate(array $tempLogFiles, string $eventLogFile): array
    {
        $allTemps = [];
        foreach ($tempLogFiles as $file) {
            $allTemps = array_merge($allTemps, $this->parseJsonlFile($file));
        }
        usort($allTemps, fn($a, $b) => strtotime($a['timestamp']) - strtotime($b['timestamp']));

        $events = $this->parseJsonlFile($eventLogFile);

        if (empty($events) || empty($allTemps)) {
            return $this->emptyResults();
        }

        $sessions = $this->buildSessions($allTemps, $events);

        if (empty($sessions)) {
            return $this->emptyResults();
        }

        $velocities = array_column($sessions, 'heating_velocity_f_per_min');
        $lags = array_column($sessions, 'startup_lag_minutes');
        $overshoots = array_column($sessions, 'overshoot_degrees_f');

        return [
            'heating_velocity_f_per_min' => round($this->mean($velocities), 3),
            'startup_lag_minutes' => round($this->mean($lags), 1),
            'overshoot_degrees_f' => round($this->mean($overshoots), 2),
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

    private function emptyResults(): array
    {
        return [
            'heating_velocity_f_per_min' => null,
            'startup_lag_minutes' => null,
            'overshoot_degrees_f' => null,
            'sessions_analyzed' => 0,
            'sessions' => [],
            'generated_at' => date('c'),
        ];
    }
}
