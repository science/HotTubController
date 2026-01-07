#include "onewire_diagnostics.h"

// DS18B20 error constants
#define DEVICE_DISCONNECTED_C -127.0f
#define POWER_ON_RESET_C 85.0f

// OneWire family codes
#define DS18S20_FAMILY 0x10
#define DS18B20_FAMILY 0x28
#define DS1822_FAMILY  0x22

void OneWireDiagnostics::formatAddress(const uint8_t* address, char* buffer) {
    snprintf(buffer, 24, "%02X:%02X:%02X:%02X:%02X:%02X:%02X:%02X",
             address[0], address[1], address[2], address[3],
             address[4], address[5], address[6], address[7]);
}

bool OneWireDiagnostics::isValidTemperature(float tempC) {
    // Check for known error values
    if (tempC == DEVICE_DISCONNECTED_C) return false;
    if (tempC == POWER_ON_RESET_C) return false;
    return true;
}

const char* OneWireDiagnostics::getTemperatureStatus(float tempC) {
    if (tempC == DEVICE_DISCONNECTED_C) return "DISCONNECTED";
    if (tempC == POWER_ON_RESET_C) return "POWER_ON_RESET";
    return "OK";
}

const char* OneWireDiagnostics::getFamilyName(const uint8_t* address) {
    switch (address[0]) {
        case DS18B20_FAMILY: return "DS18B20";
        case DS18S20_FAMILY: return "DS18S20";
        case DS1822_FAMILY:  return "DS1822";
        default: return "UNKNOWN";
    }
}

const char* OneWireDiagnostics::busStateToString(BusState state) {
    switch (state) {
        case BUS_STATE_OK: return "OK";
        case BUS_STATE_NO_DEVICES: return "NO_DEVICES";
        case BUS_STATE_SHORT: return "SHORT_CIRCUIT";
        default: return "UNKNOWN";
    }
}
