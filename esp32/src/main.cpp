#include <Arduino.h>
#include <WiFi.h>
#include <ArduinoOTA.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <api_client.h>
#include <telnet_debugger.h>

// Firmware version - increment this with each release
#define FIRMWARE_VERSION "1.3.0"

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

void setupOTA() {
    Serial.println("Setting up OTA...");

    // Set OTA hostname (shows up in PlatformIO/Arduino IDE)
    ArduinoOTA.setHostname("hottub-esp32");
    Serial.println("OTA hostname set to: hottub-esp32");

    // Optional: Set OTA password for security
    // ArduinoOTA.setPassword("hottub123");

    ArduinoOTA.onStart([]() {
        String type = (ArduinoOTA.getCommand() == U_FLASH) ? "firmware" : "filesystem";
        Serial.println("OTA Update starting: " + type);
    });

    ArduinoOTA.onEnd([]() {
        Serial.println("\nOTA Update complete!");
    });

    ArduinoOTA.onProgress([](unsigned int progress, unsigned int total) {
        Serial.printf("OTA Progress: %u%%\r", (progress / (total / 100)));
    });

    ArduinoOTA.onError([](ota_error_t error) {
        Serial.printf("OTA Error[%u]: ", error);
        if (error == OTA_AUTH_ERROR) Serial.println("Auth Failed");
        else if (error == OTA_BEGIN_ERROR) Serial.println("Begin Failed");
        else if (error == OTA_CONNECT_ERROR) Serial.println("Connect Failed");
        else if (error == OTA_RECEIVE_ERROR) Serial.println("Receive Failed");
        else if (error == OTA_END_ERROR) Serial.println("End Failed");
    });

    Serial.println("Calling ArduinoOTA.begin()...");
    ArduinoOTA.begin();
    Serial.printf("OTA setup complete - should be listening at %s:3232\n", WiFi.localIP().toString().c_str());
}

/**
 * Perform HTTP OTA firmware update.
 * Downloads firmware from the given URL and installs it.
 */
void performHttpOtaUpdate(const char* firmwareUrl, const char* newVersion) {
    Serial.println("========================================");
    Serial.printf("HTTP OTA Update starting...\n");
    Serial.printf("Current version: %s\n", FIRMWARE_VERSION);
    Serial.printf("New version: %s\n", newVersion);
    Serial.printf("URL: %s\n", firmwareUrl);
    Serial.println("========================================");

    HTTPClient http;
    http.begin(firmwareUrl);
    http.addHeader("X-ESP32-API-Key", ESP32_API_KEY);

    // Set longer timeout for firmware download
    http.setTimeout(60000);

    t_httpUpdate_return ret = httpUpdate.update(http);

    switch (ret) {
        case HTTP_UPDATE_FAILED:
            Serial.printf("HTTP OTA Update failed! Error (%d): %s\n",
                         httpUpdate.getLastError(),
                         httpUpdate.getLastErrorString().c_str());
            break;
        case HTTP_UPDATE_NO_UPDATES:
            Serial.println("HTTP OTA: No updates available");
            break;
        case HTTP_UPDATE_OK:
            Serial.println("HTTP OTA Update successful! Rebooting...");
            delay(1000);
            ESP.restart();
            break;
    }

    http.end();
}

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
    Serial.printf("Firmware Version: %s\n", FIRMWARE_VERSION);
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

    // Initialize OTA updates (port 3232)
    setupOTA();

    // Initialize telnet debugger for remote diagnostics (port 23)
    debugger = new TelnetDebugger(&sensors, &oneWire, ONE_WIRE_BUS);
    debugger->setFirmwareVersion(FIRMWARE_VERSION);
    if (debugger->begin()) {
        Serial.printf("Telnet debugger available at %s:23\n", WiFi.localIP().toString().c_str());
    }

    // Trigger immediate first report
    lastReportTime = millis() - currentIntervalMs - 1000;
}

void loop() {
    // Handle OTA updates (must be called frequently)
    ArduinoOTA.handle();

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

        // Post to API (include firmware version for OTA check)
        unsigned long uptimeSeconds = millis() / 1000;
        ApiResponse response = apiClient->postSensors(
            deviceId.c_str(), readings, validCount, uptimeSeconds, FIRMWARE_VERSION
        );

        digitalWrite(LED_PIN, LOW);

        if (response.success) {
            backoffTimer.recordSuccess();
            currentIntervalMs = response.intervalSeconds * 1000;
            Serial.printf("Success! Next report in %d seconds\n", response.intervalSeconds);

            // Check if firmware update is available
            if (response.updateAvailable) {
                Serial.printf("Firmware update available: %s -> %s\n",
                             FIRMWARE_VERSION, response.firmwareVersion);
                performHttpOtaUpdate(response.firmwareUrl, response.firmwareVersion);
                // If we get here, update failed - continue normal operation
            }
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
