<?php

declare(strict_types=1);

namespace HotTub\Tests\Services;

use HotTub\Contracts\CrontabAdapterInterface;
use HotTub\Services\CronSchedulingService;
use HotTub\Services\TimeConverter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CronSchedulingService.
 *
 * These tests define the expected behavior BEFORE implementation (TDD RED phase).
 */
class CronSchedulingServiceTest extends TestCase
{
    private MockObject&CrontabAdapterInterface $mockCrontab;
    private CronSchedulingService $service;

    protected function setUp(): void
    {
        $this->mockCrontab = $this->createMock(CrontabAdapterInterface::class);
        $this->service = new CronSchedulingService($this->mockCrontab);
    }

    // ========== scheduleAt() tests ==========

    /**
     * CRITICAL TEST: Verify scheduleAt uses SYSTEM timezone, not PHP's configured timezone.
     *
     * This is the exact bug that caused the 5-hour offset in production.
     * PHP was configured for UTC, but cron runs in system timezone (EST).
     */
    public function testScheduleAtUsesSystemTimezoneNotPhpTimezone(): void
    {
        // Given: A timestamp representing 14:30 UTC on Jan 25, 2026
        $timestamp = strtotime('2026-01-25 14:30:00 UTC');

        // The system timezone determines what cron expression we generate.
        // If system is America/New_York (EST, UTC-5), then 14:30 UTC = 09:30 EST
        $systemTz = TimeConverter::getSystemTimezone();
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone(new \DateTimeZone($systemTz));
        $expectedMinute = (int) $dt->format('i');
        $expectedHour = (int) $dt->format('G');

        $capturedEntry = null;
        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->callback(function ($entry) use (&$capturedEntry) {
                $capturedEntry = $entry;
                return true;
            }));

        // When: scheduleAt is called
        $this->service->scheduleAt($timestamp, '/path/to/script.sh', 'TEST:comment');

        // Then: The cron expression should use system timezone, not UTC
        // Extract minute and hour from the cron entry
        preg_match('/^(\d+)\s+(\d+)\s+/', $capturedEntry, $matches);
        $actualMinute = (int) $matches[1];
        $actualHour = (int) $matches[2];

