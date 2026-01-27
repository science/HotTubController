#include <Arduino.h>
#include <WiFi.h>
#include <ArduinoOTA.h>
#include <HTTPClient.h>
#include <HTTPUpdate.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include <time.h>
#include <api_client.h>
#include <telnet_debugger.h>
#include <report_scheduler.h>

// Firmware version - increment this with each release
#define FIRMWARE_VERSION "1.4.0"

// Hardware pins
#define ONE_WIRE_BUS 4
#define LED_PIN 2

// NTP configuration
#define NTP_SERVER "pool.ntp.org"
#define NTP_SYNC_TIMEOUT_MS 10000  // 10 seconds max wait for NTP sync

// WIFI_SSID, WIFI_PASSWORD, ESP32_API_KEY, API_ENDPOINT injected via build flags

OneWire oneWire(ONE_WIRE_BUS);
DallasTemperature sensors(&oneWire);
ApiClient* apiClient = nullptr;
BackoffTimer backoffTimer;
TelnetDebugger* debugger = nullptr;

String deviceId;

/**
 * ESP32-specific time provider using NTP.
 * Implements TimeProvider interface for ReportScheduler.
 */
class Esp32TimeProvider : public TimeProvider {
public:
    uint32_t getCurrentTime() const override {
        time_t now;
        time(&now);
        return static_cast<uint32_t>(now);
    }

    int getSecondOfMinute() const override {
        struct tm timeinfo;
        time_t now;
        time(&now);
        localtime_r(&now, &timeinfo);
        return timeinfo.tm_sec;
    }

    bool isTimeSynced() const override {
        time_t now;
        time(&now);
        // NTP synced if time is after 2024 (before sync, time starts at 1970)
        return now > 1704067200; // 2024-01-01 00:00:00 UTC
    }
};

Esp32TimeProvider* timeProvider = nullptr;
ReportScheduler* reportScheduler = nullptr;

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

/**
 * Initialize NTP time synchronization.
 * Uses UTC to avoid timezone complexity.
 * Falls back gracefully if NTP sync fails - scheduler will use interval-only timing.
 */
void setupNTP() {
    Serial.println("Configuring NTP...");

    // Configure time with UTC (no timezone offset)
    // ESP32's SNTP library handles periodic re-sync internally
    configTime(0, 0, NTP_SERVER);

    // Wait for initial sync with timeout
    Serial.print("Waiting for NTP sync");
    unsigned long startMs = millis();
    while (!timeProvider->isTimeSynced() &&
           (millis() - startMs) < NTP_SYNC_TIMEOUT_MS) {
        delay(100);
        Serial.print(".");
    }

    if (timeProvider->isTimeSynced()) {
        struct tm timeinfo;
        time_t now;
        time(&now);
        localtime_r(&now, &timeinfo);
        Serial.printf("\nNTP synced! Time: %04d-%02d-%02d %02d:%02d:%02d UTC\n",
                      timeinfo.tm_year + 1900, timeinfo.tm_mon + 1, timeinfo.tm_mday,
                      timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec);
    } else {
        // NTP failed - scheduler will fall back to interval-only timing
        Serial.println("\nNTP sync timeout - using interval-only timing (no :55 alignment)");
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

    // Initialize time provider (needed before NTP setup)
    timeProvider = new Esp32TimeProvider();

    // Initialize NTP time synchronization
    if (WiFi.status() == WL_CONNECTED) {
        setupNTP();
    } else {
        Serial.println("WiFi not connected - skipping NTP setup");
    }

    // Initialize report scheduler with :55 second alignment
    // Scheduler handles fallback to interval-only timing if NTP not synced
    reportScheduler = new ReportScheduler(timeProvider, DEFAULT_INTERVAL_SEC, 55);
    Serial.printf("Report scheduler initialized (interval: %ds, align to :55)\n",
                  DEFAULT_INTERVAL_SEC);

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

    Serial.println("Setup complete - first report will be sent immediately");
}

void loop() {
    // Handle OTA updates (must be called frequently)
    ArduinoOTA.handle();

    // Handle telnet connections
    debugger->loop();

    // Check if scheduler says it's time to report
    if (reportScheduler->shouldSend()) {
        // Check WiFi and reconnect if needed
        if (WiFi.status() != WL_CONNECTED) {
            Serial.println("WiFi disconnected, reconnecting...");
            WiFi.disconnect();
            delay(1000);
            connectWiFi();

            // If still not connected, record send to prevent tight loop
            // and skip this report cycle
            if (WiFi.status() != WL_CONNECTED) {
                Serial.println("WiFi reconnect failed - skipping this report");
                reportScheduler->recordSend();
                backoffTimer.recordFailure();
                reportScheduler->setInterval(backoffTimer.getDelayMs() / 1000);
                return; // Return to main loop
            }
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

        // Log scheduler state for debugging
        if (timeProvider->isTimeSynced()) {
            struct tm timeinfo;
            time_t now;
            time(&now);
            localtime_r(&now, &timeinfo);
            Serial.printf("Sending at %02d:%02d:%02d (second %d)\n",
                          timeinfo.tm_hour, timeinfo.tm_min, timeinfo.tm_sec,
                          timeinfo.tm_sec);
        }

        // Blink LED to show activity
        digitalWrite(LED_PIN, HIGH);

        // Post to API (include firmware version for OTA check)
        unsigned long uptimeSeconds = millis() / 1000;
        ApiResponse response = apiClient->postSensors(
            deviceId.c_str(), readings, validCount, uptimeSeconds, FIRMWARE_VERSION
        );

        digitalWrite(LED_PIN, LOW);

        // Always record the send to advance scheduler state
        reportScheduler->recordSend();

        if (response.success) {
            backoffTimer.recordSuccess();
            reportScheduler->setInterval(response.intervalSeconds);

            int secsUntil = reportScheduler->getSecondsUntilSend();
            if (timeProvider->isTimeSynced() && response.intervalSeconds >= 60) {
                Serial.printf("Success! Next report in ~%d seconds (aligned to :55)\n", secsUntil);
            } else {
                Serial.printf("Success! Next report in %d seconds\n", response.intervalSeconds);
            }

            // Check if firmware update is available
            if (response.updateAvailable) {
                Serial.printf("Firmware update available: %s -> %s\n",
                             FIRMWARE_VERSION, response.firmwareVersion);
                performHttpOtaUpdate(response.firmwareUrl, response.firmwareVersion);
                // If we get here, update failed - continue normal operation
            }
        } else {
            backoffTimer.recordFailure();
            // On failure, use backoff interval
            int backoffSec = backoffTimer.getDelayMs() / 1000;
            reportScheduler->setInterval(backoffSec);
            Serial.printf("Failed (HTTP %d). Retry in %d seconds (state: %s)\n",
                          response.httpCode, backoffSec,
                          ReportScheduler::stateToString(reportScheduler->getState()));

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
