# Plan: DRY Cron Scheduling Refactor

## Problem Statement

The codebase has multiple places that schedule cron jobs, each implementing timezone handling and cron expression generation independently:

| Service | Timezone Handling | Cron Expression | Result |
|---------|------------------|-----------------|--------|
| `SchedulerService` | `TimeConverter::getSystemTimezone()` ✓ | `dateToCronExpression()` ✓ | Correct |
| `TargetTemperatureService` | `date_default_timezone_get()` ✗ | Inline sprintf ✗ | **5-hour offset bug** |
| `MaintenanceCronService` | Hardcoded schedule | N/A | OK (static) |

This duplication has caused repeated production failures where cron jobs fire at the wrong time due to timezone mismatches between PHP's configured timezone and the system timezone where cron actually runs.

## Goals

1. **Single source of truth** for cron scheduling - one method to convert timestamps to cron entries
2. **Impossible to misuse** - the API should make it hard to accidentally use the wrong timezone
3. **Testable** - unit tests for the new service, pre-production simulation tests for integration
4. **Backward compatible** - existing `SchedulerService` and `TargetTemperatureService` continue to work

## Proposed Architecture

### New Service: `CronSchedulingService`

A single service that handles all aspects of scheduling a cron job:

```php
interface CronSchedulingServiceInterface
{
    /**
     * Schedule a one-time cron job.
     *
     * @param int $unixTimestamp When to run (UTC timestamp)
     * @param string $command The command to execute
     * @param string $comment Cron comment for identification (e.g., "HOTTUB:job-123:ON:ONCE")
     * @return string The cron expression that was scheduled (for logging/verification)
     */
    public function scheduleAt(int $unixTimestamp, string $command, string $comment): string;

    /**
     * Schedule a recurring daily cron job.
     *
     * @param string $timeWithOffset Time in "HH:MM+/-HH:MM" format (e.g., "06:30-08:00" for 6:30 AM Pacific)
     * @param string $command The command to execute
     * @param string $comment Cron comment for identification
     * @return string The cron expression that was scheduled
     */
    public function scheduleDaily(string $timeWithOffset, string $command, string $comment): string;

    /**
     * Get the cron expression for a timestamp WITHOUT scheduling.
     * Useful for creating health checks (which need UTC cron expressions).
     *
     * @param int $unixTimestamp Unix timestamp
     * @param bool $useUtc If true, return UTC expression; if false, return server timezone expression
     * @return string Cron expression (minute hour day month *)
     */
    public function getCronExpression(int $unixTimestamp, bool $useUtc = false): string;
}
```

### Why This Design?

1. **Unix timestamps as input** - No ambiguity about timezone. The timestamp is absolute.
2. **Timezone conversion is internal** - Callers don't need to know about `TimeConverter`
3. **Cron expression generation is internal** - Callers can't make formatting mistakes
4. **The `CrontabAdapter` remains thin** - It just manages raw entries; this service handles the intelligence

### Class Structure

```
CronSchedulingService
├── Uses: TimeConverter (for getSystemTimezone, toServerTimezone)
├── Uses: CrontabAdapterInterface (for addEntry)
├── Methods:
│   ├── scheduleAt(timestamp, command, comment) → adds entry to crontab
│   ├── scheduleDaily(timeWithOffset, command, comment) → adds recurring entry
│   ├── getCronExpression(timestamp, useUtc) → returns expression without scheduling
│   └── (private) formatCronExpression(DateTime) → minute hour day month *
```

### Integration with Existing Services

**SchedulerService** - Refactor to use `CronSchedulingService`:
```php
// Before (correct but duplicated logic):
$serverDateTime = $this->timeConverter->toServerTimezone($scheduledTime);
$cronExpression = $this->dateToCronExpression($serverDateTime);
$this->crontabAdapter->addEntry("$cronExpression $command # $comment");

// After (delegates to CronSchedulingService):
$timestamp = (new DateTime($scheduledTime))->getTimestamp();
$this->cronSchedulingService->scheduleAt($timestamp, $command, $comment);
```

**TargetTemperatureService** - Refactor to use `CronSchedulingService`:
```php
// Before (buggy):
$dateTime = new DateTime('@' . $checkTime);
$dateTime->setTimezone(new DateTimeZone(date_default_timezone_get())); // WRONG!
$cronExpression = sprintf('%d %d %d %d *', ...);
$this->crontabAdapter->addEntry("$cronExpression $command # $comment");

// After (correct):
$this->cronSchedulingService->scheduleAt($checkTime, $command, $comment);
```

## TDD Implementation Plan

### Phase 1: Unit Tests for CronSchedulingService (RED)

Write failing tests FIRST that define the expected behavior:

