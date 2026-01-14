<?php

declare(strict_types=1);

namespace HotTub\Tests\Fixtures;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the HeatingCycleFixture helper class.
 */
class HeatingCycleFixtureTest extends TestCase
{
    private HeatingCycleFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = HeatingCycleFixture::load();
    }

    /**
     * @test
     */
    public function fixtureLoadsSuccessfully(): void
    {
        $metadata = $this->fixture->getMetadata();

        $this->assertEquals(82.0, $metadata['start_temp_f']);
        $this->assertEquals(103.5, $metadata['end_temp_f']);
        $this->assertEquals(0.5, $metadata['heating_rate_f_per_min']);
        $this->assertEquals(44, $metadata['total_readings']);
    }

    /**
     * @test
     */
    public function getAllReadingsReturns44Readings(): void
    {
        $readings = $this->fixture->getAllReadings();

        $this->assertCount(44, $readings);
    }

    /**
     * @test
     */
    public function firstReadingStartsAt82F(): void
    {
        $reading = $this->fixture->getReading(0);

        $this->assertEquals(82.0, $this->fixture->getWaterTempF($reading));
        $this->assertEquals(0, $reading['uptime_seconds']);
    }

    /**
     * @test
     */
    public function lastReadingEndsAt103Point5F(): void
    {
        $reading = $this->fixture->getReading(43);

        $this->assertEquals(103.5, $this->fixture->getWaterTempF($reading));
        $this->assertEquals(2580, $reading['uptime_seconds']); // 43 minutes
    }

    /**
     * @test
     */
    public function heatingRateIsHalfDegreePerMinute(): void
    {
        $rate = $this->fixture->calculateHeatingRate();

        $this->assertEquals(0.5, $rate);
    }

    /**
     * @test
     */
    public function getFirstReadingAtOrAboveFindsCorrectReading(): void
    {
        // 100°F should be reached at minute 36
        $reading = $this->fixture->getFirstReadingAtOrAbove(100.0);

        $this->assertEquals(36, $reading['minute']);
        $this->assertEquals(100.0, $this->fixture->getWaterTempF($reading));
    }

    /**
     * @test
     */
    public function getLastReadingBelowFindsCorrectReading(): void
    {
        // Last reading below 100°F should be minute 35 (99.5°F)
        $reading = $this->fixture->getLastReadingBelow(100.0);

        $this->assertEquals(35, $reading['minute']);
        $this->assertEquals(99.5, $this->fixture->getWaterTempF($reading));
    }

    /**
     * @test
     */
    public function getMinuteAtTemperatureReturnsCorrectMinute(): void
    {
        $this->assertEquals(36, $this->fixture->getMinuteAtTemperature(100.0));
        $this->assertEquals(16, $this->fixture->getMinuteAtTemperature(90.0));
        $this->assertEquals(0, $this->fixture->getMinuteAtTemperature(82.0));
    }

    /**
     * @test
     */
    public function toApiRequestFormatsCorrectly(): void
    {
        $reading = $this->fixture->getReading(20);
        $apiRequest = $this->fixture->toApiRequest($reading);

        $this->assertArrayHasKey('device_id', $apiRequest);
        $this->assertArrayHasKey('sensors', $apiRequest);
        $this->assertArrayHasKey('uptime_seconds', $apiRequest);
        $this->assertCount(2, $apiRequest['sensors']);

        // Sensors should not have 'role' field (that's internal to fixture)
        $this->assertArrayNotHasKey('role', $apiRequest['sensors'][0]);
        $this->assertArrayHasKey('address', $apiRequest['sensors'][0]);
        $this->assertArrayHasKey('temp_c', $apiRequest['sensors'][0]);
    }

    /**
     * @test
     */
    public function readingsHaveBothWaterAndAmbientSensors(): void
    {
        $reading = $this->fixture->getReading(10);

        $roles = array_column($reading['sensors'], 'role');
        $this->assertContains('water', $roles);
        $this->assertContains('ambient', $roles);
    }

    /**
     * @test
     */
    public function ambientTemperatureRemainsConstant(): void
    {
        $first = $this->fixture->getReading(0);
        $last = $this->fixture->getReading(43);

        $firstAmbient = null;
        $lastAmbient = null;

        foreach ($first['sensors'] as $sensor) {
            if ($sensor['role'] === 'ambient') {
                $firstAmbient = $sensor['temp_f'];
            }
        }
        foreach ($last['sensors'] as $sensor) {
            if ($sensor['role'] === 'ambient') {
                $lastAmbient = $sensor['temp_f'];
            }
        }

        $this->assertEquals(45.0, $firstAmbient);
        $this->assertEquals($firstAmbient, $lastAmbient);
    }
}
