<?php

declare(strict_types=1);

namespace HotTub\Tests\Unit;

use PHPUnit\Framework\TestCase;
use HotTub\Services\TemperatureStateService;

/**
 * Tests for TemperatureStateService.
 *
 * This service manages the async refresh state for temperature readings.
 * It tracks when a refresh was requested so we can determine if a refresh
 * is in progress, succeeded, or timed out.
 */
class TemperatureStateServiceTest extends TestCase
{
    private string $testStateFile;
    private TemperatureStateService $service;

    protected function setUp(): void
    {
        $this->testStateFile = sys_get_temp_dir() . '/test_temp_state_' . uniqid() . '.json';
        $this->service = new TemperatureStateService($this->testStateFile);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->testStateFile)) {
            unlink($this->testStateFile);
        }
    }

    /**
     * @test
     */
    public function canStoreRefreshRequestedTimestamp(): void
    {
        $timestamp = new \DateTimeImmutable('2025-12-15T10:00:00+00:00');

        $this->service->markRefreshRequested($timestamp);

        $state = $this->service->getState();
        $this->assertEquals($timestamp->format('c'), $state['refresh_requested_at']);
    }

    /**
     * @test
     */
    public function getStateReturnsNullWhenNoStateExists(): void
    {
        $state = $this->service->getState();

        $this->assertNull($state);
    }

    /**
     * @test
     */
    public function canClearRefreshState(): void
    {
        $timestamp = new \DateTimeImmutable('2025-12-15T10:00:00+00:00');
        $this->service->markRefreshRequested($timestamp);

        $this->service->clearRefreshState();

        $state = $this->service->getState();
        $this->assertNull($state);
    }

    /**
     * @test
     */
    public function isRefreshInProgressReturnsFalseWhenNoState(): void
    {
        $sensorTimestamp = new \DateTimeImmutable('2025-12-15T10:00:00+00:00');

        $result = $this->service->isRefreshInProgress($sensorTimestamp);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function isRefreshInProgressReturnsTrueWhenSensorTimestampOlderThanRequest(): void
    {
        // Use relative times so timeout logic works correctly
        $requestTime = new \DateTimeImmutable('-2 seconds');        // Request made 2 sec ago
        $sensorTimestamp = new \DateTimeImmutable('-10 seconds');   // Sensor data is 10 sec old

        $this->service->markRefreshRequested($requestTime);

        $result = $this->service->isRefreshInProgress($sensorTimestamp);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function isRefreshInProgressReturnsFalseWhenSensorTimestampNewerThanRequest(): void
    {
        // Use relative times for consistency
        $requestTime = new \DateTimeImmutable('-10 seconds');       // Request made 10 sec ago
        $sensorTimestamp = new \DateTimeImmutable('-5 seconds');    // Sensor data is 5 sec old (newer than request)

        $this->service->markRefreshRequested($requestTime);

        $result = $this->service->isRefreshInProgress($sensorTimestamp);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function isRefreshInProgressReturnsFalseWhenRequestTimedOut(): void
    {
        // Request made 20 seconds ago (past 15 second timeout)
        $requestTime = new \DateTimeImmutable('-20 seconds');
        $sensorTimestamp = new \DateTimeImmutable('-30 seconds'); // Even older

        $this->service->markRefreshRequested($requestTime);

        $result = $this->service->isRefreshInProgress($sensorTimestamp);

        $this->assertFalse($result);
    }

    /**
     * @test
     */
    public function isRefreshInProgressReturnsTrueWithinTimeoutWindow(): void
    {
        // Request made 5 seconds ago (within 15 second timeout)
        $requestTime = new \DateTimeImmutable('-5 seconds');
        $sensorTimestamp = new \DateTimeImmutable('-30 seconds'); // Older than request

        $this->service->markRefreshRequested($requestTime);

        $result = $this->service->isRefreshInProgress($sensorTimestamp);

        $this->assertTrue($result);
    }

    /**
     * @test
     */
    public function getRefreshRequestedAtReturnsTimestampWhenSet(): void
    {
        $timestamp = new \DateTimeImmutable('2025-12-15T10:00:00+00:00');
        $this->service->markRefreshRequested($timestamp);

        $result = $this->service->getRefreshRequestedAt();

        $this->assertInstanceOf(\DateTimeImmutable::class, $result);
        $this->assertEquals($timestamp->format('c'), $result->format('c'));
    }

    /**
     * @test
     */
    public function getRefreshRequestedAtReturnsNullWhenNotSet(): void
    {
        $result = $this->service->getRefreshRequestedAt();

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function stateFileIsCreatedInDirectoryIfNotExists(): void
    {
        $nestedPath = sys_get_temp_dir() . '/nested_' . uniqid() . '/temp_state.json';
        $service = new TemperatureStateService($nestedPath);

        $service->markRefreshRequested(new \DateTimeImmutable());

        $this->assertFileExists($nestedPath);

        // Cleanup
        unlink($nestedPath);
        rmdir(dirname($nestedPath));
    }
}
