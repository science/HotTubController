#ifndef ONEWIRE_DIAGNOSTICS_H
#define ONEWIRE_DIAGNOSTICS_H

#include <cstdint>
#include <cstdio>

// Bus state enumeration
enum BusState {
    BUS_STATE_OK,
    BUS_STATE_NO_DEVICES,
    BUS_STATE_SHORT,
    BUS_STATE_UNKNOWN
};

// Diagnostic result structure
struct DiagnosticResult {
    int deviceCount;
    bool parasitic;
    BusState busState;
};

class OneWireDiagnostics {
public:
    // Format DS18B20 address to string "XX:XX:XX:XX:XX:XX:XX:XX"
    static void formatAddress(const uint8_t* address, char* buffer);

    // Check if temperature reading is valid (not error code)
    static bool isValidTemperature(float tempC);

    // Get human-readable status for a temperature reading
    static const char* getTemperatureStatus(float tempC);

    // Get family name from device address
    static const char* getFamilyName(const uint8_t* address);

    // Convert bus state to string
    static const char* busStateToString(BusState state);
};

#endif // ONEWIRE_DIAGNOSTICS_H
