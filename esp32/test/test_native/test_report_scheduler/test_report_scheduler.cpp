#include <unity.h>
#include <report_scheduler.h>
#include <cstdio>

/**
 * Mock time provider for deterministic testing.
 * Allows tests to control time precisely.
 */
class MockTimeProvider : public TimeProvider {
public:
    MockTimeProvider(uint32_t initialTime = 1704067200, bool synced = true)
        : currentTime(initialTime), timeSynced(synced) {}

    uint32_t getCurrentTime() const override {
        return currentTime;
    }

    int getSecondOfMinute() const override {
        return static_cast<int>(currentTime % 60);
    }

    bool isTimeSynced() const override {
        return timeSynced;
    }

    // Test helpers
    void setTime(uint32_t time) { currentTime = time; }
    void advanceTime(int seconds) { currentTime += seconds; }
    void setSecond(int second) {
        // Adjust time so getSecondOfMinute() returns the desired second
        // Always move forward to the next occurrence of that second
        int currentSec = static_cast<int>(currentTime % 60);
        if (second <= currentSec) {
            // Move to next minute's occurrence of that second
            currentTime = ((currentTime / 60) + 1) * 60 + second;
        } else {
            // Move to this minute's occurrence of that second
            currentTime = (currentTime / 60) * 60 + second;
        }
    }
    void setSecondDirect(int second) {
        // Force set second without time progression (use carefully)
        currentTime = (currentTime / 60) * 60 + second;
    }
    void setSynced(bool s) { timeSynced = s; }

private:
    uint32_t currentTime;
    bool timeSynced;
};

// Test fixtures
static MockTimeProvider* mockTime = nullptr;
static ReportScheduler* scheduler = nullptr;

void setUp(void) {
    // Default: time synced, starting at second 0
    mockTime = new MockTimeProvider(1704067200, true); // 2024-01-01 00:00:00 UTC (sec=0)
    scheduler = new ReportScheduler(mockTime, 300, 55);
}

void tearDown(void) {
    delete scheduler;
    delete mockTime;
    scheduler = nullptr;
    mockTime = nullptr;
}

// ==================== State String Tests ====================

void test_stateToString_returns_correct_names(void) {
    TEST_ASSERT_EQUAL_STRING("BOOT_SEND", ReportScheduler::stateToString(STATE_BOOT_SEND));
    TEST_ASSERT_EQUAL_STRING("INTERVAL_WAIT", ReportScheduler::stateToString(STATE_INTERVAL_WAIT));
    TEST_ASSERT_EQUAL_STRING("ALIGN_WAIT", ReportScheduler::stateToString(STATE_ALIGN_WAIT));
    TEST_ASSERT_EQUAL_STRING("READY_TO_SEND", ReportScheduler::stateToString(STATE_READY_TO_SEND));
}

// ==================== Interval Clamping Tests ====================

void test_clampInterval_returns_value_within_bounds(void) {
    TEST_ASSERT_EQUAL(60, ReportScheduler::clampInterval(60));
    TEST_ASSERT_EQUAL(300, ReportScheduler::clampInterval(300));
    TEST_ASSERT_EQUAL(600, ReportScheduler::clampInterval(600));
}

void test_clampInterval_clamps_to_minimum(void) {
    TEST_ASSERT_EQUAL(10, ReportScheduler::clampInterval(1));
    TEST_ASSERT_EQUAL(10, ReportScheduler::clampInterval(5));
    TEST_ASSERT_EQUAL(10, ReportScheduler::clampInterval(0));
    TEST_ASSERT_EQUAL(10, ReportScheduler::clampInterval(-10));
}

void test_clampInterval_clamps_to_maximum(void) {
    TEST_ASSERT_EQUAL(1800, ReportScheduler::clampInterval(2000));
    TEST_ASSERT_EQUAL(1800, ReportScheduler::clampInterval(3600));
}

// ==================== Boot Behavior Tests ====================

void test_state_is_BOOT_SEND_initially(void) {
    TEST_ASSERT_EQUAL(STATE_BOOT_SEND, scheduler->getState());
}

