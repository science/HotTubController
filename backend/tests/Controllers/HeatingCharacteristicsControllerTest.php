<?php

declare(strict_types=1);

namespace HotTub\Tests\Controllers;

use HotTub\Controllers\HeatingCharacteristicsController;
use HotTub\Services\HeatingCharacteristicsService;
use PHPUnit\Framework\TestCase;

class HeatingCharacteristicsControllerTest extends TestCase
{
    private string $testDir;
    private string $resultsFile;
    private string $logsDir;
    private string $eventLogFile;

    protected function setUp(): void
    {
        $this->testDir = sys_get_temp_dir() . '/heating-ctrl-test-' . uniqid();
        mkdir($this->testDir . '/logs', 0755, true);
        mkdir($this->testDir . '/state', 0755, true);
        $this->resultsFile = $this->testDir . '/state/heating-characteristics.json';
        $this->logsDir = $this->testDir . '/logs';
        $this->eventLogFile = $this->testDir . '/logs/equipment-events.log';
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->testDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->testDir);
    }

    public function testGetReturnsNullWhenNoResults(): void
    {
        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertNull($response['body']['results']);
    }

    public function testGetReturnsStoredResults(): void
    {
        $stored = ['heating_velocity_f_per_min' => 0.45, 'sessions_analyzed' => 2];
        file_put_contents($this->resultsFile, json_encode($stored));

        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        $response = $controller->get();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(0.45, $response['body']['results']['heating_velocity_f_per_min']);
    }

    public function testGenerateRunsAnalysisAndStoresResults(): void
    {
        // Copy fixture files into test logs dir
        $fixtureDir = __DIR__ . '/../Fixtures';
        copy($fixtureDir . '/heating-sessions-temperature.log', $this->logsDir . '/temperature-2026-01-20.log');
        copy($fixtureDir . '/heating-sessions-events.log', $this->eventLogFile);

        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        $response = $controller->generate();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(2, $response['body']['results']['sessions_analyzed']);
        $this->assertFileExists($this->resultsFile);
    }

    public function testGenerateWithLookbackDaysFiltersOldLogs(): void
    {
        // Create two temp log files: one "old" (20 days ago) and one "recent" (today)
        $oldDate = date('Y-m-d', strtotime('-20 days'));
        $todayDate = date('Y-m-d');

        // Old log file with a valid heating session (we want lookback to exclude it by date, not by velocity)
        $oldTempFile = $this->logsDir . "/temperature-{$oldDate}.log";
        file_put_contents($oldTempFile, implode("\n", [
            json_encode(['timestamp' => "{$oldDate}T14:00:00+00:00", 'water_temp_f' => 80.0, 'water_temp_c' => 26.7, 'ambient_temp_f' => 55.0, 'ambient_temp_c' => 12.8, 'heater_on' => true]),
            json_encode(['timestamp' => "{$oldDate}T14:10:00+00:00", 'water_temp_f' => 85.0, 'water_temp_c' => 29.4, 'ambient_temp_f' => 55.0, 'ambient_temp_c' => 12.8, 'heater_on' => true]),
            json_encode(['timestamp' => "{$oldDate}T14:20:00+00:00", 'water_temp_f' => 90.0, 'water_temp_c' => 32.2, 'ambient_temp_f' => 55.0, 'ambient_temp_c' => 12.8, 'heater_on' => false]),
            json_encode(['timestamp' => "{$oldDate}T14:25:00+00:00", 'water_temp_f' => 91.0, 'water_temp_c' => 32.8, 'ambient_temp_f' => 55.0, 'ambient_temp_c' => 12.8, 'heater_on' => false]),
        ]));

        // Recent log file with valid heating
        $recentTempFile = $this->logsDir . "/temperature-{$todayDate}.log";
        file_put_contents($recentTempFile, implode("\n", [
            json_encode(['timestamp' => "{$todayDate}T14:00:00+00:00", 'water_temp_f' => 80.0, 'water_temp_c' => 26.7, 'ambient_temp_f' => 50.0, 'ambient_temp_c' => 10.0, 'heater_on' => true]),
            json_encode(['timestamp' => "{$todayDate}T14:10:00+00:00", 'water_temp_f' => 85.0, 'water_temp_c' => 29.4, 'ambient_temp_f' => 50.0, 'ambient_temp_c' => 10.0, 'heater_on' => true]),
            json_encode(['timestamp' => "{$todayDate}T14:20:00+00:00", 'water_temp_f' => 90.0, 'water_temp_c' => 32.2, 'ambient_temp_f' => 50.0, 'ambient_temp_c' => 10.0, 'heater_on' => true]),
            json_encode(['timestamp' => "{$todayDate}T14:30:00+00:00", 'water_temp_f' => 95.0, 'water_temp_c' => 35.0, 'ambient_temp_f' => 50.0, 'ambient_temp_c' => 10.0, 'heater_on' => true]),
            json_encode(['timestamp' => "{$todayDate}T14:40:00+00:00", 'water_temp_f' => 100.0, 'water_temp_c' => 37.8, 'ambient_temp_f' => 50.0, 'ambient_temp_c' => 10.0, 'heater_on' => false]),
        ]));

        // Events file with both sessions
        file_put_contents($this->eventLogFile, implode("\n", [
            json_encode(['timestamp' => "{$oldDate}T14:00:00+00:00", 'equipment' => 'heater', 'action' => 'on', 'water_temp_f' => 80.0]),
            json_encode(['timestamp' => "{$oldDate}T14:20:00+00:00", 'equipment' => 'heater', 'action' => 'off', 'water_temp_f' => 90.0]),
            json_encode(['timestamp' => "{$todayDate}T14:00:00+00:00", 'equipment' => 'heater', 'action' => 'on', 'water_temp_f' => 80.0]),
            json_encode(['timestamp' => "{$todayDate}T14:40:00+00:00", 'equipment' => 'heater', 'action' => 'off', 'water_temp_f' => 100.0]),
        ]));

        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        // Lookback 5 days should exclude the 20-day-old log file
        $response = $controller->generate(['lookback_days' => '5']);

        $this->assertEquals(200, $response['status']);
        // Only the recent valid session should be analyzed
        $this->assertEquals(1, $response['body']['results']['sessions_analyzed']);
        $this->assertGreaterThan(0, $response['body']['results']['heating_velocity_f_per_min']);
    }

    public function testGenerateHandlesEmptyLogs(): void
    {
        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        $response = $controller->generate();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(0, $response['body']['results']['sessions_analyzed']);
    }
}
