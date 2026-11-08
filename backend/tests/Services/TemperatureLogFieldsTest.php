<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\TemperatureLogFields;
use PHPUnit\Framework\TestCase;

/**
 * Logs record the RAW sensor reading plus the calibration offset that was in
 * force at the time, so the value the controller acted on can be reconstructed
 * (calibrated °C = raw °C + offset °C) and a later change to the offset can't
 * silently rewrite history.
 */
class TemperatureLogFieldsTest extends TestCase
{
    private const WATER = '28:B4:51:02:00:00:00:9A';
    private const AMBIENT = '28:D5:AA:87:00:23:16:34';

    private function sensors(): array
    {
        // Ambient deliberately listed FIRST: the legacy top-level temp_f is
        // sensors[0], so anything keyed off position would pick the wrong probe.
        return [
            ['address' => self::AMBIENT, 'temp_c' => 16.9375, 'temp_f' => 62.4875],
            ['address' => self::WATER, 'temp_c' => 38.375, 'temp_f' => 101.075],
        ];
    }

    private function config(): array
    {
        return [
            self::WATER => ['role' => 'water', 'calibration_offset' => 0.4, 'name' => ''],
            self::AMBIENT => ['role' => 'ambient', 'calibration_offset' => -1, 'name' => 'Ambient'],
        ];
    }

    public function testForRoleReturnsRawReadingAndOffsetOfTheSensorWithThatRole(): void
    {
        $water = TemperatureLogFields::forRole($this->sensors(), $this->config(), 'water');

        $this->assertSame(101.075, $water['temp_f']);
        $this->assertSame(38.375, $water['temp_c']);
        $this->assertSame(0.4, $water['calibration_offset_c']);

        $ambient = TemperatureLogFields::forRole($this->sensors(), $this->config(), 'ambient');

        $this->assertSame(62.4875, $ambient['temp_f']);
        $this->assertSame(-1.0, $ambient['calibration_offset_c']);
    }

    public function testForRoleDerivesFahrenheitWhenTheReadingOmitsIt(): void
    {
        $sensors = [['address' => self::WATER, 'temp_c' => 40.0]];

        $water = TemperatureLogFields::forRole($sensors, $this->config(), 'water');

        $this->assertEqualsWithDelta(104.0, $water['temp_f'], 0.0001);
    }

    public function testForRoleTreatsAMissingOffsetAsZero(): void
    {
        $config = [self::WATER => ['role' => 'water']];

        $water = TemperatureLogFields::forRole($this->sensors(), $config, 'water');

        $this->assertSame(0.0, $water['calibration_offset_c']);
    }

    public function testForRoleReturnsNullsWhenNoSensorHasTheRole(): void
    {
        $config = [self::WATER => ['role' => 'unassigned', 'calibration_offset' => 0.4]];

        $water = TemperatureLogFields::forRole($this->sensors(), $config, 'water');

        $this->assertSame(['temp_f' => null, 'temp_c' => null, 'calibration_offset_c' => null], $water);
    }

    public function testForRoleReturnsNullsWhenTheAssignedSensorIsAbsentFromTheReading(): void
    {
        $sensors = [['address' => self::AMBIENT, 'temp_c' => 16.9375, 'temp_f' => 62.4875]];

        $water = TemperatureLogFields::forRole($sensors, $this->config(), 'water');

        $this->assertNull($water['temp_f']);
        $this->assertNull($water['calibration_offset_c']);
    }

    public function testTemperatureLogEntryRecordsRawTempsWithTheirOffsets(): void
    {
        $entry = TemperatureLogFields::temperatureLogEntry($this->sensors(), $this->config(), true);

        $this->assertArrayHasKey('timestamp', $entry);
        $this->assertSame(101.075, $entry['water_temp_f']);
        $this->assertSame(38.375, $entry['water_temp_c']);
        $this->assertSame(0.4, $entry['water_calibration_offset_c']);
        $this->assertSame(62.4875, $entry['ambient_temp_f']);
        $this->assertSame(16.9375, $entry['ambient_temp_c']);
        $this->assertSame(-1.0, $entry['ambient_calibration_offset_c']);
        $this->assertTrue($entry['heater_on']);
    }
}
