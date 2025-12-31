#ifndef API_CLIENT_H
#define API_CLIENT_H

#include <Arduino.h>

// Interval bounds (seconds)
#define DEFAULT_INTERVAL_SEC 300    // 5 minutes
#define MIN_INTERVAL_SEC 10
#define MAX_INTERVAL_SEC 1800       // 30 minutes

// Backoff settings (milliseconds)
#define BACKOFF_START_MS 10000      // 10 seconds
#define BACKOFF_MAX_MS 300000       // 5 minutes

// Recovery settings
#define REBOOT_AFTER_FAILURE_MS 1800000  // 30 minutes

struct ApiResponse {
    bool success;
    int intervalSeconds;
    int httpCode;
};

class ApiClient {
public:
    ApiClient(const char* endpoint, const char* apiKey);

    // Post temperature reading, returns next interval in seconds
    ApiResponse postTemperature(const char* deviceId, float tempC, float tempF, unsigned long uptimeSeconds);

    // Get device MAC address as string
    static String getMacAddress();

    // Clamp interval to valid bounds
    static int clampInterval(int interval);

private:
    const char* endpoint;
    const char* apiKey;
};

class BackoffTimer {
public:
    BackoffTimer();

    // Get current backoff delay in milliseconds
    unsigned long getDelayMs() const;

    // Record a failure, increase backoff
    void recordFailure();

    // Record a success, reset backoff
    void recordSuccess();

    // Check if we should reboot (continuous failures for too long)
    bool shouldReboot() const;

    // Get time since first failure (for reboot decision)
    unsigned long getFailureDurationMs() const;

private:
    unsigned long currentDelayMs;
    unsigned long firstFailureTime;
    bool inFailureState;
};

#endif