```php
class CronSchedulingServiceTest extends TestCase
{
    // Test 1: scheduleAt converts timestamp correctly to server timezone
    public function test_scheduleAt_usesSystemTimezoneNotPhpTimezone(): void
    {
        // Given: System timezone is America/New_York (EST, UTC-5)
        //        PHP timezone is UTC
        //        Timestamp represents 2026-01-25 14:30:00 UTC

        // When: scheduleAt() is called

        // Then: Cron expression should be "30 9 25 1 *" (9:30 AM EST)
        //       NOT "30 14 25 1 *" (2:30 PM - would be wrong)
    }

    // Test 2: scheduleAt ensures cron is in future minute with safety margin
    public function test_scheduleAt_rejectsPastTimes(): void
    {
        // Given: Timestamp is in the past
        // When: scheduleAt() is called
        // Then: Should throw InvalidArgumentException
    }

    // Test 3: scheduleAt adds entry to crontab
    public function test_scheduleAt_addsCrontabEntry(): void
    {
        // Given: Valid future timestamp
        // When: scheduleAt() is called
        // Then: CrontabAdapter::addEntry() should be called with correct format
    }

    // Test 4: getCronExpression returns correct format
    public function test_getCronExpression_formatsCorrectlyWithoutLeadingZeros(): void
    {
        // Given: Timestamp for 2026-03-05 07:08:00
        // When: getCronExpression() is called
        // Then: Should return "8 7 5 3 *" (not "08 07 05 03 *")
    }

    // Test 5: getCronExpression with useUtc=true returns UTC expression
    public function test_getCronExpression_withUtcFlagReturnsUtcExpression(): void
    {
        // Given: Timestamp for 14:30 UTC / 9:30 EST
        // When: getCronExpression(ts, useUtc: true)
        // Then: Should return "30 14 ..." (UTC)
        // When: getCronExpression(ts, useUtc: false)
        // Then: Should return "30 9 ..." (EST)
    }

    // Test 6: scheduleDaily handles timezone offset correctly
    public function test_scheduleDaily_convertsTimezoneOffset(): void
    {
        // Given: Time "06:30-08:00" (6:30 AM Pacific)
        //        System timezone is America/New_York (EST)
        // When: scheduleDaily() is called
        // Then: Cron should be "30 9 * * *" (9:30 AM EST = 6:30 AM PST)
    }
}
```

### Phase 2: Implement CronSchedulingService (GREEN)

Write minimal code to pass all unit tests.

### Phase 3: Pre-Production Simulation Tests (RED then GREEN)

These tests use the real `CrontabAdapter` and `CronSimulator` to verify end-to-end behavior:

```php
class CronSchedulingE2ETest extends TestCase
{
    // Test 1: Scheduled cron fires at correct time
    public function e2e_scheduledCronFiresAtCorrectSystemTime(): void
    {
        // Given: CronSchedulingService with real CrontabAdapter
        //        Known system timezone (from /etc/timezone or /etc/localtime)

        // When: scheduleAt() is called for 2 minutes from now

        // Then: CronSimulator can parse the entry
        //       The parsed fire time matches expected time in system timezone
        //       (Accounts for minute-boundary rounding)
    }

    // Test 2: Cron expression matches SchedulerService output
    public function e2e_cronExpressionMatchesLegacySchedulerService(): void
    {
        // Given: Same timestamp
        // When: Both services generate cron expressions
        // Then: Expressions should be identical
        // This ensures backward compatibility during migration
    }

    // Test 3: Heat-target scenario with correct timezone
    public function e2e_heatTargetCronFiresAtCorrectTime(): void
    {
        // Given: TargetTemperatureService using new CronSchedulingService
        //        ESP32 reports temperature, needs re-check in 60 seconds

        // When: scheduleNextCheck() is called

        // Then: Cron entry fire time is within expected window
        //       (next minute boundary, 5+ seconds safety margin)
    }
}
```

### Phase 4: Refactor TargetTemperatureService (GREEN)

With tests passing, refactor `TargetTemperatureService` to use `CronSchedulingService`:

1. Add `CronSchedulingService` as constructor dependency
2. Replace `scheduleNextCheck()` internals to delegate to new service
3. Remove duplicated timezone and cron expression logic
4. Verify pre-production tests still pass

### Phase 5: Refactor SchedulerService (GREEN)

Refactor `SchedulerService` to use `CronSchedulingService`:

1. Add `CronSchedulingService` as constructor dependency
2. Keep `getCronExpression()` usage for health check creation (needs UTC)
3. Replace `dateToCronExpression()` calls with delegation
4. Remove now-redundant private methods
5. Verify all existing tests still pass

### Phase 6: Cleanup

1. Remove dead code (private cron methods in refactored services)
2. Update CLAUDE.md with new architecture
3. Add inline documentation explaining timezone handling

## File Changes Summary

### New Files
- `src/Contracts/CronSchedulingServiceInterface.php` - Interface
- `src/Services/CronSchedulingService.php` - Implementation
- `tests/Services/CronSchedulingServiceTest.php` - Unit tests
- `tests/PreProduction/CronSchedulingE2ETest.php` - Pre-production tests

### Modified Files
- `src/Services/TargetTemperatureService.php` - Use new service
- `src/Services/SchedulerService.php` - Use new service
- `public/index.php` - Wire up new service in DI
- `tests/PreProduction/HeatToTargetRealPathsE2ETest.php` - Update existing tests

## Risk Mitigation

1. **Backward compatibility** - Existing tests for `SchedulerService` must continue passing
2. **Incremental migration** - `TargetTemperatureService` first (it's broken), then `SchedulerService`
3. **Feature flag option** - Could add env var to switch between old/new implementation during testing
4. **Production verification** - After deploy, manually verify cron entries are scheduled correctly

## Test Execution Order

```bash
# Phase 1: Write failing unit tests
composer test -- --filter=CronSchedulingServiceTest
# Expected: Tests fail (class doesn't exist)

# Phase 2: Implement service
composer test -- --filter=CronSchedulingServiceTest
# Expected: Tests pass

# Phase 3: Write and run pre-production tests
composer test -- --filter=CronSchedulingE2ETest
# Expected: Tests pass

# Phase 4: Refactor TargetTemperatureService
composer test -- --filter=HeatToTargetRealPathsE2ETest
# Expected: e2e_heatTargetCronUsesCorrectTimezone NOW PASSES

# Phase 5: Refactor SchedulerService
composer test
# Expected: All tests pass

# Final verification
composer test:all
# Expected: All tests pass including live API tests
```

## Questions for Review

1. Should `CronSchedulingService` also handle job file creation, or keep that separate?
2. Should we add health check integration to `TargetTemperatureService` as part of this refactor, or defer?
3. Is the interface design clear enough, or should we add more helper methods?