void test_shouldSend_returns_true_on_boot(void) {
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_shouldSend_returns_true_on_boot_regardless_of_time(void) {
    // Even at weird times, boot should send
    mockTime->setSecond(30);
    TEST_ASSERT_TRUE(scheduler->shouldSend());

    // Recreate at different second
    delete scheduler;
    mockTime->setSecond(55);
    scheduler = new ReportScheduler(mockTime, 300, 55);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_recordSend_transitions_from_BOOT_SEND_to_INTERVAL_WAIT(void) {
    TEST_ASSERT_EQUAL(STATE_BOOT_SEND, scheduler->getState());
    scheduler->recordSend();
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
}

// ==================== Interval Wait Tests ====================

void test_shouldSend_returns_false_during_interval_wait(void) {
    scheduler->recordSend(); // Move to INTERVAL_WAIT
    TEST_ASSERT_FALSE(scheduler->shouldSend());
}

void test_shouldSend_returns_false_before_interval_elapses(void) {
    scheduler->recordSend();
    mockTime->advanceTime(299); // Just under 5 minutes
    TEST_ASSERT_FALSE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
}

void test_transitions_to_ALIGN_WAIT_when_interval_elapses(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300); // Exactly 5 minutes
    scheduler->shouldSend();    // Trigger state check
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());
}

// ==================== Alignment Tests ====================

void test_shouldSend_returns_false_during_align_wait_before_55(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300); // Move past interval
    mockTime->setSecond(50);    // Not yet at :55
    TEST_ASSERT_FALSE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());
}

void test_shouldSend_returns_true_at_55_seconds(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(55);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_alignment_accepts_seconds_55_through_59(void) {
    // Test each second in the alignment window
    for (int sec = 55; sec <= 59; sec++) {
        delete scheduler;
        mockTime->setTime(1704067200);
        scheduler = new ReportScheduler(mockTime, 300, 55);
        scheduler->recordSend();
        mockTime->advanceTime(300);
        mockTime->setSecond(sec);
        TEST_ASSERT_TRUE_MESSAGE(scheduler->shouldSend(),
            "Should send at alignment window second");
    }
}

void test_does_not_trigger_at_54_seconds(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(54);
    TEST_ASSERT_FALSE(scheduler->shouldSend());
}

void test_does_not_trigger_at_0_seconds(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(0);
    TEST_ASSERT_FALSE(scheduler->shouldSend());
}

// ==================== Short Interval Tests ====================

void test_skips_alignment_when_interval_under_60_seconds(void) {
    // Start at second 10
    mockTime->setTime(1704067210); // second 10
    delete scheduler;
    scheduler = new ReportScheduler(mockTime, 30, 55); // 30 second interval

    scheduler->recordSend();
    mockTime->advanceTime(30); // Now at second 40, interval elapsed

    TEST_ASSERT_TRUE(scheduler->shouldSend());
    // Should go directly to READY_TO_SEND, not ALIGN_WAIT
    TEST_ASSERT_EQUAL(STATE_READY_TO_SEND, scheduler->getState());
}

void test_exactly_60_second_interval_uses_alignment(void) {
    delete scheduler;
    scheduler = new ReportScheduler(mockTime, 60, 55); // Exactly 60 seconds

    scheduler->recordSend();
    mockTime->advanceTime(60);
    mockTime->setSecond(30); // Not at :55

    TEST_ASSERT_FALSE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());
}

// ==================== NTP Not Synced Fallback Tests ====================

void test_skips_alignment_when_ntp_not_synced(void) {
    mockTime->setSynced(false);
    delete scheduler;
    scheduler = new ReportScheduler(mockTime, 300, 55);

    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(30); // Not at :55, but NTP not synced

    // Should skip alignment and send anyway
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

// ==================== Alignment Timeout Safety Tests ====================

void test_align_wait_times_out_after_max_wait(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300); // Move to ALIGN_WAIT
    scheduler->shouldSend();
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());

    // Simulate stuck condition: advance time but never hit :55
    // (This shouldn't happen in real life, but tests robustness)
    mockTime->advanceTime(70); // More than MAX_ALIGN_WAIT_SEC (65)

    // Should timeout and allow send anyway
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

