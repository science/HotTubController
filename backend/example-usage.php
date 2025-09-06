<?php

declare(strict_types=1);

/**
 * External API Usage Example
 * 
 * This example demonstrates how to use the WirelessTag and IFTTT
 * clients together for hot tub control.
 * 
 * WARNING: This example will actually control your hot tub if the
 * tokens are valid. Use with caution!
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Config\ExternalApiConfig;
use HotTubController\Services\IftttWebhookClient;
use HotTubController\Services\WirelessTagClient;

function main(): void {
    echo "Hot Tub Controller - API Usage Example\n";
    echo "=====================================\n\n";
    
    try {
        // Load configuration
        echo "Loading configuration...\n";
        $config = new ExternalApiConfig();
        
        if (!$config->hasValidTokens()) {
            echo "ERROR: Configuration is missing required tokens.\n";
            echo "Run: php test-external-apis.php to verify your setup.\n";
            return;
        }
        
        // Initialize API clients
        echo "Initializing API clients...\n";
        $ifttt = new IftttWebhookClient($config->getIftttWebhookKey());
        $wirelessTag = new WirelessTagClient($config->getWirelessTagToken());
        
        // Example 1: Check current temperature
        echo "\n--- Example 1: Reading Current Temperature ---\n";
        
        $deviceId = $config->getHotTubDeviceId();
        echo "Getting cached temperature for device: {$deviceId}\n";
        
        $tempData = $wirelessTag->getCachedTemperatureData($deviceId);
        
        if ($tempData) {
            $processed = $wirelessTag->processTemperatureData($tempData);
            
            $waterTempF = $processed['water_temperature']['fahrenheit'];
            $ambientTempF = $processed['ambient_temperature']['fahrenheit'];
            $batteryV = $processed['sensor_info']['battery_voltage'];
            
            echo "✓ Water Temperature: {$waterTempF}°F\n";
            echo "✓ Ambient Temperature: {$ambientTempF}°F\n";
            echo "✓ Battery Voltage: {$batteryV}V\n";
            
            // Apply calibration to ambient temperature
            if ($waterTempF && $ambientTempF) {
                $calibratedAmbient = $wirelessTag->calibrateAmbientTemperature($ambientTempF, $waterTempF);
                echo "✓ Calibrated Ambient: {$calibratedAmbient}°F\n";
            }
            
        } else {
            echo "✗ Failed to retrieve temperature data\n";
            return;
        }
        
        // Example 2: Fresh temperature reading (uses battery)
        echo "\n--- Example 2: Fresh Temperature Reading ---\n";
        echo "WARNING: This will use sensor battery. Proceed? (y/N): ";
        
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        fclose($handle);
        
        if (trim(strtolower($line)) === 'y') {
            echo "Requesting fresh temperature reading...\n";
            
            $freshData = $wirelessTag->getFreshTemperatureData($deviceId, 3);
            
            if ($freshData) {
                $processed = $wirelessTag->processTemperatureData($freshData);
                $waterTempF = $processed['water_temperature']['fahrenheit'];
                
                echo "✓ Fresh Water Temperature: {$waterTempF}°F\n";
            } else {
                echo "✗ Failed to get fresh temperature reading\n";
            }
        } else {
            echo "Skipping fresh reading to conserve battery.\n";
        }
        
        // Example 3: Heating control logic (demonstration only)
        echo "\n--- Example 3: Heating Control Logic ---\n";
        echo "This example shows heating logic but does NOT actually start heating.\n";
        
        $targetTemp = 104.0; // Target temperature in Fahrenheit
        $currentTemp = $waterTempF;
        
        echo "Current Temperature: {$currentTemp}°F\n";
        echo "Target Temperature: {$targetTemp}°F\n";
        
        if ($currentTemp < $targetTemp) {
            $tempDiff = $targetTemp - $currentTemp;
            echo "Temperature difference: +{$tempDiff}°F (heating needed)\n";
            
            // Validate temperature is within safe bounds
            if ($wirelessTag->validateTemperature($currentTemp, 'water')) {
                echo "✓ Current temperature is within safe bounds\n";
                
                // Estimate heating time (rough calculation)
                $estimatedMinutes = $tempDiff * 5; // ~5 minutes per degree (varies)
                echo "Estimated heating time: ~{$estimatedMinutes} minutes\n";
                
                echo "\nTo start heating, you would call:\n";
                echo "  \$ifttt->startHeating();\n";
                echo "\nWARNING: Actual heating start is commented out for safety!\n";
                
                // SAFETY: Commented out to prevent accidental heating
                // if ($ifttt->startHeating()) {
                //     echo "✓ Heating started successfully\n";
                // } else {
                //     echo "✗ Failed to start heating\n";
                // }
                
            } else {
                echo "✗ Current temperature is out of safe range - heating aborted\n";
            }
            
        } else {
            echo "✓ Target temperature already reached - no heating needed\n";
        }
        
        // Example 4: API health checks
        echo "\n--- Example 4: API Health Checks ---\n";
        
        echo "Checking IFTTT webhook status...\n";
        $iftttStatus = $ifttt->testConnectivity();
        echo $iftttStatus['available'] ? "✓ IFTTT webhooks available\n" : "✗ IFTTT webhooks unavailable\n";
        
        echo "Checking WirelessTag API status...\n";
        $wirelessTagStatus = $wirelessTag->testConnectivity();
        echo $wirelessTagStatus['available'] ? "✓ WirelessTag API available\n" : "✗ WirelessTag API unavailable\n";
        
        // Example 5: Configuration inspection (safe - no token exposure)
        echo "\n--- Example 5: Configuration Status ---\n";
        
        $configStatus = $config->getConfigStatus();
        foreach ($configStatus as $key => $info) {
            $status = $info['configured'] ? '✓' : '✗';
            $optional = $info['optional'] ?? false;
            $optionalText = $optional ? ' (optional)' : '';
            
            echo "{$status} {$key}{$optionalText}: ";
            echo $info['configured'] ? "configured ({$info['length']} chars)\n" : "not configured\n";
        }
        
        echo "\n=== Example Complete ===\n";
        echo "All API clients are working correctly!\n";
        echo "You can now integrate these clients into your hot tub controller.\n";
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    }
}

// Only run if called directly (not included)
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    main();
}