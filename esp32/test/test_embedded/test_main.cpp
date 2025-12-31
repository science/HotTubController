#include <unity.h>
#include <Arduino.h>

void setUp(void) {
    // Runs before each test
}

void tearDown(void) {
    // Runs after each test
}

void test_sanity_check(void) {
    TEST_ASSERT_EQUAL(2, 1 + 1);
}

void test_esp32_millis_advances(void) {
    unsigned long start = millis();
    delay(10);
    unsigned long end = millis();
    TEST_ASSERT_TRUE(end > start);
}

void setup() {
    delay(2000);  // Allow board to settle after reset
    UNITY_BEGIN();

    RUN_TEST(test_sanity_check);
    RUN_TEST(test_esp32_millis_advances);

    UNITY_END();
}

void loop() {
    // Nothing - tests run once in setup()
}
