<?php

declare(strict_types=1);

/**
 * WirelessTag Client VCR Test
 * 
 * This script demonstrates how to use PHP-VCR to record HTTP interactions
 * with the WirelessTag API, allowing for deterministic test replay.
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Config\ExternalApiConfig;
use HotTubController\Services\WirelessTagClient;
use VCR\VCR;

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
 * Configure VCR for recording/replaying HTTP interactions
 */
function setupVCR(string $cassetteName, bool $recordNew = false): void
{
    // Create cassettes directory if it doesn't exist
    $cassettesDir = __DIR__ . '/tests/cassettes';
    if (!is_dir($cassettesDir)) {
        mkdir($cassettesDir, 0755, true);
    }
    
    VCR::configure()
        ->setCassettePath($cassettesDir)
        ->setMode($recordNew ? 'new_episodes' : 'once') // 'once' for replay, 'new_episodes' for recording
        ->enableRequestMatchers(['method', 'url', 'body'])
        ->enableLibraryHooks(['curl']) // Only enable cURL hook, not SOAP
        ->addRequestMatcher('custom_headers', function($first, $second) {
            // Match requests based on method, URL, and body, but ignore Authorization header for privacy
            return true;
        });
    
    VCR::turnOn();
    VCR::insertCassette($cassetteName);
    
    printInfo("VCR configured - Cassette: {$cassetteName} (Mode: " . ($recordNew ? 'recording' : 'replay') . ")");
}

/**
 * Test cached temperature data with VCR recording
 */
function testCachedDataWithVCR(WirelessTagClient $client, string $deviceId, bool $recordNew = false): void
{
    printHeader("Testing Cached Data (VCR)");
    
    setupVCR('wirelesstag-cached-data.yml', $recordNew);
    
    try {
        $tempData = $client->getCachedTemperatureData($deviceId);
        
        if ($tempData) {
            printSuccess("Cached data retrieved successfully");
            
            $processed = $client->processTemperatureData($tempData, 0);
            
            $waterTempF = $processed['water_temperature']['fahrenheit'];
            $ambientTempF = $processed['ambient_temperature']['fahrenheit'];
            
            printInfo("Water Temperature: {$waterTempF}°F");
            printInfo("Ambient Temperature: {$ambientTempF}°F");
            printInfo("Battery Voltage: {$processed['sensor_info']['battery_voltage']}V");
            
            // Validate temperatures
            if ($client->validateTemperature($waterTempF, 'water')) {
                printSuccess("Water temperature within valid range");
            } else {
                printWarning("Water temperature outside valid range");
            }
            
        } else {
            printError("Failed to retrieve cached data");
        }
        
    } catch (Exception $e) {
        printError("Test failed: " . $e->getMessage());
    } finally {
        VCR::eject();
        VCR::turnOff();
    }
}

/**
 * Test connectivity with VCR recording
 */
function testConnectivityWithVCR(WirelessTagClient $client, bool $recordNew = false): void
{
    printHeader("Testing Connectivity (VCR)");
    
    setupVCR('wirelesstag-connectivity.yml', $recordNew);
    
    try {
        $result = $client->testConnectivity();
        
        if ($result['available']) {
            printSuccess("Connectivity test passed");
            printInfo("Response time: {$result['response_time_ms']}ms");
            printInfo("Authenticated: " . ($result['authenticated'] ? 'Yes' : 'No'));
        } else {
            printError("Connectivity test failed");
            if ($result['error']) {
                printError("Error: {$result['error']}");
            }
        }
        
    } catch (Exception $e) {
        printError("Connectivity test failed: " . $e->getMessage());
    } finally {
        VCR::eject();
        VCR::turnOff();
    }
}

/**
 * Demonstrate VCR cassette content filtering (remove sensitive data)
 */
function sanitizeCassettes(): void
{
    printHeader("Sanitizing VCR Cassettes");
    
    $cassettesDir = __DIR__ . '/tests/cassettes';
    $cassettes = glob($cassettesDir . '/*.yml');
    
    foreach ($cassettes as $cassette) {
        printInfo("Processing cassette: " . basename($cassette));
        
        $content = file_get_contents($cassette);
        
        // Remove or mask sensitive data
        $content = preg_replace('/Authorization: Bearer [a-f0-9-]+/', 'Authorization: Bearer ***MASKED***', $content);
        $content = preg_replace('/"id":\s*"[a-f0-9-]+"/', '"id": "***DEVICE-ID-MASKED***"', $content);
        
        file_put_contents($cassette, $content);
        printSuccess("Sanitized: " . basename($cassette));
    }
}

function main(): void
{
    echo colorOutput("WirelessTag Client VCR Test", 'blue') . "\n";
    echo "Recording HTTP interactions for deterministic test replay\n";
    
    global $argv;
    $recordNew = in_array('--record', $argv ?? []);
    if ($recordNew) {
        printWarning("Recording mode enabled - will capture new HTTP interactions");
    } else {
        printInfo("Replay mode - using existing cassettes");
    }
    
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
            printInfo("Please run php test-wirelesstag-client.php first to verify your setup");
            return;
        }
        
        // Initialize client
        printInfo("Initializing WirelessTag client...");
        $client = new WirelessTagClient($config->getWirelessTagToken());
        
        // Get device ID
        $deviceId = $config->getHotTubDeviceId();
        
        // Test connectivity with VCR
        testConnectivityWithVCR($client, $recordNew);
        
        // Test cached data with VCR
        testCachedDataWithVCR($client, $deviceId, $recordNew);
        
        // Sanitize cassettes to remove sensitive data
        if ($recordNew) {
            sanitizeCassettes();
        }
        
        printHeader("VCR Test Complete");
        printSuccess("HTTP interactions " . ($recordNew ? "recorded" : "replayed") . " successfully");
        printInfo("Cassettes saved in: tests/cassettes/");
        printInfo("Run with --record flag to capture new interactions");
        
    } catch (Throwable $e) {
        printError("Test failed: " . $e->getMessage());
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
        exit(1);
    }
}

// Only run if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    main();
}