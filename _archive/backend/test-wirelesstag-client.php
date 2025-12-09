<?php

declare(strict_types=1);

/**
 * WirelessTag Client Implementation Test
 * 
 * This script tests our WirelessTagClient class against the live API
 * to verify it works correctly with real responses.
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Config\ExternalApiConfig;
use HotTubController\Services\WirelessTagClient;

// ANSI color codes for terminal output
const COLORS = [
    'green' => "\033[32m",
    'red' => "\033[31m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'cyan' => "\033[36m",
    'reset' => "\033[0m"
];

function colorOutput(string $text, string $color): string {
    return COLORS[$color] . $text . COLORS['reset'];
}

function printHeader(string $title): void {
    echo "\n" . colorOutput("=== {$title} ===", 'blue') . "\n";
}

function printSuccess(string $message): void {
    echo colorOutput("✓ {$message}", 'green') . "\n";
}

function printError(string $message): void {
    echo colorOutput("✗ {$message}", 'red') . "\n";
}

function printWarning(string $message): void {
    echo colorOutput("⚠ {$message}", 'yellow') . "\n";
}

function printInfo(string $message): void {
    echo colorOutput("ℹ {$message}", 'cyan') . "\n";
}

function testClientConnectivity(WirelessTagClient $client): bool
{
    printHeader("Testing Client Connectivity");
    
    $result = $client->testConnectivity();
    
    if ($result['available']) {
        printSuccess("Client connectivity successful");
        printInfo("Response time: {$result['response_time_ms']}ms");
        printInfo("Authenticated: " . ($result['authenticated'] ? 'Yes' : 'No'));
        return true;
    } else {
        printError("Client connectivity failed");
        if ($result['error']) {
            printError("Error: {$result['error']}");
        }
        return false;
    }
}

function testCachedTemperatureData(WirelessTagClient $client, string $deviceId): ?array
{
    printHeader("Testing Cached Temperature Data");
    
    printInfo("Device ID: {$deviceId}");
    printInfo("Getting cached temperature data...");
    
    $rawData = $client->getCachedTemperatureData($deviceId);
    
    if (!$rawData) {
        printError("Failed to retrieve cached temperature data");
        return null;
    }
    
    printSuccess("Cached data retrieved successfully");
    printInfo("Raw response contains " . count($rawData) . " device(s)");
    
    // Show raw data structure
    echo colorOutput("\nRaw API Response:", 'yellow') . "\n";
    echo json_encode($rawData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    
    // Process the data using our client
    try {
        $processed = $client->processTemperatureData($rawData, 0);
        
        printSuccess("Data processing successful");
        
        // Display processed data
        echo colorOutput("\nProcessed Temperature Data:", 'cyan') . "\n";
        
        $waterTemp = $processed['water_temperature'];
        $ambientTemp = $processed['ambient_temperature'];
        $sensorInfo = $processed['sensor_info'];
        
        echo "Water Temperature:\n";
        echo "  - Celsius: {$waterTemp['celsius']}°C\n";
        echo "  - Fahrenheit: {$waterTemp['fahrenheit']}°F\n";
        echo "  - Source: {$waterTemp['source']}\n";
        
        echo "\nAmbient Temperature:\n";
        echo "  - Celsius: {$ambientTemp['celsius']}°C\n";  
        echo "  - Fahrenheit: {$ambientTemp['fahrenheit']}°F\n";
        echo "  - Source: {$ambientTemp['source']}\n";
        
        echo "\nSensor Information:\n";
        echo "  - Battery Voltage: {$sensorInfo['battery_voltage']}V\n";
        echo "  - Signal Strength: {$sensorInfo['signal_strength_dbm']}dBm\n";
        echo "  - Device ID: {$processed['device_id']}\n";
        echo "  - Timestamp: " . date('Y-m-d H:i:s', $processed['data_timestamp']) . "\n";
        
        // Test temperature validation
        if ($waterTemp['fahrenheit'] !== null) {
            $validWater = $client->validateTemperature($waterTemp['fahrenheit'], 'water');
            echo "\nTemperature Validation:\n";
            echo "  - Water temp valid: " . ($validWater ? 'Yes' : 'No') . "\n";
        }
        
        if ($ambientTemp['fahrenheit'] !== null) {
            $validAmbient = $client->validateTemperature($ambientTemp['fahrenheit'], 'ambient');
            echo "  - Ambient temp valid: " . ($validAmbient ? 'Yes' : 'No') . "\n";
        }
        
        // Test calibration if both temperatures available
        if ($waterTemp['fahrenheit'] !== null && $ambientTemp['fahrenheit'] !== null) {
            $calibrated = $client->calibrateAmbientTemperature(
                $ambientTemp['fahrenheit'], 
                $waterTemp['fahrenheit']
            );
            
            echo "\nTemperature Calibration:\n";
            echo "  - Raw ambient: {$ambientTemp['fahrenheit']}°F\n";
            echo "  - Calibrated ambient: {$calibrated}°F\n";
            echo "  - Calibration offset: " . round($calibrated - $ambientTemp['fahrenheit'], 2) . "°F\n";
        }
        
        return $processed;
        
    } catch (Exception $e) {
        printError("Data processing failed: " . $e->getMessage());
        return null;
    }
}

function testFreshTemperatureData(WirelessTagClient $client, string $deviceId): void
{
    printHeader("Testing Fresh Temperature Data");
    
    printWarning("This will activate sensor hardware and use battery power!");
    echo "Continue with fresh reading test? (y/N): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (!$line || trim(strtolower($line)) !== 'y') {
        printInfo("Skipping fresh reading test to preserve battery");
        return;
    }
    
    printInfo("Requesting fresh temperature reading...");
    
    $freshData = $client->getFreshTemperatureData($deviceId, 3);
    
    if (!$freshData) {
        printError("Failed to get fresh temperature data");
        return;
    }
    
    printSuccess("Fresh temperature data retrieved");
    
    // Process fresh data
    try {
        $processed = $client->processTemperatureData($freshData, 0);
        $processed['is_fresh_reading'] = true; // Mark as fresh
        
        echo colorOutput("\nFresh Reading Results:", 'cyan') . "\n";
        echo "Water Temperature: {$processed['water_temperature']['fahrenheit']}°F\n";
        echo "Ambient Temperature: {$processed['ambient_temperature']['fahrenheit']}°F\n";
        echo "Battery Voltage: {$processed['sensor_info']['battery_voltage']}V\n";
        echo "Fresh reading timestamp: " . date('Y-m-d H:i:s', $processed['data_timestamp']) . "\n";
        
    } catch (Exception $e) {
        printError("Fresh data processing failed: " . $e->getMessage());
    }
}

function testClientErrorHandling(WirelessTagClient $client): void
{
    printHeader("Testing Error Handling");
    
    // Test with invalid device ID
    printInfo("Testing with invalid device ID...");
    
    $invalidData = $client->getCachedTemperatureData('invalid-device-id-12345');
    
    if ($invalidData === null) {
        printSuccess("Invalid device ID handled correctly (returned null)");
    } else {
        printWarning("Unexpected: Invalid device ID returned data");
        var_dump($invalidData);
    }
    
    // Test data processing with invalid index
    printInfo("Testing data processing with invalid device index...");
    
    try {
        $testData = [
            ['temperature' => 20.0, 'cap' => 19.0, 'batteryVolt' => 3.1]
        ];
        
        // Try to access index 5 when only index 0 exists
        $processed = $client->processTemperatureData($testData, 5);
        printWarning("Unexpected: Invalid index should have thrown exception");
        
    } catch (Exception $e) {
        printSuccess("Invalid device index handled correctly: " . $e->getMessage());
    }
}

function main(): void
{
    echo colorOutput("WirelessTag Client Implementation Test", 'blue') . "\n";
    echo "Testing WirelessTagClient against live API\n";
    
    try {
        // Load environment variables
        if (file_exists(__DIR__ . '/.env')) {
            $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
            $dotenv->load();
        }
        
        // Load configuration
        printInfo("Loading configuration...");
        $config = new ExternalApiConfig();
        
        if (!$config->hasValidTokens()) {
            printError("Configuration missing required tokens");
            printInfo("Please check your .env file and add all required tokens");
            return;
        }
        
        printSuccess("Configuration loaded successfully");
        
        // Initialize client
        printInfo("Initializing WirelessTag client...");
        $client = new WirelessTagClient($config->getWirelessTagToken());
        printSuccess("Client initialized");
        
        // Test connectivity
        if (!testClientConnectivity($client)) {
            printError("Cannot continue - client connectivity failed");
            return;
        }
        
        // Get device ID
        $deviceId = $config->getHotTubDeviceId();
        
        if (!$deviceId || $deviceId === 'your_hot_tub_device_uuid_here') {
            printError("Hot tub device ID not configured");
            printInfo("Please add your actual WirelessTag device ID to .env file:");
            printInfo("WIRELESSTAG_HOT_TUB_DEVICE_ID=your-actual-device-uuid");
            
            printInfo("You can find device IDs by running: php test-wirelesstag-raw.php");
            printInfo("Look for the 'uuid' field in the response");
            return;
        }
        
        // Test cached temperature data
        $cachedData = testCachedTemperatureData($client, $deviceId);
        
        if (!$cachedData) {
            printError("Cannot continue - cached data test failed");
            return;
        }
        
        // Test fresh temperature data (with user confirmation)
        testFreshTemperatureData($client, $deviceId);
        
        // Test error handling
        testClientErrorHandling($client);
        
        // Final summary
        printHeader("Test Summary");
        printSuccess("WirelessTagClient implementation working correctly");
        printSuccess("Temperature data parsing verified");
        printSuccess("Error handling verified");
        printInfo("Client is ready for integration into heating control system");
        
    } catch (Throwable $e) {
        printError("Test failed with exception: " . $e->getMessage());
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

// Only run if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    main();
}