// ==================== Full Cycle Tests ====================

void test_full_cycle_from_boot_to_second_send(void) {
    // Boot: send immediately
    TEST_ASSERT_TRUE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_BOOT_SEND, scheduler->getState());
    scheduler->recordSend();

    // Interval wait
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
    mockTime->advanceTime(300);

    // Alignment wait
    scheduler->shouldSend();
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());

    // Align to :55
    mockTime->setSecond(55);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
    scheduler->recordSend();

    // Back to interval wait for next cycle
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
}

void test_full_cycle_matches_requirement_example(void) {
    // Boot at second 20 (outside alignment window 55-59)
    mockTime->setTime(1704067220); // second 20
    delete scheduler;
    scheduler = new ReportScheduler(mockTime, 300, 55);

    // 1. Send immediately on boot
    TEST_ASSERT_TRUE(scheduler->shouldSend());
    scheduler->recordSend();

    // 2. Wait 300s - now at second 20 again (300 = 5 full minutes)
    mockTime->advanceTime(300);
    TEST_ASSERT_EQUAL(20, mockTime->getSecondOfMinute());

    // shouldSend() should transition to ALIGN_WAIT (not in 55-59 window)
    TEST_ASSERT_FALSE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_ALIGN_WAIT, scheduler->getState());

    // 3. Wait for :55 mark (35 seconds from :20 to :55)
    mockTime->advanceTime(35);
    TEST_ASSERT_EQUAL(55, mockTime->getSecondOfMinute());
    TEST_ASSERT_TRUE(scheduler->shouldSend());
    scheduler->recordSend();

    // 4. Wait 300s, should be at :55 again (5 full minutes)
    mockTime->advanceTime(300);
    TEST_ASSERT_EQUAL(55, mockTime->getSecondOfMinute());
    // Already at :55, so should be ready to send
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_repeated_cycles_continue_working(void) {
    // Run through 5 complete cycles to ensure no state corruption
    for (int cycle = 0; cycle < 5; cycle++) {
        if (cycle == 0) {
            TEST_ASSERT_TRUE(scheduler->shouldSend()); // Boot send
        } else {
            mockTime->advanceTime(300);
            mockTime->setSecond(55);
            TEST_ASSERT_TRUE(scheduler->shouldSend());
        }
        scheduler->recordSend();
        TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
    }
}

// ==================== Interval Update Tests ====================

void test_setInterval_updates_interval(void) {
    scheduler->setInterval(120);
    TEST_ASSERT_EQUAL(120, scheduler->getInterval());
}

void test_setInterval_clamps_values(void) {
    scheduler->setInterval(5); // Below min
    TEST_ASSERT_EQUAL(10, scheduler->getInterval());

    scheduler->setInterval(5000); // Above max
    TEST_ASSERT_EQUAL(1800, scheduler->getInterval());
}

