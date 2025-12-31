#include <unity.h>
#include <Arduino.h>
#include <api_client.h>

void setUp(void) {}
void tearDown(void) {}

// ==================== Interval Clamping Tests ====================

void test_clampInterval_returns_value_within_bounds(void) {
    TEST_ASSERT_EQUAL(60, ApiClient::clampInterval(60));
    TEST_ASSERT_EQUAL(300, ApiClient::clampInterval(300));
    TEST_ASSERT_EQUAL(600, ApiClient::clampInterval(600));
}

void test_clampInterval_clamps_to_minimum(void) {
    TEST_ASSERT_EQUAL(MIN_INTERVAL_SEC, ApiClient::clampInterval(1));
    TEST_ASSERT_EQUAL(MIN_INTERVAL_SEC, ApiClient::clampInterval(5));
    TEST_ASSERT_EQUAL(MIN_INTERVAL_SEC, ApiClient::clampInterval(0));
    TEST_ASSERT_EQUAL(MIN_INTERVAL_SEC, ApiClient::clampInterval(-10));
}

void test_clampInterval_clamps_to_maximum(void) {
    TEST_ASSERT_EQUAL(MAX_INTERVAL_SEC, ApiClient::clampInterval(2000));
    TEST_ASSERT_EQUAL(MAX_INTERVAL_SEC, ApiClient::clampInterval(3600));
    TEST_ASSERT_EQUAL(MAX_INTERVAL_SEC, ApiClient::clampInterval(86400));
}

// ==================== BackoffTimer Tests ====================

void test_backoff_starts_at_initial_value(void) {
    BackoffTimer timer;
    TEST_ASSERT_EQUAL(BACKOFF_START_MS, timer.getDelayMs());
}

void test_backoff_doubles_on_failure(void) {
    BackoffTimer timer;
    timer.recordFailure();
    TEST_ASSERT_EQUAL(BACKOFF_START_MS, timer.getDelayMs());

    timer.recordFailure();
    TEST_ASSERT_EQUAL(BACKOFF_START_MS * 2, timer.getDelayMs());

    timer.recordFailure();
    TEST_ASSERT_EQUAL(BACKOFF_START_MS * 4, timer.getDelayMs());
}

void test_backoff_caps_at_maximum(void) {
    BackoffTimer timer;
    // Record many failures to exceed max
    for (int i = 0; i < 20; i++) {
        timer.recordFailure();
    }
    TEST_ASSERT_EQUAL(BACKOFF_MAX_MS, timer.getDelayMs());
}

void test_backoff_resets_on_success(void) {
    BackoffTimer timer;
    timer.recordFailure();
    timer.recordFailure();
    timer.recordFailure();
    // Should be at 4x by now
    TEST_ASSERT_GREATER_THAN(BACKOFF_START_MS, timer.getDelayMs());

    timer.recordSuccess();
    TEST_ASSERT_EQUAL(BACKOFF_START_MS, timer.getDelayMs());
}

void test_shouldReboot_false_initially(void) {
    BackoffTimer timer;
    TEST_ASSERT_FALSE(timer.shouldReboot());
}

void test_shouldReboot_false_after_success(void) {
    BackoffTimer timer;
    timer.recordFailure();
    timer.recordSuccess();
    TEST_ASSERT_FALSE(timer.shouldReboot());
}

void test_getFailureDuration_zero_when_no_failure(void) {
    BackoffTimer timer;
    TEST_ASSERT_EQUAL(0, timer.getFailureDurationMs());
}

void test_getFailureDuration_increases_after_failure(void) {
    BackoffTimer timer;
    timer.recordFailure();
    delay(50);
    TEST_ASSERT_GREATER_THAN(40, timer.getFailureDurationMs());
}

void setup() {
    delay(2000);
    Serial.begin(115200);

    Serial.println();
    Serial.println("================================");
    Serial.println("API Client Unit Tests");
    Serial.println("================================");

    UNITY_BEGIN();

    // Interval clamping tests
    RUN_TEST(test_clampInterval_returns_value_within_bounds);
    RUN_TEST(test_clampInterval_clamps_to_minimum);
    RUN_TEST(test_clampInterval_clamps_to_maximum);

    // BackoffTimer tests
    RUN_TEST(test_backoff_starts_at_initial_value);
    RUN_TEST(test_backoff_doubles_on_failure);
    RUN_TEST(test_backoff_caps_at_maximum);
    RUN_TEST(test_backoff_resets_on_success);
    RUN_TEST(test_shouldReboot_false_initially);
    RUN_TEST(test_shouldReboot_false_after_success);
    RUN_TEST(test_getFailureDuration_zero_when_no_failure);
    RUN_TEST(test_getFailureDuration_increases_after_failure);

    UNITY_END();
}

void loop() {}
