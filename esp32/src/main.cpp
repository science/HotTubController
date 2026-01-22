#include <Arduino.h>
#include <WiFi.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <api_client.h>
#include <telnet_debugger.h>

// Hardware pins
#define ONE_WIRE_BUS 4
#define LED_PIN 2

// WIFI_SSID, WIFI_PASSWORD, ESP32_API_KEY, API_ENDPOINT injected via build flags

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
ApiClient* apiClient = nullptr;
BackoffTimer backoffTimer;
TelnetDebugger* debugger = nullptr;

String deviceId;
unsigned long lastReportTime = 0;
int currentIntervalMs = DEFAULT_INTERVAL_SEC * 1000;

void connectWiFi() {
    Serial.printf("Connecting to WiFi: %s\n", WIFI_SSID);

    // Set max TX power for better range (default is ~13dBm, max is 20.5dBm)
    WiFi.setTxPower(WIFI_POWER_19_5dBm);
    Serial.println("WiFi TX power set to 19.5 dBm (max)");

    WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

    int attempts = 0;
    while (WiFi.status() != WL_CONNECTED && attempts < 30) {
        delay(500);
        Serial.print(".");
        attempts++;
    }

    if (WiFi.status() == WL_CONNECTED) {
        Serial.println();
        Serial.printf("Connected! IP: %s\n", WiFi.localIP().toString().c_str());
    } else {
        Serial.println();
        Serial.println("WiFi connection FAILED");
    }
}

void setup() {
    Serial.begin(115200);
    pinMode(LED_PIN, OUTPUT);

    Serial.println();
    Serial.println("================================");
    Serial.println("ESP32 Hot Tub Controller");
    Serial.println("================================");

    // Initialize temperature sensor
    sensors.begin();
    int deviceCount = sensors.getDeviceCount();
    Serial.printf("Found %d DS18B20 sensor(s)\n", deviceCount);

    // Connect to WiFi
    connectWiFi();

    // Get device ID (MAC address)
    deviceId = ApiClient::getMacAddress();
    Serial.printf("Device ID: %s\n", deviceId.c_str());

    // Create API client
    apiClient = new ApiClient(API_ENDPOINT, ESP32_API_KEY);

    Serial.printf("API Endpoint: %s\n", API_ENDPOINT);
    Serial.printf("Default interval: %d seconds\n", DEFAULT_INTERVAL_SEC);

    // Initialize telnet debugger for remote diagnostics
    debugger = new TelnetDebugger(&sensors, &oneWire, ONE_WIRE_BUS);
    if (debugger->begin()) {
        Serial.printf("Telnet debugger available at %s:23\n", WiFi.localIP().toString().c_str());
    }

    // Trigger immediate first report
    lastReportTime = millis() - currentIntervalMs - 1000;
}

void loop() {
    // Handle telnet connections
    debugger->loop();

    unsigned long now = millis();

    // Check if it's time to report
    if (now - lastReportTime >= (unsigned long)currentIntervalMs) {
        lastReportTime = now;

        // Check WiFi and reconnect if needed
        if (WiFi.status() != WL_CONNECTED) {
            Serial.println("WiFi disconnected, reconnecting...");
            WiFi.disconnect();
            delay(1000);
            connectWiFi();
        }

        // Read all sensors
        sensors.requestTemperatures();
        int deviceCount = sensors.getDeviceCount();

        // Build sensor readings array
        SensorReading readings[MAX_SENSORS];
        int validCount = 0;

        for (int i = 0; i < deviceCount && i < MAX_SENSORS; i++) {
            DeviceAddress addr;
            if (sensors.getAddress(addr, i)) {
                float tempC = sensors.getTempC(addr);
                if (tempC != DEVICE_DISCONNECTED_C) {
                    ApiClient::formatAddress(addr, readings[validCount].address);
                    readings[validCount].tempC = tempC;

                    Serial.printf("Sensor %s: %.2f C (%.2f F)\n",
                                  readings[validCount].address,
                                  tempC,
                                  tempC * 9.0 / 5.0 + 32.0);
                    validCount++;
                }
            }
        }

        // Blink LED to show activity
        digitalWrite(LED_PIN, HIGH);

        // Post to API
        unsigned long uptimeSeconds = millis() / 1000;
        ApiResponse response = apiClient->postSensors(
            deviceId.c_str(), readings, validCount, uptimeSeconds
        );

        digitalWrite(LED_PIN, LOW);

        if (response.success) {
            backoffTimer.recordSuccess();
            currentIntervalMs = response.intervalSeconds * 1000;
            Serial.printf("Success! Next report in %d seconds\n", response.intervalSeconds);
        } else {
            backoffTimer.recordFailure();
            currentIntervalMs = backoffTimer.getDelayMs();
            Serial.printf("Failed (HTTP %d). Retry in %lu ms\n",
                          response.httpCode, backoffTimer.getDelayMs());

            // Check if we should reboot
            if (backoffTimer.shouldReboot()) {
                Serial.println("Too many failures. Rebooting...");
                delay(1000);
                ESP.restart();
            }
        }
    }

    // Small delay to prevent tight loop
    delay(100);
}
