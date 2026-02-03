<?php

declare(strict_types=1);

namespace HotTub\Services;

class HeatingCharacteristicsService
{
    private float $startupLagThresholdF;
    private int $cooldownThresholdMinutes;
    private string $timezone;
    private int $coolingSettleMinutes;

    public function __construct(
        float $startupLagThresholdF = 0.2,
        int $cooldownThresholdMinutes = 30,
        string $timezone = 'America/Los_Angeles',
        int $coolingSettleMinutes = 15
    ) {
        $this->startupLagThresholdF = $startupLagThresholdF;
        $this->cooldownThresholdMinutes = $cooldownThresholdMinutes;
        $this->timezone = $timezone;
        $this->coolingSettleMinutes = $coolingSettleMinutes;
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

        // Cooling rate analysis
        $coolingResults = $this->analyzeCoolingRates($allTemps, $events);

        return [
            'heating_velocity_f_per_min' => round($this->mean($velocities), 3),
            'startup_lag_minutes' => round($this->mean($lags), 1),
            'overshoot_degrees_f' => round($this->mean($overshoots), 2),
            'cooling_rate_day_f_per_min' => $coolingResults['cooling_rate_day_f_per_min'],
            'cooling_rate_night_f_per_min' => $coolingResults['cooling_rate_night_f_per_min'],
            'cooling_segments_day' => $coolingResults['cooling_segments_day'],
            'cooling_segments_night' => $coolingResults['cooling_segments_night'],
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
     * Analyze cooling rates from temperature data, split into day/night segments.
     *
     * Day = 9am-9pm local, Night = 9pm-9am local (in configured timezone).
     * Only considers periods where heater has been off for at least coolingSettleMinutes.
     */
    private function analyzeCoolingRates(array $temps, array $events): array
    {
        $emptyResult = [
            'cooling_rate_day_f_per_min' => null,
            'cooling_rate_night_f_per_min' => null,
            'cooling_segments_day' => 0,
            'cooling_segments_night' => 0,
        ];

        if (empty($temps) || empty($events)) {
            return $emptyResult;
        }

        // Build list of heater off→on windows
        $offEvents = [];
        $onEvents = [];
        foreach ($events as $event) {
            if (($event['equipment'] ?? '') !== 'heater') continue;
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
        $tz = new \DateTimeZone($this->timezone);

        $dayRates = [];
        $nightRates = [];

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

            if (count($coolReadings) < 2) {
                continue;
            }

            // Split readings at 9am/9pm local boundaries
            $segments = $this->splitByDayNight($coolReadings, $tz);

            foreach ($segments as $seg) {
                if (count($seg['readings']) < 2) continue;

                $points = [];
                $firstTs = strtotime($seg['readings'][0]['timestamp']);
                foreach ($seg['readings'] as $r) {
                    $points[] = [
                        'minutes' => (strtotime($r['timestamp']) - $firstTs) / 60.0,
                        'temp_f' => $r['water_temp_f'],
                    ];
                }

                $rate = $this->linearRegressionSlope($points);

                if ($seg['period'] === 'day') {
                    $dayRates[] = $rate;
                } else {
                    $nightRates[] = $rate;
                }
            }
        }

        return [
            'cooling_rate_day_f_per_min' => !empty($dayRates) ? round($this->mean($dayRates), 4) : null,
            'cooling_rate_night_f_per_min' => !empty($nightRates) ? round($this->mean($nightRates), 4) : null,
            'cooling_segments_day' => count($dayRates),
            'cooling_segments_night' => count($nightRates),
        ];
    }

    /**
     * Split temperature readings into day/night segments at 9am and 9pm local boundaries.
     *
     * @return array Array of ['period' => 'day'|'night', 'readings' => [...]]
     */
    private function splitByDayNight(array $readings, \DateTimeZone $tz): array
    {
        if (empty($readings)) return [];

        $segments = [];
        $currentSegment = [];
        $currentPeriod = null;

        foreach ($readings as $r) {
            $dt = new \DateTime($r['timestamp']);
            $dt->setTimezone($tz);
            $hour = (int) $dt->format('G');
            $period = ($hour >= 9 && $hour < 21) ? 'day' : 'night';

            if ($currentPeriod !== null && $period !== $currentPeriod) {
                // Boundary crossed — close current segment
                $segments[] = ['period' => $currentPeriod, 'readings' => $currentSegment];
                $currentSegment = [];
            }

            $currentPeriod = $period;
            $currentSegment[] = $r;
        }

        if (!empty($currentSegment)) {
            $segments[] = ['period' => $currentPeriod, 'readings' => $currentSegment];
        }

        return $segments;
    }

    private function emptyResults(): array
    {
        return [
            'heating_velocity_f_per_min' => null,
            'startup_lag_minutes' => null,
            'overshoot_degrees_f' => null,
            'cooling_rate_day_f_per_min' => null,
            'cooling_rate_night_f_per_min' => null,
            'cooling_segments_day' => 0,
            'cooling_segments_night' => 0,
            'sessions_analyzed' => 0,
            'sessions' => [],
            'generated_at' => date('c'),
        ];
    }
}
