<?php

declare(strict_types=1);

/**
 * IFTTT Webhook VCR Recording Script
 * 
 * This script safely records IFTTT webhook responses for test replay.
 * 
 * CRITICAL SAFETY FEATURES:
 * - Uses production .env with real API key (required for recording)
 * - Requires explicit user confirmation for each webhook trigger
 * - Includes safety countdown before each API call
 * - Provides abort mechanism at every step
 * - Logs all actions for audit trail
 * 
 * IMPORTANT: This script WILL trigger real hardware!
 * Only run when you are prepared to manually control hot tub equipment.
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Services\IftttWebhookClient;
use VCR\VCR;

// ANSI color codes for terminal output
const COLORS = [
    'green' => "\033[32m",
    'red' => "\033[31m",
    'yellow' => "\033[33m",
    'blue' => "\033[34m",
    'cyan' => "\033[36m",
    'magenta' => "\033[35m",
    'bold' => "\033[1m",
    'reset' => "\033[0m"
];

function colorOutput(string $text, string $color): string {
    return COLORS[$color] . $text . COLORS['reset'];
}

function printHeader(string $title): void {
    $line = str_repeat('=', strlen($title) + 8);
    echo "\n" . colorOutput($line, 'bold') . "\n";
    echo colorOutput("=== {$title} ===", 'bold') . "\n";
    echo colorOutput($line, 'bold') . "\n";
}

function printWarning(string $message): void {
    echo colorOutput("⚠️  WARNING: {$message}", 'yellow') . "\n";
}

function printDanger(string $message): void {
    echo colorOutput("🚨 DANGER: {$message}", 'red') . "\n";
}

function printSuccess(string $message): void {
    echo colorOutput("✅ {$message}", 'green') . "\n";
}

function printInfo(string $message): void {
    echo colorOutput("ℹ️  {$message}", 'cyan') . "\n";
}

function confirmAction(string $action, string $warning = ''): bool {
    if ($warning) {
        printWarning($warning);
    }
    
    echo colorOutput("Are you sure you want to: {$action}? (yes/no): ", 'bold');
    
    // Handle case where STDIN is not available (e.g., during syntax checking)
    $stdin = fopen('php://stdin', 'r');
    if ($stdin === false) {
        echo "STDIN not available - assuming 'no' for safety\n";
        return false;
    }
    
    $response = fgets($stdin);
    if ($response === false) {
        echo "Could not read input - assuming 'no' for safety\n";
        return false;
    }
    
    return strtolower(trim($response)) === 'yes';
}

function safetyCountdown(int $seconds = 5): bool {
    printInfo("Starting safety countdown...");
    
    for ($i = $seconds; $i >= 1; $i--) {
        echo colorOutput("Triggering in {$i} seconds... (press Ctrl+C to abort)\n", 'yellow');
        sleep(1);
    }
    
    // Final confirmation
    echo colorOutput("FINAL CONFIRMATION - Press ENTER to proceed or Ctrl+C to abort: ", 'red');
    $stdin = fopen('php://stdin', 'r');
    if ($stdin !== false) {
        fgets($stdin);
    }
    
    return true;
}

function loadEnvironment(): void {
    // Explicitly load production .env (NOT .env.testing)
    if (!file_exists(__DIR__ . '/.env')) {
        printDanger('Production .env file not found!');
        printInfo('This script requires the production .env file with real IFTTT_WEBHOOK_KEY');
        exit(1);
    }
    
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    if (empty($_ENV['IFTTT_WEBHOOK_KEY'])) {
        printDanger('IFTTT_WEBHOOK_KEY not found in .env file!');
        printInfo('This script requires a valid IFTTT webhook key to record responses');
        exit(1);
    }
    
    printSuccess('Production environment loaded successfully');
}

function setupVCR(string $cassetteName): void {
    // Create cassettes directory if it doesn't exist
    $cassettesDir = __DIR__ . '/tests/cassettes/ifttt';
    if (!is_dir($cassettesDir)) {
        mkdir($cassettesDir, 0755, true);
    }
    
    VCR::configure()
        ->setCassettePath($cassettesDir)
        ->setMode('new_episodes') // Always record new episodes
        ->enableRequestMatchers(['method', 'url'])
        ->enableLibraryHooks(['curl']);
    
    VCR::turnOn();
    VCR::insertCassette($cassetteName);
    
    printInfo("VCR recording to: {$cassettesDir}/{$cassetteName}");
}

function recordWebhook(IftttWebhookClient $client, string $eventName, string $description): bool {
    printHeader("Recording: {$description}");
    
    printWarning("This will trigger REAL HARDWARE!");
    printInfo("Event: {$eventName}");
    printInfo("Description: {$description}");
    
    if (!confirmAction("trigger {$eventName}", "This will affect physical hot tub equipment")) {
        printInfo("Skipped {$eventName}");
        return false;
    }
    
    safetyCountdown(5);
    
    printInfo("Triggering webhook...");
    
    $startTime = microtime(true);
    $success = $client->trigger($eventName);
    $duration = round((microtime(true) - $startTime) * 1000);
    
    if ($success) {
        printSuccess("Webhook triggered successfully in {$duration}ms");
        printInfo("Response has been recorded to VCR cassette");
    } else {
        printWarning("Webhook trigger failed (response still recorded)");
    }
    
    // Give user time to observe hardware behavior
    echo colorOutput("Observing hardware response... (press ENTER when ready to continue): ", 'cyan');
    $stdin = fopen('php://stdin', 'r');
    if ($stdin !== false) {
        fgets($stdin);
    }
    
    return $success;
}

function main(): void {
    printHeader("IFTTT Webhook VCR Recording Script");
    
    printDanger("THIS SCRIPT WILL TRIGGER REAL HOT TUB HARDWARE!");
    printWarning("Only proceed if you are prepared to manually control equipment");
    printInfo("Make sure you have manual overrides available");
    
    if (!confirmAction("proceed with VCR recording", "This will trigger real hardware")) {
        printInfo("Recording cancelled - no hardware will be affected");
        exit(0);
    }
    
    // Load environment
    loadEnvironment();
    
    // Create client with production API key
    $client = new IftttWebhookClient($_ENV['IFTTT_WEBHOOK_KEY']);
    
    // Test connectivity first
    printHeader("Testing IFTTT Connectivity");
    $connectivity = $client->testConnectivity();
    
    if (!$connectivity['available']) {
        printDanger('IFTTT connectivity test failed!');
        printInfo('Error: ' . ($connectivity['error'] ?? 'Unknown error'));
        exit(1);
    }
    
    printSuccess('IFTTT connectivity confirmed');
    
    // Define webhooks to record - CRITICAL: ON webhooks first, then OFF webhooks
    // This prevents interference with async hardware sequences
    $onWebhooks = [
        'hot-tub-heat-on' => [
            'description' => 'Start hot tub heating sequence (pump + heater) - ASYNC PROCESS',
            'cassette' => 'heat-on.yml'
        ],
        'turn-on-hot-tub-ionizer' => [
            'description' => 'Start hot tub ionizer system',
            'cassette' => 'ionizer-on.yml'
        ]
    ];
    
    $offWebhooks = [
        'hot-tub-heat-off' => [
            'description' => 'Stop hot tub heating sequence (heater off, pump cooling cycle)',
            'cassette' => 'heat-off.yml'
        ],
        'turn-off-hot-tub-ionizer' => [
            'description' => 'Stop hot tub ionizer system',
            'cassette' => 'ionizer-off.yml'
        ]
    ];
    
    $recordedCount = 0;
    $totalOnWebhooks = count($onWebhooks);
    $totalOffWebhooks = count($offWebhooks);
    $totalWebhooks = $totalOnWebhooks + $totalOffWebhooks;
    
    // PHASE 1: Record ON webhooks (these start async hardware processes)
    printHeader("PHASE 1: Recording ON Webhooks (Hardware Startup)");
    printDanger("These webhooks start async hardware processes that take time to complete");
    
    foreach ($onWebhooks as $eventName => $info) {
        printInfo("Phase 1 Progress: " . ($recordedCount + 1) . "/{$totalOnWebhooks}");
        
        // Setup VCR for this specific webhook
        setupVCR($info['cassette']);
        
        $success = recordWebhook($client, $eventName, $info['description']);
        
        if ($success) {
            $recordedCount++;
        }
        
        // Turn off VCR after each recording
        VCR::turnOff();
        
        // Extended pause after ON webhooks to allow hardware to complete
        if ($recordedCount < $totalOnWebhooks) {
            printWarning("Allowing time for hardware sequence to complete...");
            echo colorOutput("Press ENTER when ready for next ON webhook: ", 'cyan');
            $stdin = fopen('php://stdin', 'r');
            if ($stdin !== false) {
                fgets($stdin);
            }
        }
    }
    
    // CRITICAL PAUSE: Wait for all hardware sequences to complete
    printHeader("HARDWARE SEQUENCE COMPLETION CHECK");
    printDanger("CRITICAL: All ON webhook hardware sequences must complete before OFF webhooks");
    printWarning("Check that hot tub heating cycle has fully started and stabilized");
    printWarning("Check that ionizer system has fully activated");
    printInfo("Take time to observe and verify all equipment is operating as expected");
    
    echo colorOutput("\nCONFIRM: All hardware sequences completed and stable? (yes/no): ", 'bold');
    $stdin = fopen('php://stdin', 'r');
    $hardwareReady = false;
    if ($stdin !== false) {
        $response = fgets($stdin);
        if ($response !== false) {
            $hardwareReady = (strtolower(trim($response)) === 'yes');
        }
    }
    
    if (!$hardwareReady) {
        printDanger("Hardware not ready - stopping recording for safety");
        printInfo("You can restart the script later when hardware sequences complete");
        exit(0);
    }
    
    // PHASE 2: Record OFF webhooks (these stop hardware processes)
    printHeader("PHASE 2: Recording OFF Webhooks (Hardware Shutdown)");
    printInfo("Now recording shutdown sequences - these should execute immediately");
    
    $offRecordedCount = 0;
    foreach ($offWebhooks as $eventName => $info) {
        printInfo("Phase 2 Progress: " . ($offRecordedCount + 1) . "/{$totalOffWebhooks}");
        
        // Setup VCR for this specific webhook
        setupVCR($info['cassette']);
        
        $success = recordWebhook($client, $eventName, $info['description']);
        
        if ($success) {
            $recordedCount++;
            $offRecordedCount++;
        }
        
        // Turn off VCR after each recording
        VCR::turnOff();
        
        // Normal pause between OFF recordings
        if ($offRecordedCount < $totalOffWebhooks) {
            echo colorOutput("\nPress ENTER to continue to next OFF webhook: ", 'cyan');
            $stdin = fopen('php://stdin', 'r');
            if ($stdin !== false) {
                fgets($stdin);
            }
        }
    }
    
    printHeader("Recording Summary");
    printSuccess("Recorded {$recordedCount}/{$totalWebhooks} webhooks successfully");
    
    if ($recordedCount > 0) {
        printInfo("VCR cassettes saved to: tests/cassettes/ifttt/");
        printInfo("These can now be used for safe test replay");
        
        printWarning("IMPORTANT: Check your hot tub equipment status!");
        printInfo("You may need to manually adjust settings after this recording session");
    }
    
    printSuccess("VCR recording session completed safely");
}

// Safety check - prevent running in test environment
if (isset($_ENV['APP_ENV']) && in_array($_ENV['APP_ENV'], ['testing', 'test'])) {
    printDanger('This script cannot be run in test environment!');
    printInfo('Use the production .env file and ensure APP_ENV is not set to testing');
    exit(1);
}

// Run main function with error handling
try {
    main();
} catch (Exception $e) {
    printDanger('Recording failed: ' . $e->getMessage());
    printInfo('Check the error logs for more details');
    exit(1);
} finally {
    // Ensure VCR is turned off
    VCR::turnOff();
}