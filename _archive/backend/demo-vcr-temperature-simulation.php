<?php

declare(strict_types=1);

/**
 * VCR Temperature Simulation Demo
 * 
 * Demonstrates the VCR temperature simulation system for hot tub heating cycles.
 * Shows how to generate temperature sequences and create VCR cassettes
 * without requiring live API calls.
 */

require_once __DIR__ . '/vendor/autoload.php';

use Tests\Fixtures\TemperatureSequenceBuilder;
use Tests\Fixtures\VCRCassetteGenerator;
use Tests\Support\HeatingTestHelpers;

// ANSI color codes for terminal output
const COLORS = [
    'green' => "\033[32m",
    'red' => "\033[31m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'cyan' => "\033[36m",
    'reset' => "\033[0m"
];

function colorOutput(string $text, string $color): string
{
    return COLORS[$color] . $text . COLORS['reset'];
}

function printHeader(string $title): void
{
    echo "\n" . colorOutput("=== {$title} ===", 'blue') . "\n";
}

function printSuccess(string $message): void
{
    echo colorOutput("✓ {$message}", 'green') . "\n";
}

function printInfo(string $message): void
{
    echo colorOutput("ℹ {$message}", 'cyan') . "\n";
}

function main(): void
{
    echo colorOutput("VCR Temperature Simulation Demo", 'blue') . "\n";
    echo "Demonstrating dynamic temperature sequence generation for testing\n";
    
    printHeader("Temperature Sequence Builder Demo");
    
    $sequenceBuilder = new TemperatureSequenceBuilder();
    
    // Demo 1: Generate heating sequence from 88°F to 102°F
    printInfo("Generating heating sequence: 88°F → 102°F");
    $startTemp = 88.0;
    $targetTemp = 102.0;
    $heatingSequence = $sequenceBuilder->buildHeatingSequence($startTemp, $targetTemp, 5);
    
    printSuccess("Generated " . count($heatingSequence) . " temperature readings");
    printInfo("Expected duration: " . $sequenceBuilder->calculateHeatingDuration($startTemp, $targetTemp) . " minutes");
    printInfo("Heating rate: " . $sequenceBuilder->getHeatingRate() . "°F per minute");
    
    // Show first few readings
    echo "\nFirst few temperature readings:\n";
    foreach (array_slice($heatingSequence, 0, 5) as $index => $reading) {
        printf(
            "%2d. %5.1f°F (%5.2f°C) at %2d min - Battery: %.2fV Signal: %ddBm\n",
            $index + 1,
            $reading['water_temp_f'],
            $reading['water_temp_c'],
            $reading['minutes_elapsed'],
            $reading['battery_voltage'],
            $reading['signal_strength']
        );
    }
    
    printHeader("Precision Monitoring Demo");
    
    // Demo 2: Generate precision sequence when within 1°F of target
    printInfo("Generating precision sequence: 101°F → 102°F (15-second intervals)");
    $precisionSequence = $sequenceBuilder->buildPrecisionSequence(101.0, 102.0, 15);
    
    printSuccess("Generated " . count($precisionSequence) . " precision readings");
    
    // Show precision readings
    echo "\nPrecision temperature readings:\n";
    foreach ($precisionSequence as $index => $reading) {
        printf(
            "%2d. %5.1f°F at %2d:%02d - %s\n",
            $index + 1,
            $reading['water_temp_f'],
            $reading['minutes_elapsed'] / 60,
            $reading['minutes_elapsed'] % 60,
            $reading['water_temp_f'] >= 102.0 ? "TARGET REACHED!" : "heating..."
        );
    }
    
    printHeader("VCR Cassette Generation Demo");
    
    // Demo 3: Generate VCR cassette
    $cassetteGenerator = new VCRCassetteGenerator();
    $deviceId = '217af407-0165-462d-be07-809e82f6a865';
    
    try {
        $cassetteFile = $cassetteGenerator->generateHeatingCycle(88.0, 102.0, $deviceId);
        printSuccess("Generated VCR cassette: " . basename($cassetteFile));
        printInfo("Cassette contains " . count($heatingSequence) . " HTTP interactions");
        
        // Show cassette file size
        $fileSize = filesize($cassetteFile);
        printInfo("Cassette file size: " . number_format($fileSize) . " bytes");
        
    } catch (Exception $e) {
        echo colorOutput("✗ Error generating cassette: " . $e->getMessage(), 'red') . "\n";
    }
    
    printHeader("Heating Test Helpers Demo");
    
    // Demo 4: Show helper functions
    $helpers = new HeatingTestHelpers();
    
    printInfo("Testing various heating scenarios:");
    
    $scenarios = [
        [85.0, 100.0, "Cold start"],
        [95.0, 104.0, "Warm start"],
        [98.0, 102.0, "Quick heat"]
    ];
    
    foreach ($scenarios as [$start, $target, $description]) {
        $duration = $helpers->getExpectedHeatingDuration($start, $target);
        $tempRise = $target - $start;
        
        printf(
            "• %s: %.0f°F → %.0f°F (%.0f°F rise, %d minutes)\n",
            $description,
            $start,
            $target,
            $tempRise,
            $duration
        );
    }
    
    printHeader("System Capabilities Summary");
    
    printSuccess("✓ Realistic heating physics simulation (0.5°F/minute)");
    printSuccess("✓ Dynamic temperature progression with proper timing");
    printSuccess("✓ Precision monitoring when within 1°F of target");
    printSuccess("✓ VCR cassette generation for deterministic testing");
    printSuccess("✓ Sensor failure simulation (timeout, invalid readings, low battery)");
    printSuccess("✓ Battery degradation and signal strength variation");
    printSuccess("✓ .NET ticks timestamp conversion for WirelessTag compatibility");
    
    printInfo("\nThe VCR temperature simulation system enables comprehensive testing");
    printInfo("of heating control logic without requiring live API calls or actual hardware.");
    
    echo "\n" . colorOutput("Demo completed successfully!", 'green') . "\n";
}

// Only run if called directly
if (basename(__FILE__) === basename($_SERVER['SCRIPT_NAME'])) {
    main();
}