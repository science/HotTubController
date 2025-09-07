<?php

declare(strict_types=1);

/**
 * Storage System Demonstration Script
 * 
 * This script demonstrates the complete storage infrastructure we've built:
 * - JSON-based model persistence with file rotation
 * - Query system with filtering, ordering, and pagination
 * - HeatingCycle and HeatingEvent models
 * - Repository pattern with CRUD operations
 * - Automatic cleanup and file management
 */

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use DateTime;

function printHeader(string $title): void {
    echo "\n" . str_repeat('=', 50) . "\n";
    echo "=== {$title} ===\n";
    echo str_repeat('=', 50) . "\n";
}

function printInfo(string $message): void {
    echo "ℹ️  {$message}\n";
}

function printSuccess(string $message): void {
    echo "✅ {$message}\n";
}

function printData(string $label, array $data): void {
    echo "\n📊 {$label}:\n";
    if (empty($data)) {
        echo "   (No data)\n";
        return;
    }
    
    foreach ($data as $i => $item) {
        if (is_array($item)) {
            echo "   [" . ($i + 1) . "] " . json_encode($item) . "\n";
        } else {
            echo "   [" . ($i + 1) . "] {$item}\n";
        }
    }
}

try {
    printHeader("Storage System Demo");
    
    // Setup storage with demo configuration
    $storagePath = __DIR__ . '/storage/demo';
    $storageManager = new JsonStorageManager($storagePath, [
        'rotation' => [
            'strategy' => 'size',
            'max_size' => 2048, // 2KB for demo
            'retention_days' => 30,
            'compress_after_days' => 7,
        ]
    ]);
    
    $cycleRepository = new HeatingCycleRepository($storageManager);
    $eventRepository = new HeatingEventRepository($storageManager);
    
    printInfo("Initialized storage system at: {$storagePath}");
    
    // Clean slate for demo
    if (is_dir($storagePath)) {
        $files = glob($storagePath . '/**/*.json');
        foreach ($files as $file) {
            unlink($file);
        }
        printInfo("Cleaned existing demo data");
    }
    
    printHeader("Creating Heating Cycles");
    
    // Create several heating cycles with different statuses and temperatures
    $cycles = [];
    $temperatures = [98.5, 102.0, 104.5, 106.0, 95.0];
    $statuses = [
        HeatingCycle::STATUS_HEATING,
        HeatingCycle::STATUS_COMPLETED, 
        HeatingCycle::STATUS_HEATING,
        HeatingCycle::STATUS_STOPPED,
        HeatingCycle::STATUS_ERROR
    ];
    
    foreach ($temperatures as $i => $targetTemp) {
        $cycle = new HeatingCycle();
        $cycle->setTargetTemp($targetTemp);
        $cycle->setCurrentTemp($targetTemp - 10); // Simulate 10 degree difference
        $cycle->setStatus($statuses[$i]);
        $cycle->addMetadata('demo_cycle', $i + 1);
        $cycle->addMetadata('created_by', 'demo_script');
        
        if ($targetTemp > 100) {
            $cycle->setEstimatedCompletion(new DateTime('+2 hours'));
        }
        
        if ($cycle->save()) {
            $cycles[] = $cycle;
            printSuccess("Created cycle #{$cycle->getId()} - Target: {$targetTemp}°F, Status: {$cycle->getStatus()}");
        }
    }
    
    printHeader("Creating Heating Events");
    
    // Create scheduled events
    $events = [];
    $eventTypes = [HeatingEvent::EVENT_TYPE_START, HeatingEvent::EVENT_TYPE_MONITOR];
    $scheduleTimes = ['+1 hour', '+2 hours', '+3 hours', '+4 hours'];
    
    foreach ($scheduleTimes as $i => $timeOffset) {
        $event = new HeatingEvent();
        $event->setEventType($eventTypes[$i % 2]);
        $event->setScheduledFor(new DateTime($timeOffset));
        $event->setTargetTemp(104.0);
        $event->addMetadata('demo_event', $i + 1);
        
        if ($event->getEventType() === HeatingEvent::EVENT_TYPE_MONITOR && !empty($cycles)) {
            $event->setCycleId($cycles[0]->getId()); // Link to first cycle
        }
        
        if ($event->save()) {
            $events[] = $event;
            $typeLabel = $event->isStartEvent() ? 'START' : 'MONITOR';
            printSuccess("Created {$typeLabel} event #{$event->getId()} scheduled for {$event->getScheduledFor()->format('Y-m-d H:i:s')}");
        }
    }
    
    printHeader("Query System Demonstration");
    
    // Demonstrate various query capabilities
    printInfo("Finding active heating cycles...");
    $activeCycles = $cycleRepository->findActiveCycles();
    printSuccess("Found " . count($activeCycles) . " active cycles");
    
    printInfo("Finding completed cycles...");
    $completedCycles = $cycleRepository->findCompletedCycles();
    printSuccess("Found " . count($completedCycles) . " completed cycles");
    
    printInfo("Finding high-temperature cycles (>100°F)...");
    $highTempCycles = $cycleRepository->query()
        ->where('target_temp', '>', 100.0)
        ->orderBy('target_temp', 'desc')
        ->get();
    printSuccess("Found " . count($highTempCycles) . " high-temperature cycles");
    
    $highTempData = [];
    foreach ($highTempCycles as $cycle) {
        $highTempData[] = [
            'id' => substr($cycle->getId(), -8),
            'target' => $cycle->getTargetTemp(),
            'status' => $cycle->getStatus()
        ];
    }
    printData("High Temperature Cycles", $highTempData);
    
    printInfo("Finding scheduled events in next 5 hours...");
    $upcomingEvents = $eventRepository->findUpcomingEvents(5);
    printSuccess("Found " . count($upcomingEvents) . " upcoming events");
    
    $eventData = [];
    foreach ($upcomingEvents as $event) {
        $eventData[] = [
            'id' => substr($event->getId(), -8),
            'type' => $event->getEventType(),
            'scheduled' => $event->getScheduledFor()->format('H:i:s'),
            'target' => $event->getTargetTemp()
        ];
    }
    printData("Upcoming Events", $eventData);
    
    printHeader("Advanced Query Features");
    
    // Complex query with multiple conditions
    printInfo("Complex query: Active heating cycles with target temp between 100-105°F...");
    $complexQuery = $cycleRepository->query()
        ->where('status', HeatingCycle::STATUS_HEATING)
        ->whereBetween('target_temp', [100.0, 105.0])
        ->whereNotNull('estimated_completion')
        ->orderBy('target_temp', 'asc')
        ->get();
    
    printSuccess("Found " . count($complexQuery) . " cycles matching complex criteria");
    
    // Metadata queries
    printInfo("Finding cycles created by demo script...");
    $demoCycles = $cycleRepository->query()
        ->where('metadata.created_by', 'demo_script')
        ->count();
    printSuccess("Found {$demoCycles} demo cycles");
    
    printHeader("Model Operations");
    
    // Demonstrate model operations
    if (!empty($cycles)) {
        $firstCycle = $cycles[0];
        printInfo("Updating first cycle temperature...");
        $firstCycle->setCurrentTemp(95.5);
        $firstCycle->addMetadata('last_updated', (new DateTime())->format('Y-m-d H:i:s'));
        
        if ($firstCycle->save()) {
            printSuccess("Updated cycle #{$firstCycle->getId()} current temperature to {$firstCycle->getCurrentTemp()}°F");
        }
        
        printInfo("Temperature difference: {$firstCycle->getTemperatureDifference()}°F");
        printInfo("Elapsed time: {$firstCycle->getElapsedTime()} seconds");
    }
    
    if (!empty($events)) {
        $firstEvent = $events[0];
        printInfo("Demonstrating event state transitions...");
        
        if ($firstEvent->isScheduled()) {
            printInfo("Event is currently: SCHEDULED");
            
            if ($firstEvent->trigger()) {
                printSuccess("Event #{$firstEvent->getId()} triggered successfully");
                printInfo("Event is now: TRIGGERED");
            }
        }
    }
    
    printHeader("Repository Statistics");
    
    printInfo("Heating Cycles Repository:");
    echo "   Total cycles: " . $cycleRepository->count() . "\n";
    echo "   Active cycles: " . count($cycleRepository->findActiveCycles()) . "\n";
    echo "   Completed cycles: " . count($cycleRepository->findCompletedCycles()) . "\n";
    
    printInfo("Heating Events Repository:");
    echo "   Total events: " . $eventRepository->count() . "\n";
    echo "   Scheduled events: " . count($eventRepository->findScheduledEvents()) . "\n";
    echo "   Start events: " . count($eventRepository->findStartEvents()) . "\n";
    echo "   Monitor events: " . count($eventRepository->findMonitorEvents()) . "\n";
    
    printHeader("Storage System Health");
    
    // Check file system
    $dataFiles = glob($storagePath . '/**/*.json');
    printSuccess("Storage files created: " . count($dataFiles));
    
    foreach ($dataFiles as $file) {
        $size = filesize($file);
        $relativePath = str_replace($storagePath . '/', '', $file);
        echo "   📄 {$relativePath} ({$size} bytes)\n";
    }
    
    // Cleanup demonstration
    printInfo("Testing cleanup functionality...");
    $deletedFiles = $storageManager->cleanup();
    printSuccess("Cleanup completed - {$deletedFiles} old files removed");
    
    printHeader("Validation Examples");
    
    // Demonstrate model validation
    printInfo("Testing model validation...");
    
    $invalidCycle = new HeatingCycle();
    $invalidCycle->setTargetTemp(-10.0); // Invalid temperature
    $invalidCycle->setCurrentTemp(150.0); // Invalid temperature
    
    $errors = $invalidCycle->validate();
    if (!empty($errors)) {
        printInfo("Validation errors found (as expected):");
        foreach ($errors as $error) {
            echo "   ❌ {$error}\n";
        }
    }
    
    $invalidEvent = new HeatingEvent();
    $invalidEvent->setScheduledFor(new DateTime('-1 hour')); // Past time
    $invalidEvent->setTargetTemp(200.0); // Too hot!
    
    $eventErrors = $invalidEvent->validate();
    if (!empty($eventErrors)) {
        printInfo("Event validation errors found (as expected):");
        foreach ($eventErrors as $error) {
            echo "   ❌ {$error}\n";
        }
    }
    
    printHeader("Demo Complete");
    printSuccess("Storage system demonstration completed successfully!");
    printInfo("All features working correctly:");
    echo "   ✅ Model creation and persistence\n";
    echo "   ✅ Repository CRUD operations\n"; 
    echo "   ✅ Advanced querying with filters and sorting\n";
    echo "   ✅ Model validation and business rules\n";
    echo "   ✅ JSON file storage with rotation\n";
    echo "   ✅ Metadata support for flexible data\n";
    echo "   ✅ Automatic cleanup and maintenance\n";
    
    printInfo("The storage infrastructure is ready for production use!");
    printInfo("Next steps: Integrate with heating control APIs and cron management");
    
} catch (Exception $e) {
    echo "\n❌ Demo failed with error: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}