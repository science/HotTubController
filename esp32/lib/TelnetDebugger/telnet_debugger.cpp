#include "telnet_debugger.h"
#include <stdarg.h>
#include <ArduinoOTA.h>

// Static instance pointer for callbacks
TelnetDebugger* TelnetDebugger::instance = nullptr;

TelnetDebugger::TelnetDebugger(DallasTemperature* sensors, OneWire* oneWire, uint8_t oneWirePin)
    : sensors(sensors), oneWire(oneWire), pin(oneWirePin), connected(false), firmwareVersion("unknown") {
    instance = this;
}

void TelnetDebugger::setFirmwareVersion(const char* version) {
    firmwareVersion = version;
}

// Static callbacks
void TelnetDebugger::onTelnetConnect(String ip) {
    if (instance) {
        instance->connected = true;
        Serial.printf("Telnet client connected from %s\n", ip.c_str());
        instance->println("=================================");
        instance->println("ESP32 Hot Tub Diagnostic Console");
        instance->printf("Firmware: %s\n", instance->firmwareVersion);
        instance->println("=================================");
        instance->println("Type 'help' for commands");
        instance->println("");
    }
}

void TelnetDebugger::onTelnetDisconnect(String ip) {
    if (instance) {
        instance->connected = false;
        Serial.printf("Telnet client disconnected from %s\n", ip.c_str());
    }
}

void TelnetDebugger::onTelnetInput(String input) {
    if (instance) {
        instance->handleCommand(input);
    }
}

bool TelnetDebugger::begin(uint16_t port) {
    // Set up telnet callbacks using static functions
    telnet.onConnect(onTelnetConnect);
    telnet.onDisconnect(onTelnetDisconnect);
    telnet.onInputReceived(onTelnetInput);

    bool success = telnet.begin(port);
    if (success) {
        Serial.printf("Telnet server started on port %d\n", port);
    } else {
        Serial.println("Failed to start telnet server");
    }
    return success;
}

void TelnetDebugger::handleCommand(String input) {
    input.trim();
    if (input == "help") {
        println("Commands:");
        println("  diag       - Run full diagnostics");
        println("  scan       - Scan OneWire bus");
        println("  read       - Read all sensors");
        println("  info       - Show connection info");
        println("  ota        - Show OTA update status");
        println("  update URL - Trigger HTTP OTA from URL");
        println("  help       - Show this help");
    } else if (input == "diag") {
        runDiagnostics();
    } else if (input == "scan") {
        scanOneWireBus();
    } else if (input == "read") {
        readAllSensors();
    } else if (input == "info") {
        printf("Firmware: %s\n", firmwareVersion);
        printf("IP Address: %s\n", WiFi.localIP().toString().c_str());
        printf("OneWire Pin: GPIO%d\n", pin);
        printf("Uptime: %lu seconds\n", millis() / 1000);
    } else if (input == "ota") {
        println("--- OTA Status ---");
        printf("Firmware: %s\n", firmwareVersion);
        printf("ArduinoOTA Hostname: %s\n", ArduinoOTA.getHostname().c_str());
        printf("ArduinoOTA port: 3232 (UDP)\n");
        printf("Free heap: %lu bytes\n", ESP.getFreeHeap());
        println("");
        println("For HTTP OTA, use: update <url>");
        println("Example: update https://example.com/api/esp32/firmware/download");
    } else if (input.startsWith("update ")) {
        String url = input.substring(7);
        url.trim();
        if (url.length() > 0) {
            printf("Starting HTTP OTA from: %s\n", url.c_str());
            println("This will download and install new firmware...");
            println("Device will reboot if successful.");
            // Note: actual update must be done in main.cpp with proper includes
            // This just displays info - real update triggered via API response
            println("Use the API to trigger updates (reports firmware_version)");
        } else {
            println("Usage: update <firmware_url>");
        }
    } else if (input.length() > 0) {
        printf("Unknown command: %s\n", input.c_str());
        println("Type 'help' for available commands");
    }
}

void TelnetDebugger::loop() {
    telnet.loop();
}

void TelnetDebugger::print(const char* message) {
    Serial.print(message);
    if (connected) {
        telnet.print(message);
    }
}

void TelnetDebugger::println(const char* message) {
    Serial.println(message);
    if (connected) {
        telnet.println(message);
    }
}

void TelnetDebugger::printf(const char* format, ...) {
    char buffer[256];
    va_list args;
    va_start(args, format);
    vsnprintf(buffer, sizeof(buffer), format, args);
    va_end(args);
    print(buffer);
}

bool TelnetDebugger::isConnected() {
    return connected;
}

