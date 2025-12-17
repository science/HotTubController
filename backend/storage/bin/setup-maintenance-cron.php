#!/usr/bin/env php
<?php
/**
 * Maintenance Cron Setup Script
 *
 * Sets up the log rotation cron job on deploy. This script is idempotent -
 * it will only create the cron job if it doesn't already exist.
 *
 * Usage: php setup-maintenance-cron.php [--remove] [--status] [--help]
 *
 * Options:
 *   --remove   Remove the log rotation cron job
 *   --status   Show current status without making changes
 *   --help     Show this help message
 *
 * The cron job runs monthly (1st of each month at 3am) and calls:
 *   POST /api/maintenance/logs/rotate
 *
 * This endpoint compresses logs older than 30 days and deletes
 * compressed logs older than 6 months.
 */

declare(strict_types=1);

// Determine paths relative to this script
$scriptDir = dirname(__FILE__);
$storageDir = dirname($scriptDir);
$backendDir = dirname($storageDir);

require_once $backendDir . '/vendor/autoload.php';

use HotTub\Services\MaintenanceCronService;
use HotTub\Services\CrontabAdapter;
use HotTub\Services\CrontabBackupService;
use HotTub\Services\EnvLoader;

// Parse command line arguments
$remove = in_array('--remove', $argv);
$status = in_array('--status', $argv);
$help = in_array('--help', $argv) || in_array('-h', $argv);

if ($help) {
    echo <<<HELP
Maintenance Cron Setup Script

Usage: php setup-maintenance-cron.php [--remove] [--status] [--help]

Options:
  --remove   Remove the log rotation cron job
  --status   Show current status without making changes
  --help     Show this help message

The cron job runs monthly (1st of each month at 3am) and calls:
  POST /api/maintenance/logs/rotate

This endpoint compresses logs older than 30 days and deletes
compressed logs older than 6 months.

HELP;
    exit(0);
}

// Load environment to get API base URL
$loader = new EnvLoader();
$envPath = $backendDir . '/.env';

if (!file_exists($envPath)) {
    echo "Error: .env file not found at $envPath\n";
    echo "Please configure the environment before running this script.\n";
    exit(1);
}

$config = $loader->load($envPath);

// Get API base URL from config or construct from defaults
$apiBaseUrl = $config['API_BASE_URL'] ?? null;

if ($apiBaseUrl === null) {
    echo "Error: API_BASE_URL not configured in .env\n";
    echo "Please add API_BASE_URL=https://your-server.com/path/to/backend/public to .env\n";
    exit(1);
}

// Create services
$crontabBackupDir = $storageDir . '/crontab-backups';
$crontabBackupService = new CrontabBackupService($crontabBackupDir);
$crontabAdapter = new CrontabAdapter($crontabBackupService);
$maintenanceService = new MaintenanceCronService($crontabAdapter, $apiBaseUrl);

$timestamp = date('Y-m-d H:i:s');

if ($status) {
    // Status mode: just show current state
    echo "[$timestamp] Log rotation cron status\n";
    if ($maintenanceService->logRotationCronExists()) {
        echo "  Status: INSTALLED\n";
        $entries = $crontabAdapter->listEntries();
        foreach ($entries as $entry) {
            if (strpos($entry, 'HOTTUB:log-rotation') !== false) {
                echo "  Entry: $entry\n";
            }
        }
    } else {
        echo "  Status: NOT INSTALLED\n";
    }
    exit(0);
}

if ($remove) {
    // Remove mode
    echo "[$timestamp] Removing log rotation cron...\n";
    $result = $maintenanceService->removeLogRotationCron();
    if ($result['removed']) {
        echo "  Removed successfully\n";
    } else {
        echo "  No cron job to remove\n";
    }
    exit(0);
}

// Default: ensure cron exists (idempotent create)
echo "[$timestamp] Setting up log rotation cron...\n";
echo "  API Base URL: $apiBaseUrl\n";

$result = $maintenanceService->ensureLogRotationCronExists();

if ($result['created']) {
    echo "  Created cron job:\n";
    echo "    $result[entry]\n";
    echo "  Success! Log rotation will run monthly.\n";
} else {
    echo "  Cron job already exists:\n";
    echo "    $result[entry]\n";
    echo "  No changes made.\n";
}
