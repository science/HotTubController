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
        $this->assertArrayHasKey('sessions_analyzed', $results);
        $this->assertArrayHasKey('generated_at', $results);

        $this->assertEquals(2, $results['sessions_analyzed']);
        $this->assertGreaterThan(0, $results['heating_velocity_f_per_min']);
        $this->assertGreaterThan(0, $results['overshoot_degrees_f']);
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
}