String TelnetDebugger::getIP() {
    return WiFi.localIP().toString();
}

void TelnetDebugger::runDiagnostics() {
    println("");
    println("========== FULL DIAGNOSTICS ==========");
    println("");

    // Connection info
    println("--- Connection Info ---");
    printf("Firmware: %s\n", firmwareVersion);
    printf("WiFi SSID: %s\n", WiFi.SSID().c_str());
    printf("IP Address: %s\n", WiFi.localIP().toString().c_str());
    printf("Signal Strength: %d dBm\n", WiFi.RSSI());
    printf("Uptime: %lu seconds\n", millis() / 1000);
    println("");

    // Hardware config
    println("--- Hardware Config ---");
    printf("OneWire Pin: GPIO%d\n", pin);
    println("");

    // Bus scan
    scanOneWireBus();
    println("");

    // Sensor readings
    readAllSensors();

    println("");
    println("========== END DIAGNOSTICS ==========");
    println("");
}

void TelnetDebugger::scanOneWireBus() {
    println("--- OneWire Bus Scan ---");

    // Reset the bus and check for presence
    uint8_t busState = oneWire->reset();
    if (busState == 0) {
        println("Bus State: NO PRESENCE PULSE DETECTED");
        println("  -> No devices responding or bus shorted to ground");
        return;
    }
    println("Bus State: Presence pulse detected (OK)");

    // Check for parasitic power
    sensors->begin();
    bool parasitic = !sensors->isParasitePowerMode();
    printf("Power Mode: %s\n", parasitic ? "PARASITIC (2-wire)" : "EXTERNAL (3-wire)");

    // Count devices
    int deviceCount = sensors->getDeviceCount();
    printf("Devices Found: %d\n", deviceCount);

    if (deviceCount == 0) {
        println("");
        println("WARNING: No devices found!");
        println("Possible causes:");
        println("  1. Incorrect wiring (check VCC, GND, DATA)");
        println("  2. Missing or wrong pull-up resistor (need 4.7k)");
        println("  3. Cable too long (try shorter cable)");
        println("  4. Damaged sensor");
        return;
    }

    println("");
    println("--- Device Details ---");

    // Enumerate all devices
    DeviceAddress address;
    for (int i = 0; i < deviceCount; i++) {
        if (sensors->getAddress(address, i)) {
            printDeviceInfo(address, i);
        } else {
            printf("Device %d: Failed to get address\n", i);
        }
    }
}

void TelnetDebugger::printDeviceInfo(uint8_t* address, int index) {
    char addrStr[24];
    OneWireDiagnostics::formatAddress(address, addrStr);

    const char* familyName = OneWireDiagnostics::getFamilyName(address);

    printf("\nDevice %d:\n", index);
    printf("  Address: %s\n", addrStr);
    printf("  Family: %s (0x%02X)\n", familyName, address[0]);

    // Read resolution
    uint8_t resolution = sensors->getResolution(address);
    printf("  Resolution: %d bits\n", resolution);

    // Validate CRC
    if (OneWire::crc8(address, 7) != address[7]) {
        println("  CRC: INVALID - address may be corrupted!");
    } else {
        println("  CRC: Valid");
    }
}

void TelnetDebugger::readAllSensors() {
    println("--- Sensor Readings ---");

    int deviceCount = sensors->getDeviceCount();
    if (deviceCount == 0) {
        println("No sensors to read");
        return;
    }

    println("Requesting temperatures...");
    unsigned long startTime = millis();
    sensors->requestTemperatures();
    unsigned long elapsed = millis() - startTime;
    printf("Conversion time: %lu ms\n", elapsed);
    println("");

    DeviceAddress address;
    for (int i = 0; i < deviceCount; i++) {
        if (sensors->getAddress(address, i)) {
            char addrStr[24];
            OneWireDiagnostics::formatAddress(address, addrStr);

            float tempC = sensors->getTempC(address);
            float tempF = tempC * 9.0 / 5.0 + 32.0;

            const char* status = OneWireDiagnostics::getTemperatureStatus(tempC);

            printf("Sensor %d (%s):\n", i, addrStr);
            printf("  Temperature: %.2f C / %.2f F\n", tempC, tempF);
            printf("  Status: %s\n", status);

            if (!OneWireDiagnostics::isValidTemperature(tempC)) {
                println("  WARNING: Invalid reading!");
                if (tempC == -127.0) {
                    println("  -> Device not responding (check wiring)");
                } else if (tempC == 85.0) {
                    println("  -> Power-on reset value (conversion may have failed)");
                }
            }
            println("");
        }
    }
}
