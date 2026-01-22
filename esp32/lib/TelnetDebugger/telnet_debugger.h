#ifndef TELNET_DEBUGGER_H
#define TELNET_DEBUGGER_H

#include <Arduino.h>
#include <ESPTelnet.h>
#include <OneWire.h>
#include <DallasTemperature.h>
#include "onewire_diagnostics.h"

// Default telnet port
#define TELNET_PORT 23

class TelnetDebugger {
public:
    TelnetDebugger(DallasTemperature* sensors, OneWire* oneWire, uint8_t oneWirePin);

    // Initialize telnet server
    bool begin(uint16_t port = TELNET_PORT);

    // Must be called in loop() to handle telnet connections
    void loop();

    // Print to both Serial and Telnet (if connected)
    void print(const char* message);
    void println(const char* message);
    void printf(const char* format, ...);

    // Run full diagnostic scan and output results
    void runDiagnostics();

    // Check if a client is connected
    bool isConnected();

    // Get the IP address for connection
    String getIP();

    // Set firmware version for display
    void setFirmwareVersion(const char* version);

    // Handle incoming command
    void handleCommand(String input);

    // Static instance for callbacks
    static TelnetDebugger* instance;

private:
    ESPTelnet telnet;
    DallasTemperature* sensors;
    OneWire* oneWire;
    uint8_t pin;
    bool connected;
    const char* firmwareVersion;

    // Diagnostic helpers
    void scanOneWireBus();
    void readAllSensors();
    void printBusState();
    void printDeviceInfo(uint8_t* address, int index);

    // Static callbacks for ESPTelnet
    static void onTelnetConnect(String ip);
    static void onTelnetDisconnect(String ip);
    static void onTelnetInput(String input);
};

#endif // TELNET_DEBUGGER_H
