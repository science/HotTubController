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

    public function testGenerateHandlesEmptyLogs(): void
    {
        $service = new HeatingCharacteristicsService();
        $controller = new HeatingCharacteristicsController($service, $this->resultsFile, $this->logsDir, $this->eventLogFile);

        $response = $controller->generate();

        $this->assertEquals(200, $response['status']);
        $this->assertEquals(0, $response['body']['results']['sessions_analyzed']);
    }
}
