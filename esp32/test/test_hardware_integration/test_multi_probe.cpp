#include <unity.h>
#include <Arduino.h>
#include <OneWire.h>
#include <DallasTemperature.h>

#define ONE_WIRE_BUS 4

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);

void setUp(void) {}
void tearDown(void) {}

void test_detect_multiple_sensors(void) {
    sensors.begin();
    int deviceCount = sensors.getDeviceCount();

    Serial.printf("\n=== Found %d DS18B20 sensor(s) ===\n", deviceCount);

    // Print each sensor's address and temperature
    DeviceAddress addr;
    for (int i = 0; i < deviceCount; i++) {
        if (sensors.getAddress(addr, i)) {
            Serial.printf("Sensor %d address: ", i);
            for (int j = 0; j < 8; j++) {
                Serial.printf("%02X", addr[j]);
                if (j < 7) Serial.print(":");
            }
            Serial.println();

            sensors.requestTemperaturesByAddress(addr);
            float tempC = sensors.getTempC(addr);
            float tempF = tempC * 9.0 / 5.0 + 32.0;
            Serial.printf("Sensor %d temp: %.2f C (%.2f F)\n\n", i, tempC, tempF);
        }
    }

    TEST_ASSERT_GREATER_OR_EQUAL(1, deviceCount);
}

void setup() {
    delay(2000);
    Serial.begin(115200);

    Serial.println();
    Serial.println("================================");
    Serial.println("Multi-Probe Detection Test");
    Serial.println("================================");

    UNITY_BEGIN();
    RUN_TEST(test_detect_multiple_sensors);
    UNITY_END();
}

void loop() {}
