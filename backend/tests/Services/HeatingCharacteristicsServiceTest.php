<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\HeatingCharacteristicsService;
use PHPUnit\Framework\TestCase;

class HeatingCharacteristicsServiceTest extends TestCase
{
    private string $fixtureDir;

    protected function setUp(): void
    {
        $this->fixtureDir = __DIR__ . '/../Fixtures';
    }

    // ========== Session Extraction Tests ==========

    public function testExtractsHeatingSessionsFromLogs(): void
    {
        $service = new HeatingCharacteristicsService();

        $tempLogFile = $this->fixtureDir . '/heating-sessions-temperature.log';
        $eventLogFile = $this->fixtureDir . '/heating-sessions-events.log';

        $sessions = $service->extractSessions($tempLogFile, $eventLogFile);

        $this->assertCount(2, $sessions);
    }

    public function testSessionContainsHeaterOnAndOffTimestamps(): void
    {
        $service = new HeatingCharacteristicsService();

        $sessions = $service->extractSessions(
            $this->fixtureDir . '/heating-sessions-temperature.log',
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        $session = $sessions[0];
        $this->assertEquals('2026-01-20T08:20:00+00:00', $session['heater_on_at']);
        $this->assertEquals('2026-01-20T08:48:00+00:00', $session['heater_off_at']);
        $this->assertEqualsWithDelta(86.5625, $session['start_temp_f'], 0.01);
        $this->assertEqualsWithDelta(97.0, $session['end_temp_f'], 0.01);
    }

    // ========== Heating Velocity Tests ==========

    public function testCalculatesHeatingVelocity(): void
    {
        $service = new HeatingCharacteristicsService();

        $sessions = $service->extractSessions(
            $this->fixtureDir . '/heating-sessions-temperature.log',
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        // Session 1: ~0.45 F/min during steady-state (after startup lag)
        $this->assertEqualsWithDelta(0.45, $sessions[0]['heating_velocity_f_per_min'], 0.05);
    }

    // ========== Startup Lag Tests ==========

    public function testDetectsStartupLagForColdStart(): void
    {
        $service = new HeatingCharacteristicsService();

        $sessions = $service->extractSessions(
            $this->fixtureDir . '/heating-sessions-temperature.log',
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        // Session 1 (cold start): ~3 min startup lag
        $this->assertEqualsWithDelta(3.0, $sessions[0]['startup_lag_minutes'], 1.0);
    }

    public function testWarmRestartHasMinimalStartupLag(): void
    {
        $service = new HeatingCharacteristicsService();

        $sessions = $service->extractSessions(
            $this->fixtureDir . '/heating-sessions-temperature.log',
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        // Session 2 (warm restart, 57 min gap): minimal lag
        $this->assertLessThanOrEqual(1.0, $sessions[1]['startup_lag_minutes']);
    }

    // ========== Overshoot Tests ==========

    public function testCalculatesOvershoot(): void
    {
        $service = new HeatingCharacteristicsService();

        $sessions = $service->extractSessions(
            $this->fixtureDir . '/heating-sessions-temperature.log',
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        // Session 1: heater off at 97.0, peaks at 98.2 → overshoot ~1.2
        $this->assertEqualsWithDelta(1.2, $sessions[0]['overshoot_degrees_f'], 0.2);

        // Session 2: heater off at 100.0, peaks at 100.8 → overshoot ~0.8
        $this->assertEqualsWithDelta(0.8, $sessions[1]['overshoot_degrees_f'], 0.2);
    }

    // ========== Aggregate Results Tests ==========

    public function testGenerateReturnsAggregateResults(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/heating-sessions-temperature.log'],
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        $this->assertArrayHasKey('heating_velocity_f_per_min', $results);
        $this->assertArrayHasKey('startup_lag_minutes', $results);
        $this->assertArrayHasKey('overshoot_degrees_f', $results);
        $this->assertArrayHasKey('cooling_coefficient_k', $results);
        $this->assertArrayHasKey('cooling_data_points', $results);
        $this->assertArrayHasKey('cooling_r_squared', $results);
        $this->assertArrayHasKey('sessions_analyzed', $results);
        $this->assertArrayHasKey('generated_at', $results);

        $this->assertEquals(2, $results['sessions_analyzed']);
        $this->assertGreaterThan(0, $results['heating_velocity_f_per_min']);
        $this->assertGreaterThan(0, $results['overshoot_degrees_f']);
    }

    // ========== Garbage Session Filtering Tests ==========

    public function testGenerateExcludesGarbageSessionsFromAggregates(): void
    {
        $service = new HeatingCharacteristicsService();

        // This fixture has a garbage session (Jan 29: 8+ hrs, temp dropped 89.9→84.6)
        // plus a valid session (Jan 30: 52 min, 79.9→101.75)
        $results = $service->generate(
            [$this->fixtureDir . '/garbage-session-temperature.log'],
            $this->fixtureDir . '/garbage-session-events.log'
        );

        // Only the valid session should be counted in aggregates
        $this->assertEquals(1, $results['sessions_analyzed']);
        $this->assertGreaterThan(0.3, $results['heating_velocity_f_per_min']);
    }

    public function testProdFixturesProduceValidStats(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/prod-2026-01-30-temperature.log'],
            $this->fixtureDir . '/prod-2026-01-30-events.log'
        );

        // Two valid sessions: cold start (~0.5°F/min) and warm restart (~0.48°F/min)
        $this->assertEquals(2, $results['sessions_analyzed']);
        $this->assertEqualsWithDelta(0.49, $results['heating_velocity_f_per_min'], 0.05);
    }

    // ========== Date Range Filtering Tests ==========

    public function testGenerateFiltersEventsByDateRange(): void
    {
        $service = new HeatingCharacteristicsService();

        // Use garbage fixture which has events on Jan 29 and Jan 30
        // Filter to only Jan 30 — should exclude the garbage Jan 29 session
        $results = $service->generate(
            [$this->fixtureDir . '/garbage-session-temperature.log'],
            $this->fixtureDir . '/garbage-session-events.log',
            '2026-01-30',
            '2026-01-30'
        );

        $this->assertEquals(1, $results['sessions_analyzed']);
        $this->assertGreaterThan(0.3, $results['heating_velocity_f_per_min']);
    }

    public function testGenerateWithStartDateOnlyFiltersOlderEvents(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/garbage-session-temperature.log'],
            $this->fixtureDir . '/garbage-session-events.log',
            '2026-01-30'
        );

        // Only Jan 30 session should remain
        $this->assertEquals(1, $results['sessions_analyzed']);
    }

    // ========== Edge Cases ==========

    public function testHandlesEmptyLogs(): void
    {
        $service = new HeatingCharacteristicsService();

        $tmpDir = sys_get_temp_dir() . '/heating-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $emptyTemp = $tmpDir . '/empty.log';
        $emptyEvents = $tmpDir . '/empty-events.log';
        file_put_contents($emptyTemp, '');
        file_put_contents($emptyEvents, '');

        $results = $service->generate([$emptyTemp], $emptyEvents);

        $this->assertEquals(0, $results['sessions_analyzed']);
        $this->assertNull($results['heating_velocity_f_per_min']);
        $this->assertNull($results['startup_lag_minutes']);
        $this->assertNull($results['overshoot_degrees_f']);

        unlink($emptyTemp);
        unlink($emptyEvents);
        rmdir($tmpDir);
    }

    public function testHandlesMissingFiles(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(['/nonexistent/temp.log'], '/nonexistent/events.log');

        $this->assertEquals(0, $results['sessions_analyzed']);
    }

    // ========== Newton Cooling Coefficient Tests ==========

    public function testCoolingCoefficientCalculation(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/newton-cooling-temperature.log'],
            $this->fixtureDir . '/newton-cooling-events.log'
        );

        // Fixture generated with k = 0.001/min
        $this->assertNotNull($results['cooling_coefficient_k']);
        $this->assertEqualsWithDelta(0.001, $results['cooling_coefficient_k'], 0.0002);
    }

    public function testCoolingRSquared(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/newton-cooling-temperature.log'],
            $this->fixtureDir . '/newton-cooling-events.log'
        );

        // Clean synthetic data should have R² > 0.95
        $this->assertNotNull($results['cooling_r_squared']);
        $this->assertGreaterThan(0.95, $results['cooling_r_squared']);
    }

    public function testCoolingDataPointCount(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/newton-cooling-temperature.log'],
            $this->fixtureDir . '/newton-cooling-events.log'
        );

        // 3 cooling regimes with 5-min intervals over ~17.75 hours after settle
        // Regime 1: 02:20-08:00 = 68 intervals, Regime 2: 08:05-14:00 = 71, Regime 3: 14:05-20:00 = 71
        // Each pair of consecutive readings gives one data point, so count = readings - 1
        // Total cooling readings after settle (02:15+): 69+72+72 = 213, pairs = 212
        // But the pair at each ambient transition (08:00→08:05, 14:00→14:05) spans the boundary
        // and has a valid 5-min dt, so it should be included
        $this->assertGreaterThan(200, $results['cooling_data_points']);
    }

