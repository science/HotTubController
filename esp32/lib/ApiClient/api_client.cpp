#include "api_client.h"
#include <WiFi.h>
#include <HTTPClient.h>
#include <ArduinoJson.h>

ApiClient::ApiClient(const char* endpoint, const char* apiKey)
    : endpoint(endpoint), apiKey(apiKey) {}

ApiResponse ApiClient::postTemperature(const char* deviceId, float tempC, float tempF, unsigned long uptimeSeconds) {
    ApiResponse response = {false, DEFAULT_INTERVAL_SEC, 0};

    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi not connected, skipping API call");
        return response;
    }

    HTTPClient http;
    http.begin(endpoint);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-ESP32-API-Key", apiKey);

    // Build JSON payload
    JsonDocument doc;
    doc["device_id"] = deviceId;
    doc["temp_c"] = tempC;
    doc["temp_f"] = tempF;
    doc["uptime_seconds"] = uptimeSeconds;

    String payload;
    serializeJson(doc, payload);

    Serial.printf("POST %s\n", endpoint);
    Serial.printf("Payload: %s\n", payload.c_str());

    int httpCode = http.POST(payload);
    response.httpCode = httpCode;

    if (httpCode == 200) {
        String responseBody = http.getString();
        Serial.printf("Response: %s\n", responseBody.c_str());

        // Parse response
        JsonDocument responseDoc;
        DeserializationError error = deserializeJson(responseDoc, responseBody);

        if (!error) {
            response.success = true;
            if (!responseDoc["interval_seconds"].isNull()) {
                response.intervalSeconds = clampInterval(responseDoc["interval_seconds"].as<int>());
            }
        } else {
            Serial.printf("JSON parse error: %s\n", error.c_str());
        }
    } else {
        Serial.printf("HTTP error: %d\n", httpCode);
        if (httpCode > 0) {
            Serial.println(http.getString());
        }
    }

    http.end();
    return response;
}

ApiResponse ApiClient::postSensors(const char* deviceId, SensorReading* sensors, int sensorCount,
                                   unsigned long uptimeSeconds, const char* firmwareVersion) {
    ApiResponse response = {false, DEFAULT_INTERVAL_SEC, 0, false, "", ""};

    if (WiFi.status() != WL_CONNECTED) {
        Serial.println("WiFi not connected, skipping API call");
        return response;
    }

    HTTPClient http;
    http.begin(endpoint);
    http.addHeader("Content-Type", "application/json");
    http.addHeader("X-ESP32-API-Key", apiKey);

    // Build JSON payload with sensors array
    JsonDocument doc;
    doc["device_id"] = deviceId;
    doc["uptime_seconds"] = uptimeSeconds;

    // Include firmware version if provided (for OTA update check)
    if (firmwareVersion != nullptr) {
        doc["firmware_version"] = firmwareVersion;
    }

    JsonArray sensorsArray = doc["sensors"].to<JsonArray>();
    for (int i = 0; i < sensorCount; i++) {
        JsonObject sensorObj = sensorsArray.add<JsonObject>();
        sensorObj["address"] = sensors[i].address;
        sensorObj["temp_c"] = sensors[i].tempC;
    }

    String payload;
    serializeJson(doc, payload);

    Serial.printf("POST %s\n", endpoint);
    Serial.printf("Payload: %s\n", payload.c_str());

    int httpCode = http.POST(payload);
    response.httpCode = httpCode;

    if (httpCode == 200) {
        String responseBody = http.getString();
        Serial.printf("Response: %s\n", responseBody.c_str());

        // Parse response
        JsonDocument responseDoc;
        DeserializationError error = deserializeJson(responseDoc, responseBody);

        if (!error) {
            response.success = true;
            if (!responseDoc["interval_seconds"].isNull()) {
                response.intervalSeconds = clampInterval(responseDoc["interval_seconds"].as<int>());
            }

            // Check for firmware update info
            if (!responseDoc["firmware_version"].isNull() && !responseDoc["firmware_url"].isNull()) {
                response.updateAvailable = true;
                strncpy(response.firmwareVersion, responseDoc["firmware_version"].as<const char*>(), sizeof(response.firmwareVersion) - 1);
                response.firmwareVersion[sizeof(response.firmwareVersion) - 1] = '\0';
                strncpy(response.firmwareUrl, responseDoc["firmware_url"].as<const char*>(), sizeof(response.firmwareUrl) - 1);
                response.firmwareUrl[sizeof(response.firmwareUrl) - 1] = '\0';
                Serial.printf("Firmware update available: %s\n", response.firmwareVersion);
            }
        } else {
            Serial.printf("JSON parse error: %s\n", error.c_str());
        }
    } else {
        Serial.printf("HTTP error: %d\n", httpCode);
        if (httpCode > 0) {
            Serial.println(http.getString());
        }
    }

    http.end();
    return response;
}

void ApiClient::formatAddress(uint8_t* address, char* buffer) {
    snprintf(buffer, 24, "%02X:%02X:%02X:%02X:%02X:%02X:%02X:%02X",
             address[0], address[1], address[2], address[3],
             address[4], address[5], address[6], address[7]);
}

String ApiClient::getMacAddress() {
    uint8_t mac[6];
    WiFi.macAddress(mac);
    char macStr[18];
    snprintf(macStr, sizeof(macStr), "%02X:%02X:%02X:%02X:%02X:%02X",
             mac[0], mac[1], mac[2], mac[3], mac[4], mac[5]);
    return String(macStr);
}

int ApiClient::clampInterval(int interval) {
    if (interval < MIN_INTERVAL_SEC) return MIN_INTERVAL_SEC;
    if (interval > MAX_INTERVAL_SEC) return MAX_INTERVAL_SEC;
    return interval;
}

// BackoffTimer implementation

BackoffTimer::BackoffTimer()
    : currentDelayMs(BACKOFF_START_MS), firstFailureTime(0), inFailureState(false) {}

unsigned long BackoffTimer::getDelayMs() const {
    return currentDelayMs;
}

void BackoffTimer::recordFailure() {
    if (!inFailureState) {
        inFailureState = true;
        firstFailureTime = millis();
        currentDelayMs = BACKOFF_START_MS;
    } else {
        // Double the delay, up to max
        currentDelayMs = min(currentDelayMs * 2, (unsigned long)BACKOFF_MAX_MS);
    }
    Serial.printf("Backoff: next retry in %lu ms\n", currentDelayMs);
}

void BackoffTimer::recordSuccess() {
    inFailureState = false;
    firstFailureTime = 0;
    currentDelayMs = BACKOFF_START_MS;
}

bool BackoffTimer::shouldReboot() const {
    if (!inFailureState) return false;
    return getFailureDurationMs() >= REBOOT_AFTER_FAILURE_MS;
}

unsigned long BackoffTimer::getFailureDurationMs() const {
    if (!inFailureState) return 0;
    return millis() - firstFailureTime;
}
