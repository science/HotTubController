#include <unity.h>
#include <cstdio>
#include <cstdint>
#include <cstring>

// Include our diagnostic module (pure C++, no Arduino)
#include <onewire_diagnostics.h>

void setUp(void) {}
void tearDown(void) {}

// ==================== Address Formatting Tests ====================

void test_formatAddress_formats_correctly(void) {
    uint8_t addr[8] = {0x28, 0xFF, 0x12, 0x34, 0x56, 0x78, 0x9A, 0xBC};
    char buffer[24];

    OneWireDiagnostics::formatAddress(addr, buffer);

    TEST_ASSERT_EQUAL_STRING("28:FF:12:34:56:78:9A:BC", buffer);
}

void test_formatAddress_handles_zeros(void) {
    uint8_t addr[8] = {0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
    char buffer[24];

    OneWireDiagnostics::formatAddress(addr, buffer);

    TEST_ASSERT_EQUAL_STRING("00:00:00:00:00:00:00:00", buffer);
}

void test_formatAddress_handles_all_ff(void) {
    uint8_t addr[8] = {0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF, 0xFF};
    char buffer[24];

    OneWireDiagnostics::formatAddress(addr, buffer);

    TEST_ASSERT_EQUAL_STRING("FF:FF:FF:FF:FF:FF:FF:FF", buffer);
}

// ==================== Temperature Interpretation Tests ====================

void test_isValidTemperature_true_for_normal_temps(void) {
    TEST_ASSERT_TRUE(OneWireDiagnostics::isValidTemperature(25.0f));
    TEST_ASSERT_TRUE(OneWireDiagnostics::isValidTemperature(0.0f));
    TEST_ASSERT_TRUE(OneWireDiagnostics::isValidTemperature(100.0f));
    TEST_ASSERT_TRUE(OneWireDiagnostics::isValidTemperature(-10.0f));
}

void test_isValidTemperature_false_for_disconnected(void) {
    // DEVICE_DISCONNECTED_C is -127.0
    TEST_ASSERT_FALSE(OneWireDiagnostics::isValidTemperature(-127.0f));
}

void test_isValidTemperature_false_for_error_values(void) {
    // 85.0 is the DS18B20 power-on reset value (indicates read before conversion)
    TEST_ASSERT_FALSE(OneWireDiagnostics::isValidTemperature(85.0f));
}

void test_getTemperatureStatus_normal(void) {
    const char* status = OneWireDiagnostics::getTemperatureStatus(25.0f);
    TEST_ASSERT_EQUAL_STRING("OK", status);
}

void test_getTemperatureStatus_disconnected(void) {
    const char* status = OneWireDiagnostics::getTemperatureStatus(-127.0f);
    TEST_ASSERT_EQUAL_STRING("DISCONNECTED", status);
}

void test_getTemperatureStatus_power_on_reset(void) {
    const char* status = OneWireDiagnostics::getTemperatureStatus(85.0f);
    TEST_ASSERT_EQUAL_STRING("POWER_ON_RESET", status);
}

// ==================== Family Code Tests ====================

void test_getFamilyName_DS18B20(void) {
    uint8_t addr[8] = {0x28, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
    const char* name = OneWireDiagnostics::getFamilyName(addr);
    TEST_ASSERT_EQUAL_STRING("DS18B20", name);
}

void test_getFamilyName_DS18S20(void) {
    uint8_t addr[8] = {0x10, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
    const char* name = OneWireDiagnostics::getFamilyName(addr);
    TEST_ASSERT_EQUAL_STRING("DS18S20", name);
}

void test_getFamilyName_DS1822(void) {
    uint8_t addr[8] = {0x22, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
    const char* name = OneWireDiagnostics::getFamilyName(addr);
    TEST_ASSERT_EQUAL_STRING("DS1822", name);
}

void test_getFamilyName_unknown(void) {
    uint8_t addr[8] = {0x99, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00};
    const char* name = OneWireDiagnostics::getFamilyName(addr);
    TEST_ASSERT_EQUAL_STRING("UNKNOWN", name);
}

// ==================== Diagnostic Report Tests ====================

void test_DiagnosticResult_initialized_correctly(void) {
    DiagnosticResult result;
    result.deviceCount = 0;
    result.parasitic = false;
    result.busState = BUS_STATE_UNKNOWN;

    TEST_ASSERT_EQUAL(0, result.deviceCount);
    TEST_ASSERT_FALSE(result.parasitic);
    TEST_ASSERT_EQUAL(BUS_STATE_UNKNOWN, result.busState);
}

void test_busStateToString_returns_correct_strings(void) {
    TEST_ASSERT_EQUAL_STRING("OK", OneWireDiagnostics::busStateToString(BUS_STATE_OK));
    TEST_ASSERT_EQUAL_STRING("NO_DEVICES", OneWireDiagnostics::busStateToString(BUS_STATE_NO_DEVICES));
    TEST_ASSERT_EQUAL_STRING("SHORT_CIRCUIT", OneWireDiagnostics::busStateToString(BUS_STATE_SHORT));
    TEST_ASSERT_EQUAL_STRING("UNKNOWN", OneWireDiagnostics::busStateToString(BUS_STATE_UNKNOWN));
}

// ==================== Main ====================

int main(int argc, char **argv) {
    UNITY_BEGIN();

    // Address formatting tests
    RUN_TEST(test_formatAddress_formats_correctly);
    RUN_TEST(test_formatAddress_handles_zeros);
    RUN_TEST(test_formatAddress_handles_all_ff);

    // Temperature interpretation tests
    RUN_TEST(test_isValidTemperature_true_for_normal_temps);
    RUN_TEST(test_isValidTemperature_false_for_disconnected);
    RUN_TEST(test_isValidTemperature_false_for_error_values);
    RUN_TEST(test_getTemperatureStatus_normal);
    RUN_TEST(test_getTemperatureStatus_disconnected);
    RUN_TEST(test_getTemperatureStatus_power_on_reset);

    // Family code tests
    RUN_TEST(test_getFamilyName_DS18B20);
    RUN_TEST(test_getFamilyName_DS18S20);
    RUN_TEST(test_getFamilyName_DS1822);
    RUN_TEST(test_getFamilyName_unknown);

    // Diagnostic result tests
    RUN_TEST(test_DiagnosticResult_initialized_correctly);
    RUN_TEST(test_busStateToString_returns_correct_strings);

    return UNITY_END();
}