    public function testCoolingNullWhenNoData(): void
    {
        $service = new HeatingCharacteristicsService();

        $tmpDir = sys_get_temp_dir() . '/cooling-test-' . uniqid();
        mkdir($tmpDir, 0755, true);
        $emptyTemp = $tmpDir . '/empty.log';
        $emptyEvents = $tmpDir . '/empty-events.log';
        file_put_contents($emptyTemp, '');
        file_put_contents($emptyEvents, '');

        $results = $service->generate([$emptyTemp], $emptyEvents);

        $this->assertNull($results['cooling_coefficient_k']);
        $this->assertNull($results['cooling_r_squared']);
        $this->assertEquals(0, $results['cooling_data_points']);

        unlink($emptyTemp);
        unlink($emptyEvents);
        rmdir($tmpDir);
    }

    public function testCoolingSkipsSettlePeriod(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/newton-cooling-temperature.log'],
            $this->fixtureDir . '/newton-cooling-events.log'
        );

        // If settle period readings (02:01-02:15) were included, k would be
        // distorted. With clean Newton data, k should be very close to 0.001.
        $this->assertEqualsWithDelta(0.001, $results['cooling_coefficient_k'], 0.0002);
    }

    public function testCoolingSkipsSmallDeltaT(): void
    {
        $service = new HeatingCharacteristicsService();

        // Create fixture where water ≈ ambient (near equilibrium)
        $tmpDir = sys_get_temp_dir() . '/cooling-small-delta-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $tempFile = $tmpDir . '/temp.log';
        $eventFile = $tmpDir . '/events.log';

        // Heating phase to have a valid session
        $lines = [];
        for ($i = 0; $i <= 10; $i++) {
            $t = 70.0 + $i * 0.5;
            $ts = sprintf('2026-02-01T01:%02d:00+00:00', $i);
            $lines[] = json_encode([
                'timestamp' => $ts,
                'water_temp_f' => $t,
                'water_temp_c' => ($t - 32) * 5 / 9,
                'ambient_temp_f' => 50.0,
                'ambient_temp_c' => 10.0,
                'heater_on' => true,
            ]);
        }
        // Cooling phase: water ≈ ambient (both ~70°F, ΔT < 1°F)
        for ($i = 0; $i < 30; $i++) {
            $min = 16 + $i; // Start after 15-min settle
            $ts = sprintf('2026-02-01T01:%02d:00+00:00', $min);
            $water = 70.0 + 0.5 * sin($i * 0.1); // oscillates within ±0.5°F of ambient
            $lines[] = json_encode([
                'timestamp' => $ts,
                'water_temp_f' => round($water, 2),
                'water_temp_c' => round(($water - 32) * 5 / 9, 4),
                'ambient_temp_f' => 70.0,
                'ambient_temp_c' => 21.1111,
                'heater_on' => false,
            ]);
        }
        file_put_contents($tempFile, implode("\n", $lines) . "\n");

        $events = [
            json_encode(['timestamp' => '2026-02-01T01:00:00+00:00', 'equipment' => 'heater', 'action' => 'on', 'water_temp_f' => 70.0]),
            json_encode(['timestamp' => '2026-02-01T01:00:00+00:00', 'equipment' => 'heater', 'action' => 'off', 'water_temp_f' => 75.0]),
        ];
        file_put_contents($eventFile, implode("\n", $events) . "\n");

        $results = $service->generate([$tempFile], $eventFile);

        // All cooling points have |ΔT| < 1°F, so should be filtered out
        $this->assertEquals(0, $results['cooling_data_points']);
        $this->assertNull($results['cooling_coefficient_k']);

        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
    }

    public function testExistingHeatingUnchanged(): void
    {
        $service = new HeatingCharacteristicsService();

        $results = $service->generate(
            [$this->fixtureDir . '/heating-sessions-temperature.log'],
            $this->fixtureDir . '/heating-sessions-events.log'
        );

        // Heating metrics should be unchanged
        $this->assertEquals(2, $results['sessions_analyzed']);
        $this->assertEqualsWithDelta(0.45, $results['heating_velocity_f_per_min'], 0.05);

        // New cooling fields should exist
        $this->assertArrayHasKey('cooling_coefficient_k', $results);
        $this->assertArrayHasKey('cooling_data_points', $results);
        $this->assertArrayHasKey('cooling_r_squared', $results);
    }

    // ========== Production Data Validation (Phase 0) ==========

    public function testCoolingPrunesHighKOutliers(): void
    {
        $tmpDir = sys_get_temp_dir() . '/cooling-outlier-' . uniqid();
        mkdir($tmpDir, 0755, true);

        $tempFile = $tmpDir . '/temp.log';
        $eventFile = $tmpDir . '/events.log';

        $events = [
            json_encode(['timestamp' => '2026-02-01T01:00:00+00:00', 'equipment' => 'heater', 'action' => 'on', 'water_temp_f' => 85.0]),
            json_encode(['timestamp' => '2026-02-01T02:00:00+00:00', 'equipment' => 'heater', 'action' => 'off', 'water_temp_f' => 102.0]),
        ];
        file_put_contents($eventFile, implode("\n", $events) . "\n");

        // Generate cooling data: 60 five-minute intervals after settle
        // Readings 0-24: clean k=0.001, readings 25-30: pump k=0.005, readings 31-59: clean k=0.001
        $ambient = 40.0;
        $temp = 102.0;
        $kClean = 0.001;
        $kPump = 0.005;
        $lines = [];

        // Heating phase (for valid session)
        for ($m = 0; $m <= 60; $m++) {
            $t = 85.0 + (102.0 - 85.0) * ($m / 60.0);
            $ts = sprintf('2026-02-01T01:%02d:00+00:00', $m);
            $lines[] = json_encode([
                'timestamp' => $ts,
                'water_temp_f' => round($t, 4),
                'water_temp_c' => round(($t - 32) * 5 / 9, 4),
                'ambient_temp_f' => $ambient,
                'ambient_temp_c' => round(($ambient - 32) * 5 / 9, 4),
                'heater_on' => true,
            ]);
        }

        // Cooling phase: start 20 min after off (past 15-min settle)
        // 60 intervals of 5 min = 5 hours of cooling
        for ($i = 0; $i < 60; $i++) {
            $minutesSinceOff = 20 + $i * 5;
            $isPump = ($i >= 25 && $i < 31); // 6 pump intervals
            $k = $isPump ? $kPump : $kClean;

            $deltaT = $temp - $ambient;
            $tempDrop = $k * $deltaT * 5.0;
            $temp -= $tempDrop;

            $totalMinutes = 120 + $minutesSinceOff; // minutes from midnight
            $hour = intdiv($totalMinutes, 60);
            $min = $totalMinutes % 60;
            $ts = sprintf('2026-02-01T%02d:%02d:00+00:00', $hour, $min);
            $lines[] = json_encode([
                'timestamp' => $ts,
                'water_temp_f' => round($temp, 4),
                'water_temp_c' => round(($temp - 32) * 5 / 9, 4),
                'ambient_temp_f' => $ambient,
                'ambient_temp_c' => round(($ambient - 32) * 5 / 9, 4),
                'heater_on' => false,
            ]);
        }

        file_put_contents($tempFile, implode("\n", $lines) . "\n");

        $service = new HeatingCharacteristicsService();
        $results = $service->generate([$tempFile], $eventFile);

        // With pruning, k should be ≈ 0.001 (pump outliers removed)
        $this->assertNotNull($results['cooling_coefficient_k']);
        $this->assertEqualsWithDelta(0.001, $results['cooling_coefficient_k'], 0.0003);

        // R² should be high after outlier removal
        $this->assertGreaterThan(0.90, $results['cooling_r_squared']);

        // Cleanup
        array_map('unlink', glob($tmpDir . '/*'));
        rmdir($tmpDir);
    }

    // ========== Production Data Validation (Phase 0) ==========

    public function testNewtonModelAgainstProductionData(): void
    {
        $logDir = __DIR__ . '/../../storage/logs';
        $tempFiles = glob($logDir . '/temperature-*.log');

        if (empty($tempFiles)) {
            $this->markTestSkipped('No production temperature logs available');
        }

        $eventFile = $logDir . '/equipment-events.log';
        if (!file_exists($eventFile)) {
            $this->markTestSkipped('No production equipment events log available');
        }

        $service = new HeatingCharacteristicsService();
        $results = $service->generate($tempFiles, $eventFile);

        if ($results['cooling_data_points'] === 0) {
            $this->markTestSkipped('No cooling data points in production logs');
        }

        // Phase 0 go/no-go: R² > 0.3 means Newton model is viable
        $this->assertNotNull($results['cooling_coefficient_k'], 'k should not be null with production data');
        $this->assertGreaterThan(0, $results['cooling_coefficient_k'], 'k should be positive');
        $this->assertNotNull($results['cooling_r_squared'], 'R² should not be null');

        // Print results for human review
        fwrite(STDERR, sprintf(
            "\n  Production Newton's Law validation:\n" .
            "    k = %.6f /min\n" .
            "    R² = %.4f\n" .
            "    Data points: %d\n" .
            "    Predicted cooling at ΔT=40°F: %.2f°F/hr\n",
            $results['cooling_coefficient_k'],
            $results['cooling_r_squared'],
            $results['cooling_data_points'],
            $results['cooling_coefficient_k'] * 40 * 60
        ));
    }
}
