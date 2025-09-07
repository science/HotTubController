<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

use HotTubController\Services\CronManager;
use HotTubController\Services\CronSecurityManager;
use HotTubController\Domain\Heating\CronJobBuilder;
use DateTime;

echo "\n";
echo "🌊 Hot Tub Controller - Cron System Demonstration\n";
echo "================================================\n\n";

try {
    // Initialize services
    echo "Initializing cron management services...\n";
    $securityManager = new CronSecurityManager();
    $cronManager = new CronManager();
    $cronJobBuilder = new CronJobBuilder(null, 'https://your-domain.com');
    
    // Check API key status
    echo "\n📋 API Key Status:\n";
    $keyInfo = $securityManager->getApiKeyInfo();
    
    if ($keyInfo['exists']) {
        echo "  ✓ API key exists\n";
        echo "  ✓ Valid format: " . ($keyInfo['valid_format'] ? 'Yes' : 'No') . "\n";
        echo "  ✓ Permissions: {$keyInfo['permissions']}\n";
        echo "  ✓ Size: {$keyInfo['size']} bytes\n";
        if (isset($keyInfo['key_preview'])) {
            echo "  ✓ Preview: {$keyInfo['key_preview']}\n";
        }
    } else {
        echo "  ⚠️  No API key found - run setup-cron-api-key.php first\n";
        exit(1);
    }
    
    // Demonstrate cron job creation
    echo "\n🔧 Demonstration: Creating Self-Deleting Cron Jobs\n";
    echo "------------------------------------------------\n";
    
    // 1. Schedule a heating start event for 10 minutes from now
    $startTime = (new DateTime())->modify('+10 minutes');
    echo "\n1. Creating START heating cron for {$startTime->format('Y-m-d H:i:s')}...\n";
    
    $startCronConfig = $cronJobBuilder->buildStartHeatingCron(
        $startTime,
        'demo-event-' . time(),
        104.0
    );
    
    echo "   Config file: " . basename($startCronConfig['config_file']) . "\n";
    echo "   Cron ID: {$startCronConfig['cron_id']}\n";
    
    // Show what the cron entry would look like
    $cronExpression = sprintf(
        '%d %d %d %d *',
        (int) $startTime->format('i'),
        (int) $startTime->format('H'),
        (int) $startTime->format('d'),
        (int) $startTime->format('n')
    );
    
    $wrapperScript = __DIR__ . '/storage/bin/cron-wrapper.sh';
    $cronCommand = "'{$wrapperScript}' '{$startCronConfig['cron_id']}' '{$startCronConfig['config_file']}' >/dev/null 2>&1";
    
    echo "   Cron entry would be:\n";
    echo "   {$cronExpression} {$cronCommand} # {$startCronConfig['cron_id']}\n";
    
    // 2. Create a monitoring cron for 15 minutes from now
    $monitorTime = (new DateTime())->modify('+15 minutes');
    echo "\n2. Creating MONITOR temperature cron for {$monitorTime->format('Y-m-d H:i:s')}...\n";
    
    $monitorCronConfig = $cronJobBuilder->buildMonitorTempCron(
        $monitorTime,
        'demo-cycle-456',
        'demo-monitor-' . time()
    );
    
    echo "   Config file: " . basename($monitorCronConfig['config_file']) . "\n";
    echo "   Cron ID: {$monitorCronConfig['cron_id']}\n";
    
    // 3. Show config file contents
    echo "\n📄 Sample Curl Config File Contents:\n";
    echo "------------------------------------\n";
    $configContent = file_get_contents($startCronConfig['config_file']);
    echo $configContent;
    
    // 4. Demonstrate self-deletion mechanism
    echo "\n🔄 Self-Deletion Mechanism:\n";
    echo "---------------------------\n";
    echo "The cron-wrapper.sh script performs these steps:\n";
    echo "  1. Execute: curl --config {config_file}\n";
    echo "  2. Remove cron: (crontab -l | grep -v \"# {cron_id}\") | crontab -\n";
    echo "  3. Cleanup: rm -f {config_file}\n";
    echo "  4. Log operation and exit\n";
    
    // 5. Demonstrate heating time calculations
    echo "\n🧮 Heating Time Calculations:\n";
    echo "-----------------------------\n";
    
    $scenarios = [
        ['current' => 85.0, 'target' => 104.0, 'description' => 'Cold water heating'],
        ['current' => 98.0, 'target' => 104.0, 'description' => 'Warm water heating'],
        ['current' => 102.0, 'target' => 104.0, 'description' => 'Near-target heating'],
        ['current' => 104.0, 'target' => 104.0, 'description' => 'Already at target'],
    ];
    
    foreach ($scenarios as $scenario) {
        $estimatedMinutes = $cronJobBuilder->calculateHeatingTime($scenario['current'], $scenario['target']);
        echo sprintf("  %s: %.1f°F → %.1f°F = %d minutes\n",
            $scenario['description'],
            $scenario['current'],
            $scenario['target'],
            $estimatedMinutes
        );
    }
    
    // 6. Demonstrate monitoring intervals
    echo "\n⏰ Dynamic Monitoring Intervals:\n";
    echo "-------------------------------\n";
    
    $baseTime = new DateTime();
    $monitoringScenarios = [
        ['current' => 85.0, 'target' => 104.0, 'precision' => false, 'description' => 'Far from target (coarse)'],
        ['current' => 100.0, 'target' => 104.0, 'precision' => false, 'description' => 'Medium distance'],
        ['current' => 103.0, 'target' => 104.0, 'precision' => false, 'description' => 'Close to target (auto-precision)'],
        ['current' => 103.5, 'target' => 104.0, 'precision' => true, 'description' => 'Precision mode'],
    ];
    
    foreach ($monitoringScenarios as $scenario) {
        $nextCheck = $cronJobBuilder->calculateNextCheckTime(
            $scenario['current'],
            $scenario['target'],
            $baseTime,
            $scenario['precision']
        );
        
        $interval = $nextCheck->getTimestamp() - $baseTime->getTimestamp();
        $intervalDescription = $interval < 60 ? "{$interval} seconds" : round($interval / 60) . " minutes";
        
        echo sprintf("  %s: Next check in %s\n",
            $scenario['description'],
            $intervalDescription
        );
    }
    
    // 7. Safety features
    echo "\n🛡️  Safety Features:\n";
    echo "-------------------\n";
    echo "  ✓ Self-deleting crons prevent orphaned jobs\n";
    echo "  ✓ API key authentication prevents unauthorized access\n";
    echo "  ✓ Config files have restrictive permissions (0600)\n";
    echo "  ✓ Backup cleanup methods for orphaned crons\n";
    echo "  ✓ Temperature limits and heating duration limits\n";
    echo "  ✓ Audit logging for all operations\n";
    echo "  ✓ Let's Encrypt cron preservation\n";
    
    // 8. Clean up demo files
    echo "\n🧹 Cleaning up demo config files...\n";
    $cronJobBuilder->cleanupConfigFile($startCronConfig['config_file']);
    $cronJobBuilder->cleanupConfigFile($monitorCronConfig['config_file']);
    echo "  ✓ Demo config files removed\n";
    
    echo "\n✅ Cron System Demonstration Complete!\n";
    echo "\nNext Steps:\n";
    echo "----------\n";
    echo "1. Test the heating control API endpoints\n";
    echo "2. Create your first scheduled heating event\n";
    echo "3. Monitor the self-deleting cron behavior\n";
    echo "4. Check the audit logs in storage/logs/\n";
    
} catch (Exception $e) {
    echo "\n❌ Demo failed: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\n";