void test_setInterval_affects_next_cycle(void) {
    scheduler->recordSend(); // Move to INTERVAL_WAIT
    scheduler->setInterval(60);

    mockTime->advanceTime(59);
    TEST_ASSERT_FALSE(scheduler->shouldSend()); // Not yet

    mockTime->advanceTime(1); // Now at 60 seconds
    mockTime->setSecond(55);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

// ==================== getSecondsUntilSend Tests ====================

void test_getSecondsUntilSend_returns_0_on_boot(void) {
    TEST_ASSERT_EQUAL(0, scheduler->getSecondsUntilSend());
}

void test_getSecondsUntilSend_returns_remaining_interval(void) {
    scheduler->recordSend();
    mockTime->advanceTime(100);
    // 300 - 100 = 200 seconds remaining in interval
    // Plus some alignment time
    int remaining = scheduler->getSecondsUntilSend();
    TEST_ASSERT_GREATER_OR_EQUAL(200, remaining);
}

void test_getSecondsUntilSend_returns_0_when_ready(void) {
    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(55);
    scheduler->shouldSend(); // Transition to READY_TO_SEND
    TEST_ASSERT_EQUAL(0, scheduler->getSecondsUntilSend());
}

// ==================== setAlignSecond Tests ====================

void test_setAlignSecond_updates_alignment_target(void) {
    scheduler->setAlignSecond(53);
    TEST_ASSERT_EQUAL(53, scheduler->getAlignSecond());
}

void test_setAlignSecond_affects_alignment_window(void) {
    scheduler->setAlignSecond(53);
    scheduler->recordSend();
    mockTime->advanceTime(300);

    // At :52 should NOT trigger (below new window 53-57)
    mockTime->setSecond(52);
    TEST_ASSERT_FALSE(scheduler->shouldSend());

    // At :53 SHOULD trigger (at new target)
    mockTime->setSecond(53);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_setAlignSecond_clamps_negative_to_default(void) {
    scheduler->setAlignSecond(-5);
    // Should use default (53) not crash or use invalid value
    TEST_ASSERT_EQUAL(ReportScheduler::SCHED_DEFAULT_ALIGN_SECOND, scheduler->getAlignSecond());
}

void test_setAlignSecond_clamps_above_59_to_default(void) {
    scheduler->setAlignSecond(60);
    TEST_ASSERT_EQUAL(ReportScheduler::SCHED_DEFAULT_ALIGN_SECOND, scheduler->getAlignSecond());

    scheduler->setAlignSecond(100);
    TEST_ASSERT_EQUAL(ReportScheduler::SCHED_DEFAULT_ALIGN_SECOND, scheduler->getAlignSecond());
}

void test_setAlignSecond_accepts_valid_range_0_to_59(void) {
    // 0 is valid (align at :00)
    scheduler->setAlignSecond(0);
    TEST_ASSERT_EQUAL(0, scheduler->getAlignSecond());

    // 30 is valid (align at :30)
    scheduler->setAlignSecond(30);
    TEST_ASSERT_EQUAL(30, scheduler->getAlignSecond());

    // 59 is valid (align at :59)
    scheduler->setAlignSecond(59);
    TEST_ASSERT_EQUAL(59, scheduler->getAlignSecond());
}

void test_setAlignSecond_with_zero_still_works(void) {
    // Edge case: align at :00
    scheduler->setAlignSecond(0);
    scheduler->recordSend();
    mockTime->advanceTime(300);
    mockTime->setSecond(0);
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

void test_getAlignSecond_returns_current_value(void) {
    // Default from constructor
    TEST_ASSERT_EQUAL(55, scheduler->getAlignSecond());

    scheduler->setAlignSecond(53);
    TEST_ASSERT_EQUAL(53, scheduler->getAlignSecond());
}

void test_constructor_with_invalid_alignSecond_uses_default(void) {
    delete scheduler;
    // Pass invalid alignSecond to constructor
    scheduler = new ReportScheduler(mockTime, 300, -10);
    TEST_ASSERT_EQUAL(ReportScheduler::SCHED_DEFAULT_ALIGN_SECOND, scheduler->getAlignSecond());

    delete scheduler;
    scheduler = new ReportScheduler(mockTime, 300, 100);
    TEST_ASSERT_EQUAL(ReportScheduler::SCHED_DEFAULT_ALIGN_SECOND, scheduler->getAlignSecond());
}

// ==================== Edge Case Tests ====================

void test_handles_zero_interval_gracefully(void) {
    scheduler->setInterval(0); // Should clamp to minimum
    TEST_ASSERT_EQUAL(10, scheduler->getInterval());
}

void test_handles_negative_interval_gracefully(void) {
    scheduler->setInterval(-100); // Should clamp to minimum
    TEST_ASSERT_EQUAL(10, scheduler->getInterval());
}

void test_recordSend_from_READY_TO_SEND_resets_properly(void) {
    scheduler->recordSend(); // BOOT -> INTERVAL_WAIT
    mockTime->advanceTime(300);
    mockTime->setSecond(55);
    scheduler->shouldSend(); // -> READY_TO_SEND

    scheduler->recordSend(); // -> INTERVAL_WAIT
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());
    TEST_ASSERT_FALSE(scheduler->shouldSend());
}

// ==================== Drift Prevention Tests ====================

/**
 * Test that recordSend anchors interval start to alignment second, not actual send time.
 * This prevents drift caused by API latency.
 *
 * Scenario: alignment at :53, interval 60s
 * - Send triggered at :53
 * - API takes 1 second, recordSend called at :54
 * - Next send should still occur at :53, not :54
 */
void test_recordSend_anchors_to_alignment_second_prevents_drift(void) {
    // Create scheduler with alignment at :53, interval 60s
    delete scheduler;
    delete mockTime;

    // Start at second :53 of some minute
    uint32_t baseTime = 1704067200; // 2024-01-01 00:00:00 UTC
    baseTime = (baseTime / 60) * 60 + 53; // Align to :53
    mockTime = new MockTimeProvider(baseTime, true);
    scheduler = new ReportScheduler(mockTime, 60, 53);

    // Boot send happens immediately
    TEST_ASSERT_TRUE(scheduler->shouldSend());
    TEST_ASSERT_EQUAL(STATE_BOOT_SEND, scheduler->getState());

    // Simulate: API call takes 1 second, recordSend called at :54
    mockTime->advanceTime(1); // Now at :54
    scheduler->recordSend();
    TEST_ASSERT_EQUAL(STATE_INTERVAL_WAIT, scheduler->getState());

    // Advance 59 seconds - should now be at :53 of next minute
    mockTime->advanceTime(59); // Total: 60 seconds from :53 anchor
    TEST_ASSERT_EQUAL(53, mockTime->getSecondOfMinute());

    // With drift fix: interval anchored to :53, so 60 seconds elapsed, should be ready
    // Without fix: interval started at :54, only 59 seconds elapsed, not ready
    bool ready = scheduler->shouldSend();
    TEST_ASSERT_TRUE_MESSAGE(ready,
        "Should be ready to send at :53 - interval should anchor to alignment second, not API completion time");
}

/**
 * Test that drift doesn't accumulate over multiple cycles.
 * Each cycle should send at the same alignment second.
 */
void test_no_drift_accumulation_over_multiple_cycles(void) {
    delete scheduler;
    delete mockTime;

    // Start at second :53
    uint32_t baseTime = (1704067200 / 60) * 60 + 53;
    mockTime = new MockTimeProvider(baseTime, true);
    scheduler = new ReportScheduler(mockTime, 60, 53);

    // Boot send
    TEST_ASSERT_TRUE(scheduler->shouldSend());

    // Simulate 5 cycles, each with 1 second of "API latency"
    for (int cycle = 0; cycle < 5; cycle++) {
        // API latency: 1 second passes before recordSend
        mockTime->advanceTime(1);
        scheduler->recordSend();

        // Advance to next :53 (59 seconds from :54 = 60 seconds from :53)
        mockTime->advanceTime(59);

        // Verify we're at :53
        TEST_ASSERT_EQUAL_MESSAGE(53, mockTime->getSecondOfMinute(),
            "Test setup error: should be at :53");

        // Should be ready to send at :53 each cycle
        bool ready = scheduler->shouldSend();
        char msg[100];
        snprintf(msg, sizeof(msg), "Cycle %d: should be ready at :53, not drifted", cycle + 1);
        TEST_ASSERT_TRUE_MESSAGE(ready, msg);
    }
}

/**
 * Test that anchoring only applies when alignment is enabled.
 * Short intervals (< 60s) skip alignment and should use actual time.
 */
void test_short_interval_does_not_anchor_uses_actual_time(void) {
    delete scheduler;
    delete mockTime;

    // Start at second :53
    uint32_t baseTime = (1704067200 / 60) * 60 + 53;
    mockTime = new MockTimeProvider(baseTime, true);
    scheduler = new ReportScheduler(mockTime, 30, 53); // 30 second interval - no alignment

    // Boot send
    TEST_ASSERT_TRUE(scheduler->shouldSend());

    // Simulate 1 second API latency
    mockTime->advanceTime(1); // Now at :54
    scheduler->recordSend();

    // Advance 29 seconds (one short of 30)
    mockTime->advanceTime(29); // Now at :23 of next minute

    // Should NOT be ready - only 29 seconds elapsed from :54
    TEST_ASSERT_FALSE(scheduler->shouldSend());

    // Advance 1 more second (total 30 from :54)
    mockTime->advanceTime(1); // Now at :24

    // Now should be ready (30 seconds elapsed from actual send time)
    TEST_ASSERT_TRUE(scheduler->shouldSend());
}

// ==================== Main ====================

int main(int argc, char **argv) {
    UNITY_BEGIN();

    // State string tests
    RUN_TEST(test_stateToString_returns_correct_names);

    // Interval clamping tests
    RUN_TEST(test_clampInterval_returns_value_within_bounds);
    RUN_TEST(test_clampInterval_clamps_to_minimum);
    RUN_TEST(test_clampInterval_clamps_to_maximum);

    // Boot behavior tests
    RUN_TEST(test_state_is_BOOT_SEND_initially);
    RUN_TEST(test_shouldSend_returns_true_on_boot);
    RUN_TEST(test_shouldSend_returns_true_on_boot_regardless_of_time);
    RUN_TEST(test_recordSend_transitions_from_BOOT_SEND_to_INTERVAL_WAIT);

    // Interval wait tests
    RUN_TEST(test_shouldSend_returns_false_during_interval_wait);
    RUN_TEST(test_shouldSend_returns_false_before_interval_elapses);
    RUN_TEST(test_transitions_to_ALIGN_WAIT_when_interval_elapses);

    // Alignment tests
    RUN_TEST(test_shouldSend_returns_false_during_align_wait_before_55);
    RUN_TEST(test_shouldSend_returns_true_at_55_seconds);
    RUN_TEST(test_alignment_accepts_seconds_55_through_59);
    RUN_TEST(test_does_not_trigger_at_54_seconds);
    RUN_TEST(test_does_not_trigger_at_0_seconds);

    // Short interval tests
    RUN_TEST(test_skips_alignment_when_interval_under_60_seconds);
    RUN_TEST(test_exactly_60_second_interval_uses_alignment);

    // NTP fallback tests
    RUN_TEST(test_skips_alignment_when_ntp_not_synced);

    // Safety timeout tests
    RUN_TEST(test_align_wait_times_out_after_max_wait);

    // Full cycle tests
    RUN_TEST(test_full_cycle_from_boot_to_second_send);
    RUN_TEST(test_full_cycle_matches_requirement_example);
    RUN_TEST(test_repeated_cycles_continue_working);

    // Interval update tests
    RUN_TEST(test_setInterval_updates_interval);
    RUN_TEST(test_setInterval_clamps_values);
    RUN_TEST(test_setInterval_affects_next_cycle);

    // getSecondsUntilSend tests
    RUN_TEST(test_getSecondsUntilSend_returns_0_on_boot);
    RUN_TEST(test_getSecondsUntilSend_returns_remaining_interval);
    RUN_TEST(test_getSecondsUntilSend_returns_0_when_ready);

    // setAlignSecond tests
    RUN_TEST(test_setAlignSecond_updates_alignment_target);
    RUN_TEST(test_setAlignSecond_affects_alignment_window);
    RUN_TEST(test_setAlignSecond_clamps_negative_to_default);
    RUN_TEST(test_setAlignSecond_clamps_above_59_to_default);
    RUN_TEST(test_setAlignSecond_accepts_valid_range_0_to_59);
    RUN_TEST(test_setAlignSecond_with_zero_still_works);
    RUN_TEST(test_getAlignSecond_returns_current_value);
    RUN_TEST(test_constructor_with_invalid_alignSecond_uses_default);

    // Edge case tests
    RUN_TEST(test_handles_zero_interval_gracefully);
    RUN_TEST(test_handles_negative_interval_gracefully);
    RUN_TEST(test_recordSend_from_READY_TO_SEND_resets_properly);

    // Drift prevention tests
    RUN_TEST(test_recordSend_anchors_to_alignment_second_prevents_drift);
    RUN_TEST(test_no_drift_accumulation_over_multiple_cycles);
    RUN_TEST(test_short_interval_does_not_anchor_uses_actual_time);

    return UNITY_END();
}
