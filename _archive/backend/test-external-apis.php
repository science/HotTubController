<?php

declare(strict_types=1);

/**
 * External API Configuration and Connectivity Test
 * 
 * This script tests the external API configuration and connectivity
 * for WirelessTag and IFTTT services. Use this to verify that your
 * OAuth tokens and API keys are properly configured.
 * 
 * Usage: php test-external-apis.php [--detailed]
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Config\ExternalApiConfig;
use HotTubController\Services\IftttWebhookClient;
use HotTubController\Services\WirelessTagClient;

// ANSI color codes for terminal output
const COLORS = [
    'green' => "\033[32m",
    'red' => "\033[31m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'reset' => "\033[0m"
];

function colorOutput(string $text, string $color): string {
    return COLORS[$color] . $text . COLORS['reset'];
}

function printHeader(string $title): void {
    echo "\n" . colorOutput("=== {$title} ===", 'blue') . "\n";
}

function printResult(string $test, bool $success, string $details = ''): void {
    $status = $success ? colorOutput('✓ PASS', 'green') : colorOutput('✗ FAIL', 'red');
    echo "{$status} {$test}";
    
    if ($details) {
        echo " - {$details}";
    }
    
    echo "\n";
}

function printWarning(string $message): void {
    echo colorOutput("⚠ WARNING: {$message}", 'yellow') . "\n";
}

function main(): void {
    $detailed = in_array('--detailed', $ARGV ?? []);
    
    echo colorOutput("Hot Tub Controller - External API Test", 'blue') . "\n";
    echo "Testing configuration and connectivity...\n";
    
    // Test 1: Configuration Loading
    printHeader("Configuration Test");
    
    try {
        $config = new ExternalApiConfig();
        printResult('Configuration loaded', true);
        
        $status = $config->getConfigStatus();
        
        foreach ($status as $key => $info) {
            $optional = $info['optional'] ?? false;
            $configured = $info['configured'];
            $optionalText = $optional ? ' (optional)' : '';
            
            if ($configured) {
                $details = "length: {$info['length']}, preview: {$info['preview']}";
                printResult("{$key}{$optionalText}", true, $details);
            } else {
                printResult("{$key}{$optionalText}", !$optional, $optional ? 'not configured' : 'MISSING');
            }
        }
        
        $hasValidTokens = $config->hasValidTokens();
        printResult('All required tokens present', $hasValidTokens);
        
        if (!$hasValidTokens) {
            echo colorOutput("\nERROR: Missing required configuration. Please check your .env file.\n", 'red');
            return;
        }
        
        // Validate WirelessTag token format
        $validToken = $config->validateWirelessTagToken();
        printResult('WirelessTag token format', $validToken, $validToken ? 'appears valid' : 'too short or invalid');
        
    } catch (Exception $e) {
        printResult('Configuration loaded', false, $e->getMessage());
        echo colorOutput("\nFailed to load configuration. Ensure .env file exists and is readable.\n", 'red');
        return;
    }
    
    // Test 2: IFTTT Webhook Client
    printHeader("IFTTT Webhook Test");
    
    try {
        $iftttClient = new IftttWebhookClient($config->getIftttWebhookKey());
        printResult('IFTTT client created', true);
        
        // Test connectivity (note: this creates a test webhook call)
        printWarning('Testing IFTTT connectivity - this will trigger a test webhook');
        
        $iftttTest = $iftttClient->testConnectivity();
        printResult(
            'IFTTT connectivity', 
            $iftttTest['available'], 
            $iftttTest['available'] 
                ? "responded in {$iftttTest['response_time_ms']}ms" 
                : ($iftttTest['error'] ?? 'connection failed')
        );
        
        if ($detailed && $iftttTest['available']) {
            echo "  └─ Response time: {$iftttTest['response_time_ms']}ms\n";
            echo "  └─ Tested at: {$iftttTest['tested_at']}\n";
        }
        
    } catch (Exception $e) {
        printResult('IFTTT client', false, $e->getMessage());
    }
    
    // Test 3: WirelessTag API Client
    printHeader("WirelessTag API Test");
    
    try {
        $wirelessTagClient = new WirelessTagClient($config->getWirelessTagToken());
        printResult('WirelessTag client created', true);
        
        // Test connectivity and authentication
        $wirelessTagTest = $wirelessTagClient->testConnectivity();
        printResult(
            'WirelessTag connectivity', 
            $wirelessTagTest['available'], 
            $wirelessTagTest['available'] 
                ? "authenticated, responded in {$wirelessTagTest['response_time_ms']}ms" 
                : ($wirelessTagTest['error'] ?? 'connection failed')
        );
        
        if ($detailed && $wirelessTagTest['available']) {
            echo "  └─ Authentication: " . ($wirelessTagTest['authenticated'] ? 'valid' : 'failed') . "\n";
            echo "  └─ Response time: {$wirelessTagTest['response_time_ms']}ms\n";
            echo "  └─ Tested at: {$wirelessTagTest['tested_at']}\n";
        }
        
        // Test temperature data retrieval (if connectivity successful)
        if ($wirelessTagTest['available']) {
            $deviceId = $config->getHotTubDeviceId();
            
            printWarning("Testing temperature data retrieval for device: {$deviceId}");
            
            $tempData = $wirelessTagClient->getCachedTemperatureData($deviceId);
            
            if ($tempData) {
                printResult('Temperature data retrieval', true, 'cached data retrieved');
                
                $processed = $wirelessTagClient->processTemperatureData($tempData);
                
                if ($detailed) {
                    echo "  └─ Water temp: {$processed['water_temperature']['fahrenheit']}°F ({$processed['water_temperature']['celsius']}°C)\n";
                    echo "  └─ Ambient temp: {$processed['ambient_temperature']['fahrenheit']}°F ({$processed['ambient_temperature']['celsius']}°C)\n";
                    echo "  └─ Battery: {$processed['sensor_info']['battery_voltage']}V\n";
                    echo "  └─ Signal: {$processed['sensor_info']['signal_strength_dbm']}dBm\n";
                }
                
                // Validate temperature readings
                $waterTemp = $processed['water_temperature']['fahrenheit'];
                $ambientTemp = $processed['ambient_temperature']['fahrenheit'];
                
                if ($waterTemp !== null) {
                    $validWater = $wirelessTagClient->validateTemperature($waterTemp, 'water');
                    printResult('Water temperature validation', $validWater, $validWater ? "{$waterTemp}°F is reasonable" : "{$waterTemp}°F is out of range");
                }
                
                if ($ambientTemp !== null) {
                    $validAmbient = $wirelessTagClient->validateTemperature($ambientTemp, 'ambient');
                    printResult('Ambient temperature validation', $validAmbient, $validAmbient ? "{$ambientTemp}°F is reasonable" : "{$ambientTemp}°F is out of range");
                }
                
            } else {
                printResult('Temperature data retrieval', false, 'no data returned');
            }
        }
        
    } catch (Exception $e) {
        printResult('WirelessTag client', false, $e->getMessage());
    }
    
    // Test 4: Integration Test
    printHeader("Integration Test Summary");
    
    $configOk = $config->hasValidTokens();
    $iftttOk = isset($iftttTest) && $iftttTest['available'];
    $wirelessTagOk = isset($wirelessTagTest) && $wirelessTagTest['available'];
    
    printResult('Configuration', $configOk);
    printResult('IFTTT Webhooks', $iftttOk);
    printResult('WirelessTag API', $wirelessTagOk);
    
    $overallStatus = $configOk && $iftttOk && $wirelessTagOk;
    printResult('Overall System', $overallStatus, $overallStatus ? 'Ready for integration' : 'Issues need resolution');
    
    if ($overallStatus) {
        echo "\n" . colorOutput("🎉 All systems ready! You can now integrate the external APIs.", 'green') . "\n";
    } else {
        echo "\n" . colorOutput("❌ Some issues need to be resolved before integration.", 'red') . "\n";
        
        if (!$configOk) {
            echo "  → Check your .env file for missing or invalid tokens\n";
        }
        if (!$iftttOk) {
            echo "  → Verify your IFTTT webhook key and webhook configurations\n";
        }
        if (!$wirelessTagOk) {
            echo "  → Check your WirelessTag OAuth token and account access\n";
        }
    }
    
    // Usage instructions
    echo "\n" . colorOutput("Next Steps:", 'blue') . "\n";
    echo "1. If any tests failed, update your .env file with correct values\n";
    echo "2. Run this test again to verify fixes: php test-external-apis.php\n";
    echo "3. For detailed output, use: php test-external-apis.php --detailed\n";
    echo "4. Once all tests pass, integrate the clients into your hot tub controller\n";
}

// Run the test
try {
    main();
} catch (Throwable $e) {
    echo colorOutput("FATAL ERROR: " . $e->getMessage(), 'red') . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}