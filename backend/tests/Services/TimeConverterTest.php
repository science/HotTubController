<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Services\TimeConverter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the TimeConverter service.
 *
 * This service centralizes all timezone conversion logic so that both
 * one-off and recurring jobs use the same code path.
 */
class TimeConverterTest extends TestCase
{
    private string $originalTimezone;

    protected function setUp(): void
    {
        $this->originalTimezone = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTimezone);
    }

    // ========== System Timezone Detection Tests ==========

    /**
     * BUG TEST: Verify we use SYSTEM timezone, not PHP's configured timezone.
     *
     * Cron runs in the system timezone (e.g., America/Los_Angeles), but PHP might
     * have a different default (often UTC). If we use PHP's timezone, cron will
     * run at the wrong time.
     *
     * Example of the bug:
     * - System timezone: America/Los_Angeles (Pacific)
     * - PHP timezone: UTC
     * - Client sends: 06:00-08:00 (6 AM Pacific)
     * - Bug: We convert to 14:00 UTC and schedule cron for 14:00
     * - Cron runs at 14:00 Pacific (2 PM) instead of 06:00 Pacific (6 AM)
     */
    public function testToServerTimezoneUsesSystemTimezoneNotPhpTimezone(): void
    {
        // Get the actual system timezone
        $systemTz = TimeConverter::getSystemTimezone();

        // Simulate a mismatch: set PHP to UTC while system is something else
        date_default_timezone_set('UTC');

        $converter = new TimeConverter();

        // Client in Pacific sends 6:00 AM their time
        $clientTime = '2030-12-11T06:00:00-08:00';

        $serverTime = $converter->toServerTimezone($clientTime);

        // The result should be in SYSTEM timezone, not PHP's timezone (UTC)
        // If system is America/Los_Angeles (Pacific, UTC-8), then 6 AM Pacific stays 6 AM
        // If system is America/New_York (Eastern, UTC-5), then 6 AM Pacific = 9 AM Eastern

        // Convert the same time to system timezone manually for comparison
        $expected = new \DateTime($clientTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('H:i'),
            $serverTime->format('H:i'),
            "toServerTimezone should use system timezone ($systemTz), not PHP timezone (UTC)"
        );
    }

    /**
     * Test that getSystemTimezone returns a valid IANA timezone.
     */
    public function testGetSystemTimezoneReturnsValidTimezone(): void
    {
        $systemTz = TimeConverter::getSystemTimezone();

        // Should be a valid timezone identifier
        $this->assertNotEmpty($systemTz);

        // Should be parseable by PHP
        $tz = new \DateTimeZone($systemTz);
        $this->assertInstanceOf(\DateTimeZone::class, $tz);
    }

    // ========== toServerTimezone Tests ==========

    public function testToServerTimezoneConvertsFromClientTimezone(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // Client in Pacific (UTC-8) sends 6:30 AM their time
        $clientTime = '2030-12-11T06:30:00-08:00';

        $serverTime = $converter->toServerTimezone($clientTime);

        // Verify result is in system timezone by comparing to manual conversion
        $expected = new \DateTime($clientTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('G'),
            $serverTime->format('G'),
            "Hour should match system timezone ($systemTz) conversion"
        );
        $this->assertEquals(30, (int) $serverTime->format('i'), 'Minute should be 30');
        $this->assertEquals(
            $expected->format('j'),
            $serverTime->format('j'),
            'Day should match system timezone conversion'
        );
        $this->assertEquals(12, (int) $serverTime->format('n'), 'Month should be 12');
    }

    public function testToServerTimezoneHandlesDayBoundary(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // Client in Pacific schedules 11:30 PM Dec 10
        // This may cross day boundary depending on system timezone
        $clientTime = '2030-12-10T23:30:00-08:00';

        $serverTime = $converter->toServerTimezone($clientTime);

        // Verify result matches manual conversion to system timezone
        $expected = new \DateTime($clientTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('G'),
            $serverTime->format('G'),
            "Hour should match system timezone ($systemTz) conversion"
        );
        $this->assertEquals(30, (int) $serverTime->format('i'), 'Minute should be 30');
        $this->assertEquals(
            $expected->format('j'),
            $serverTime->format('j'),
            'Day should match system timezone conversion'
        );
    }

    public function testToServerTimezoneHandlesUtcInput(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // Input already in UTC
        $utcTime = '2030-12-11T14:30:00+00:00';

        $serverTime = $converter->toServerTimezone($utcTime);

        // Verify result matches manual conversion to system timezone
        $expected = new \DateTime($utcTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('G'),
            $serverTime->format('G'),
            "Hour should match system timezone ($systemTz) conversion"
        );
        $this->assertEquals(30, (int) $serverTime->format('i'));
    }

    public function testToServerTimezoneHandlesZSuffix(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // Input with Z suffix (UTC)
        $utcTime = '2030-12-11T14:30:00Z';

        $serverTime = $converter->toServerTimezone($utcTime);

        // Verify result matches manual conversion to system timezone
        $expected = new \DateTime($utcTime);
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('G'),
            $serverTime->format('G'),
            "Hour should match system timezone ($systemTz) conversion"
        );
        $this->assertEquals(30, (int) $serverTime->format('i'));
    }

    // ========== toUtc Tests ==========

    public function testToUtcConvertsFromClientTimezone(): void
    {
        $converter = new TimeConverter();

        // Client in Pacific (UTC-8) sends 6:30 AM their time
        $clientTime = '2030-12-11T06:30:00-08:00';

        $utcTime = $converter->toUtc($clientTime);

        // 6:30 AM PST = 14:30 UTC
        $this->assertEquals('UTC', $utcTime->getTimezone()->getName());
        $this->assertEquals(14, (int) $utcTime->format('G'));
        $this->assertEquals(30, (int) $utcTime->format('i'));
    }

    public function testToUtcFormatsAsAtom(): void
    {
        $converter = new TimeConverter();

        $clientTime = '2030-12-11T06:30:00-08:00';

        $utcTime = $converter->toUtc($clientTime);
        $formatted = $utcTime->format(\DateTime::ATOM);

        // Should end with +00:00 (UTC)
        $this->assertStringEndsWith('+00:00', $formatted);
        $this->assertStringContainsString('2030-12-11T14:30:00', $formatted);
    }

    // ========== parseTimeWithOffset Tests (for recurring jobs) ==========

    public function testParseTimeWithOffsetParsesValidFormat(): void
    {
        date_default_timezone_set('America/New_York');

        $converter = new TimeConverter();

        // Recurring job format: HH:MM with timezone offset
        $timeWithOffset = '06:30-08:00';

        $result = $converter->parseTimeWithOffset($timeWithOffset);

        // Should parse as a DateTime with the correct time and offset
        $this->assertEquals(6, (int) $result->format('G'));
        $this->assertEquals(30, (int) $result->format('i'));
    }

    public function testParseTimeWithOffsetConvertsToServerTimezone(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // 6:30 AM Pacific should convert to system timezone
        $timeWithOffset = '06:30-08:00';

        $serverTime = $converter->parseTimeWithOffset($timeWithOffset, toServerTz: true);

        // Verify result matches manual conversion to system timezone
        $expected = new \DateTime('2030-01-01T06:30:00-08:00');
        $expected->setTimezone(new \DateTimeZone($systemTz));

        $this->assertEquals(
            $expected->format('G'),
            $serverTime->format('G'),
            "Hour should match system timezone ($systemTz) conversion"
        );
        $this->assertEquals(30, (int) $serverTime->format('i'));
    }

    public function testParseTimeWithOffsetConvertsToUtc(): void
    {
        $converter = new TimeConverter();

        // 6:30 AM Pacific (UTC-8) = 14:30 UTC
        $timeWithOffset = '06:30-08:00';

        $utcTime = $converter->parseTimeWithOffset($timeWithOffset, toUtc: true);

        $this->assertEquals('UTC', $utcTime->getTimezone()->getName());
        $this->assertEquals(14, (int) $utcTime->format('G'));
        $this->assertEquals(30, (int) $utcTime->format('i'));
    }

    public function testParseTimeWithOffsetHandlesPositiveOffset(): void
    {
        $converter = new TimeConverter();

        // 6:30 AM in UTC+5:30 (India) = 01:00 UTC
        $timeWithOffset = '06:30+05:30';

        $utcTime = $converter->parseTimeWithOffset($timeWithOffset, toUtc: true);

        $this->assertEquals(1, (int) $utcTime->format('G'));
        $this->assertEquals(0, (int) $utcTime->format('i'));
    }

    public function testParseTimeWithOffsetHandlesNoonAndMidnight(): void
    {
        $converter = new TimeConverter();
        $systemTz = TimeConverter::getSystemTimezone();

        // Midnight Pacific converts to system timezone
        $midnight = '00:00-08:00';
        $serverTime = $converter->parseTimeWithOffset($midnight, toServerTz: true);

        $expectedMidnight = new \DateTime('2030-01-01T00:00:00-08:00');
        $expectedMidnight->setTimezone(new \DateTimeZone($systemTz));
        $this->assertEquals(
            $expectedMidnight->format('G'),
            $serverTime->format('G'),
            "Midnight should convert correctly to system timezone ($systemTz)"
        );

        // Noon Pacific converts to system timezone
        $noon = '12:00-08:00';
        $serverTime = $converter->parseTimeWithOffset($noon, toServerTz: true);

        $expectedNoon = new \DateTime('2030-01-01T12:00:00-08:00');
        $expectedNoon->setTimezone(new \DateTimeZone($systemTz));
        $this->assertEquals(
            $expectedNoon->format('G'),
            $serverTime->format('G'),
            "Noon should convert correctly to system timezone ($systemTz)"
        );
    }

    // ========== formatTimeUtc Tests (for recurring job storage) ==========

    public function testFormatTimeUtcReturnsTimeOnlyWithUtcIndicator(): void
    {
        $converter = new TimeConverter();

        // 6:30 AM Pacific = 14:30 UTC
        $timeWithOffset = '06:30-08:00';

        $formatted = $converter->formatTimeUtc($timeWithOffset);

        // Should return time in HH:MM:SS+00:00 format
        $this->assertEquals('14:30:00+00:00', $formatted);
    }

    public function testFormatTimeUtcHandlesDayWrap(): void
    {
        $converter = new TimeConverter();

        // 11:30 PM Pacific (UTC-8) = 07:30 UTC next day
        // For recurring jobs, we only care about the time portion
        $timeWithOffset = '23:30-08:00';

        $formatted = $converter->formatTimeUtc($timeWithOffset);

        $this->assertEquals('07:30:00+00:00', $formatted);
    }
}
