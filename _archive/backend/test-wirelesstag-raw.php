<?php

declare(strict_types=1);

/**
 * Raw WirelessTag API Testing Script
 * 
 * This script tests the WirelessTag API directly with raw cURL calls
 * to verify connectivity and understand the exact response structure.
 * 
 * Based on exact implementation from Tasker backup analysis.
 */

require_once __DIR__ . '/vendor/autoload.php';

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

/**
 * Make raw API request to WirelessTag matching Tasker implementation exactly
 */
function makeWirelessTagRequest(string $endpoint, array $payload, string $token): array
{
    $url = 'https://wirelesstag.net/ethClient.asmx' . $endpoint;
    
    // Headers exactly as used in Tasker
    $headers = [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $token,
        'User-Agent: HotTubController-Raw-Test/1.0'
    ];
    
    $jsonPayload = json_encode($payload);
    
    printInfo("Making request to: {$endpoint}");
    printInfo("Headers: " . implode(', ', array_map(fn($h) => preg_replace('/Bearer [a-f0-9-]+/', 'Bearer ***masked***', $h), $headers)));
    printInfo("Payload: {$jsonPayload}");
    
    $startTime = microtime(true);
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $jsonPayload,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_VERBOSE => false
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    $responseTime = round((microtime(true) - $startTime) * 1000);
    
    curl_close($curl);
    
    return [
        'success' => $response !== false && $httpCode >= 200 && $httpCode < 300,
        'http_code' => $httpCode,
        'response' => $response,
        'error' => $error,
        'response_time_ms' => $responseTime
    ];
}

/**
 * Test GetTagList endpoint (cached temperature data)
 */
function testGetTagList(string $token, string $deviceId): void
{
    printHeader("Testing GetTagList (Cached Data)");
    
    $result = makeWirelessTagRequest('/GetTagList', ['id' => $deviceId], $token);
    
    if (!$result['success']) {
        printError("Request failed: HTTP {$result['http_code']}");
        if ($result['error']) {
            printError("cURL Error: {$result['error']}");
        }
        if ($result['response']) {
            echo "Response body: " . $result['response'] . "\n";
        }
        return;
    }
    
    printSuccess("Request successful (HTTP {$result['http_code']}, {$result['response_time_ms']}ms)");
    
    // Parse JSON response
    $data = json_decode($result['response'], true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        printError("Invalid JSON response: " . json_last_error_msg());
        echo "Raw response: " . $result['response'] . "\n";
        return;
    }
    
    printSuccess("JSON parsed successfully");
    
    // Show complete response structure
    echo colorOutput("\nComplete Response Structure:", 'yellow') . "\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    
    // Analyze structure based on Tasker expectations
    if (!isset($data['d']) || !is_array($data['d'])) {
        printError("Expected 'd' array not found in response");
        return;
    }
    
    printSuccess("Found 'd' array with " . count($data['d']) . " device(s)");
    
    // Analyze each device in the response
    foreach ($data['d'] as $index => $device) {
        echo "\n" . colorOutput("Device [{$index}] Analysis:", 'cyan') . "\n";
        
        // Check for expected fields from Tasker analysis
        $expectedFields = [
            'temperature' => 'Water/primary temperature (°C)',
            'cap' => 'Ambient/capacitive temperature (°C)', 
            'batteryVolt' => 'Battery voltage',
            'signaldBm' => 'Signal strength (dBm)',
            'uuid' => 'Device UUID',
            'lastComm' => 'Last communication time'
        ];
        
        foreach ($expectedFields as $field => $description) {
            if (isset($device[$field])) {
                $value = $device[$field];
                
                // Convert temperatures to Fahrenheit for readability
                if ($field === 'temperature' || $field === 'cap') {
                    $fahrenheit = ($value * 1.8) + 32;
                    printSuccess("  {$field}: {$value}°C ({$fahrenheit}°F) - {$description}");
                } else {
                    printSuccess("  {$field}: {$value} - {$description}");
                }
            } else {
                printWarning("  {$field}: NOT FOUND - {$description}");
            }
        }
        
        // Show any additional fields not expected
        $additionalFields = array_diff(array_keys($device), array_keys($expectedFields));
        if (!empty($additionalFields)) {
            echo "  " . colorOutput("Additional fields found:", 'yellow') . " " . implode(', ', $additionalFields) . "\n";
        }
    }
}

