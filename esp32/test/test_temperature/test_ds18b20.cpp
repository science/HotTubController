#include <unity.h>
#include <Arduino.h>
#include <OneWire.h>
#include <DallasTemperature.h>

#define ONE_WIRE_BUS 4

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

void setUp(void) {
    sensors.begin();
}

void tearDown(void) {
}

void test_sensor_detected_on_bus(void) {
    int deviceCount = sensors.getDeviceCount();
    TEST_ASSERT_GREATER_THAN(0, deviceCount);
}

void test_temperature_reading_is_valid(void) {
    sensors.requestTemperatures();
    float tempC = sensors.getTempCByIndex(0);

    // DS18B20 returns -127 on read error, 85 on power-on reset
    TEST_ASSERT_NOT_EQUAL(-127.0, tempC);
    TEST_ASSERT_NOT_EQUAL(85.0, tempC);

    // Valid range for DS18B20: -55°C to +125°C
    // Room temp should be roughly 10-35°C
    TEST_ASSERT_GREATER_THAN(-55.0, tempC);
    TEST_ASSERT_LESS_THAN(125.0, tempC);
}

void test_temperature_in_reasonable_range(void) {
    sensors.requestTemperatures();
    float tempC = sensors.getTempCByIndex(0);

    // Sanity check: probe at room temp should be 10-40°C
    TEST_ASSERT_GREATER_THAN(10.0, tempC);
    TEST_ASSERT_LESS_THAN(40.0, tempC);
}

void setup() {
    delay(2000);
    Serial.begin(115200);

    Serial.println();
    Serial.println("================================");
    Serial.println("DS18B20 Temperature Sensor Tests");
    Serial.println("================================");

    UNITY_BEGIN();

    RUN_TEST(test_sensor_detected_on_bus);
    RUN_TEST(test_temperature_reading_is_valid);
    RUN_TEST(test_temperature_in_reasonable_range);

    UNITY_END();
}

void loop() {
}