        $this->assertEquals($expectedMinute, $actualMinute,
            "Minute should be $expectedMinute (system TZ: $systemTz), got $actualMinute");
        $this->assertEquals($expectedHour, $actualHour,
            "Hour should be $expectedHour (system TZ: $systemTz), got $actualHour. " .
            "If hour is 14, the bug is using UTC instead of system timezone!");
    }

    public function testScheduleAtAddsCrontabEntry(): void
    {
        $timestamp = strtotime('+5 minutes');
        $command = '/path/to/script.sh';
        $comment = 'HOTTUB:job-123:ON:ONCE';

        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->stringContains($comment));

        $this->service->scheduleAt($timestamp, $command, $comment);
    }

    public function testScheduleAtReturnsCronExpression(): void
    {
        $timestamp = strtotime('2026-01-25 14:30:00 UTC');

        $this->mockCrontab->method('addEntry');

        $result = $this->service->scheduleAt($timestamp, '/script.sh', 'TEST');

        // Should return a valid cron expression (5 fields)
        $this->assertMatchesRegularExpression('/^\d+\s+\d+\s+\d+\s+\d+\s+\*$/', $result);
    }

    public function testScheduleAtFormatsWithoutLeadingZeros(): void
    {
        // Given: A time that would have leading zeros (07:08 on March 5)
        $timestamp = strtotime('2026-03-05 07:08:00 ' . TimeConverter::getSystemTimezone());

        $capturedEntry = null;
        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->callback(function ($entry) use (&$capturedEntry) {
                $capturedEntry = $entry;
                return true;
            }));

        $this->service->scheduleAt($timestamp, '/script.sh', 'TEST');

        // Then: Cron expression should NOT have leading zeros
        // "8 7 5 3 *" not "08 07 05 03 *"
        $this->assertMatchesRegularExpression('/^8\s+7\s+5\s+3\s+\*/', $capturedEntry,
            "Cron expression should not have leading zeros. Got: $capturedEntry");
    }

    public function testScheduleAtIncludesCommandAndComment(): void
    {
        $timestamp = strtotime('+5 minutes');
        $command = '/path/to/cron-runner.sh';
        $comment = 'HOTTUB:heat-target-abc123:HEAT-TARGET:ONCE';

        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->callback(function ($entry) use ($command, $comment) {
                return str_contains($entry, $command) && str_contains($entry, $comment);
            }));

        $this->service->scheduleAt($timestamp, $command, $comment);
    }

    // ========== getCronExpression() tests ==========

    public function testGetCronExpressionReturnsServerTimezoneByDefault(): void
    {
        $timestamp = strtotime('2026-01-25 14:30:00 UTC');

        $systemTz = TimeConverter::getSystemTimezone();
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone(new \DateTimeZone($systemTz));
        $expectedMinute = (int) $dt->format('i');
        $expectedHour = (int) $dt->format('G');

        $result = $this->service->getCronExpression($timestamp);

        $this->assertStringStartsWith("$expectedMinute $expectedHour ", $result,
            "Should use system timezone ($systemTz) by default");
    }

    public function testGetCronExpressionWithUtcFlagReturnsUtc(): void
    {
        $timestamp = strtotime('2026-01-25 14:30:00 UTC');

        $result = $this->service->getCronExpression($timestamp, useUtc: true);

        // 14:30 UTC should give "30 14 25 1 *"
        $this->assertStringStartsWith('30 14 ', $result,
            "With useUtc=true, should return UTC time (14:30)");
    }

    public function testGetCronExpressionDoesNotAddToCrontab(): void
    {
        $timestamp = strtotime('+5 minutes');

        $this->mockCrontab->expects($this->never())
            ->method('addEntry');

        $this->service->getCronExpression($timestamp);
    }

    // ========== scheduleDaily() tests ==========

    public function testScheduleDailyConvertsTimezoneOffset(): void
    {
        // Given: Time "06:30-08:00" (6:30 AM Pacific, UTC-8)
        // This represents 14:30 UTC
        $timeWithOffset = '06:30-08:00';

        $capturedEntry = null;
        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->callback(function ($entry) use (&$capturedEntry) {
                $capturedEntry = $entry;
                return true;
            }));

        $this->service->scheduleDaily($timeWithOffset, '/script.sh', 'TEST:DAILY');

        // The cron should be in system timezone
        // If system is EST (UTC-5), 14:30 UTC = 09:30 EST, so cron should be "30 9 * * *"
        // If system is PST (UTC-8), 14:30 UTC = 06:30 PST, so cron should be "30 6 * * *"
        $systemTz = TimeConverter::getSystemTimezone();
        $dt = new \DateTime('2026-01-25 06:30:00', new \DateTimeZone('America/Los_Angeles'));
        $dt->setTimezone(new \DateTimeZone($systemTz));
        $expectedMinute = (int) $dt->format('i');
        $expectedHour = (int) $dt->format('G');

        $this->assertMatchesRegularExpression("/^$expectedMinute\s+$expectedHour\s+\*\s+\*\s+\*/", $capturedEntry,
            "Daily cron should be at $expectedHour:$expectedMinute in system TZ ($systemTz). Got: $capturedEntry");
    }

    public function testScheduleDailyReturnsRecurringCronExpression(): void
    {
        $this->mockCrontab->method('addEntry');

        $result = $this->service->scheduleDaily('06:30-08:00', '/script.sh', 'TEST');

        // Should be "minute hour * * *" (daily pattern)
        $this->assertMatchesRegularExpression('/^\d+\s+\d+\s+\*\s+\*\s+\*$/', $result);
    }

    // ========== Edge cases ==========

    public function testScheduleAtHandlesDayBoundary(): void
    {
        // Given: 23:30 UTC, which might be the next day in some timezones
        $timestamp = strtotime('2026-01-25 23:30:00 UTC');

        $systemTz = TimeConverter::getSystemTimezone();
        $dt = new \DateTime('@' . $timestamp);
        $dt->setTimezone(new \DateTimeZone($systemTz));
        $expectedDay = (int) $dt->format('j');
        $expectedMonth = (int) $dt->format('n');

        $capturedEntry = null;
        $this->mockCrontab->expects($this->once())
            ->method('addEntry')
            ->with($this->callback(function ($entry) use (&$capturedEntry) {
                $capturedEntry = $entry;
                return true;
            }));

        $this->service->scheduleAt($timestamp, '/script.sh', 'TEST');

        // Extract day and month from cron entry
        preg_match('/^\d+\s+\d+\s+(\d+)\s+(\d+)\s+/', $capturedEntry, $matches);
        $actualDay = (int) $matches[1];
        $actualMonth = (int) $matches[2];

        $this->assertEquals($expectedDay, $actualDay, "Day should handle timezone boundary");
        $this->assertEquals($expectedMonth, $actualMonth, "Month should handle timezone boundary");
    }
}