/**
 * Test RequestImmediatePostback endpoint (trigger fresh reading)
 */
function testRequestImmediatePostback(string $token, string $deviceId): void
{
    printHeader("Testing RequestImmediatePostback (Fresh Reading Trigger)");
    
    printWarning("This will activate the sensor hardware and use battery power!");
    echo "Continue with fresh reading test? (y/N): ";
    
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    fclose($handle);
    
    if (trim(strtolower($line)) !== 'y') {
        printInfo("Skipping fresh reading test to preserve battery");
        return;
    }
    
    $result = makeWirelessTagRequest('/RequestImmediatePostback', ['id' => $deviceId], $token);
    
    if (!$result['success']) {
        printError("Request failed: HTTP {$result['http_code']}");
        if ($result['error']) {
            printError("cURL Error: {$result['error']}");
        }
        if ($result['response']) {
            echo "Response body: " . $result['response'] . "\n";
        }
        return;
    }
    
    printSuccess("Fresh reading request successful (HTTP {$result['http_code']}, {$result['response_time_ms']}ms)");
    
    // Parse response (might be simple acknowledgment)
    $data = json_decode($result['response'], true);
    
    if (json_last_error() === JSON_ERROR_NONE) {
        echo colorOutput("\nRequestImmediatePostback Response:", 'yellow') . "\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    } else {
        printInfo("Non-JSON response (might be simple acknowledgment): " . $result['response']);
    }
    
    // Wait for sensor to complete reading (as Tasker does)
    printInfo("Waiting 3 seconds for sensor to complete fresh reading...");
    sleep(3);
    
    // Now get the fresh data
    printInfo("Retrieving fresh data with GetTagList...");
    testGetTagList($token, $deviceId);
}

/**
 * Test API connectivity without triggering sensors
 */
function testApiConnectivity(string $token): void
{
    printHeader("Testing API Connectivity");
    
    // Test with a lightweight endpoint that shouldn't affect sensors
    $result = makeWirelessTagRequest('/GetTagManagerSettings', [], $token);
    
    if ($result['success']) {
        printSuccess("API connectivity confirmed (HTTP {$result['http_code']})");
        
        $data = json_decode($result['response'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['d'])) {
            printSuccess("Authentication successful - API responded with valid data");
        } else {
            printInfo("API responded but with unexpected format");
            echo "Response: " . $result['response'] . "\n";
        }
    } else {
        printError("API connectivity failed: HTTP {$result['http_code']}");
        if ($result['error']) {
            printError("Error: {$result['error']}");
        }
    }
}

function main(): void
{
    echo colorOutput("WirelessTag Raw API Test", 'blue') . "\n";
    echo "Testing live API connectivity and response structure\n";
    echo "Based on Tasker backup analysis\n";
    
    // Load environment variables
    if (!file_exists(__DIR__ . '/.env')) {
        printError("No .env file found. Please create one with your WirelessTag token.");
        return;
    }
    
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    $token = $_ENV['WIRELESSTAG_OAUTH_TOKEN'] ?? null;
    
    if (!$token) {
        printError("WIRELESSTAG_OAUTH_TOKEN not found in .env file");
        return;
    }
    
    printSuccess("Loaded token: " . substr($token, 0, 8) . "..." . substr($token, -4));
    
    // Test basic API connectivity first
    testApiConnectivity($token);
    
    // For now, we'll test with a placeholder device ID
    // In a real test, you'd want to configure this
    $deviceId = $_ENV['WIRELESSTAG_HOT_TUB_DEVICE_ID'] ?? null;
    
    if (!$deviceId) {
        printWarning("WIRELESSTAG_HOT_TUB_DEVICE_ID not configured in .env");
        printInfo("Please add your device ID to test GetTagList and RequestImmediatePostback");
        return;
    }
    
    printSuccess("Using device ID: " . $deviceId);
    
    // Test cached data retrieval
    testGetTagList($token, $deviceId);
    
    // Test fresh reading (with user confirmation)
    testRequestImmediatePostback($token, $deviceId);
    
    echo "\n" . colorOutput("=== Test Complete ===", 'blue') . "\n";
    printInfo("Raw API testing completed successfully!");
    printInfo("Next step: Verify WirelessTagClient implementation matches this behavior");
}

// Run the test
try {
    main();
} catch (Throwable $e) {
    printError("FATAL ERROR: " . $e->getMessage());
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}