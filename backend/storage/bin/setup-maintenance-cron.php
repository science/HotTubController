#!/usr/bin/env php
<?php
/**
 * Maintenance Cron Setup Script
 *
 * Sets up the log rotation cron job on deploy. This script is idempotent -
 * it will only create the cron job if it doesn't already exist.
 *
 * When creating the cron for the first time, also creates a Healthchecks.io
 * monitoring check (if HEALTHCHECKS_IO_KEY is configured). The check expects
 * the log rotation to run monthly at 3am in the server's timezone.
 *
 * Usage: php setup-maintenance-cron.php [--remove] [--status] [--help]
 *
 * Options:
 *   --remove   Remove the log rotation cron job (and Healthchecks.io check)
 *   --status   Show current status without making changes
 *   --help     Show this help message
 *
 * The cron job runs monthly (1st of each month at 3am) and calls:
 *   POST /api/maintenance/logs/rotate
 *
 * This endpoint compresses logs older than 30 days and deletes
 * compressed logs older than 6 months. On success, it pings
 * Healthchecks.io to signal completion.
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
use HotTub\Services\HealthchecksClientFactory;
use HotTub\Services\TimeConverter;

// Parse command line arguments
$remove = in_array('--remove', $argv);
$status = in_array('--status', $argv);
$help = in_array('--help', $argv) || in_array('-h', $argv);

if ($help) {
    echo <<<HELP
Maintenance Cron Setup Script

Usage: php setup-maintenance-cron.php [--remove] [--status] [--help]

Options:
  --remove   Remove the log rotation cron job (and Healthchecks.io check)
  --status   Show current status without making changes
  --help     Show this help message

The cron job runs monthly (1st of each month at 3am) and calls:
  POST /api/maintenance/logs/rotate

This endpoint compresses logs older than 30 days and deletes
compressed logs older than 6 months.

Healthchecks.io Integration:
  When creating the cron, a Healthchecks.io check is also created
  (if HEALTHCHECKS_IO_KEY is configured). The MaintenanceController
  pings this check on successful log rotation.

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

// Verify API_BASE_URL is configured (required by the cron script)
$apiBaseUrl = $config['API_BASE_URL'] ?? null;

if ($apiBaseUrl === null) {
    echo "Error: API_BASE_URL not configured in .env\n";
    echo "Please add API_BASE_URL=https://your-server.com/path/to/backend/public to .env\n";
    exit(1);
}

// Path to the log rotation cron script
$cronScriptPath = $scriptDir . '/log-rotation-cron.sh';

// Create services
$crontabBackupDir = $storageDir . '/crontab-backups';
$crontabBackupService = new CrontabBackupService($crontabBackupDir);
$crontabAdapter = new CrontabAdapter($crontabBackupService);

// Create Healthchecks.io client (returns NullHealthchecksClient if not configured)
$healthchecksFactory = new HealthchecksClientFactory($config);
$healthchecksClient = $healthchecksFactory->create();

// State file for storing health check ping URL
$healthcheckStateFile = $storageDir . '/state/log-rotation-healthcheck.json';

// Get server timezone (for Healthchecks.io schedule)
$serverTimezone = TimeConverter::getSystemTimezone();

$maintenanceService = new MaintenanceCronService(
    $crontabAdapter,
    $cronScriptPath,
    $healthchecksClient,
    $healthcheckStateFile,
    $serverTimezone
);

$timestamp = date('Y-m-d H:i:s');

if ($status) {
    // Status mode: just show current state
    echo "[$timestamp] Log rotation cron status\n";
    if ($maintenanceService->logRotationCronExists()) {
        echo "  Cron Status: INSTALLED\n";
        $entries = $crontabAdapter->listEntries();
        foreach ($entries as $entry) {
            if (strpos($entry, 'HOTTUB:log-rotation') !== false) {
                echo "  Entry: $entry\n";
            }
        }
    } else {
        echo "  Cron Status: NOT INSTALLED\n";
    }

    // Show health check status
    $pingUrl = $maintenanceService->getHealthcheckPingUrl();
    if ($pingUrl !== null) {
        echo "  Healthcheck: CONFIGURED\n";
        echo "  Ping URL: $pingUrl\n";
    } else {
        echo "  Healthcheck: NOT CONFIGURED\n";
    }
    echo "  Server Timezone: $serverTimezone\n";
    exit(0);
}

if ($remove) {
    // Remove mode
    echo "[$timestamp] Removing log rotation cron...\n";
    $result = $maintenanceService->removeLogRotationCron();
    if ($result['removed']) {
        echo "  Removed cron job\n";
        echo "  Removed Healthchecks.io check (if configured)\n";
        echo "  Success!\n";
    } else {
        echo "  No cron job to remove\n";
    }
    exit(0);
}

// Default: ensure cron exists (idempotent create)
echo "[$timestamp] Setting up log rotation cron...\n";
echo "  API Base URL: $apiBaseUrl\n";
echo "  Server Timezone: $serverTimezone\n";
echo "  Healthchecks.io: " . ($healthchecksClient->isEnabled() ? "ENABLED" : "DISABLED") . "\n";

$result = $maintenanceService->ensureLogRotationCronExists();

if ($result['created']) {
    echo "\n  Created cron job:\n";
    echo "    $result[entry]\n";

    if ($result['healthcheck'] !== null) {
        echo "\n  Created Healthchecks.io check:\n";
        echo "    UUID: {$result['healthcheck']['uuid']}\n";
        echo "    Ping URL: {$result['healthcheck']['ping_url']}\n";
        echo "    Schedule: 0 3 1 * * ($serverTimezone)\n";
    }

    echo "\n  Success! Log rotation will run monthly.\n";
} else {
    echo "\n  Cron job already exists:\n";
    echo "    $result[entry]\n";

    if ($result['healthcheck'] !== null) {
        // Upgrade scenario: cron existed but healthcheck didn't
        echo "\n  Created Healthchecks.io check (upgrade):\n";
        echo "    UUID: {$result['healthcheck']['uuid']}\n";
        echo "    Ping URL: {$result['healthcheck']['ping_url']}\n";
        echo "    Schedule: 0 3 1 * * ($serverTimezone)\n";
        echo "\n  Healthcheck monitoring now enabled.\n";
    } else {
        echo "\n  No changes made.\n";
    }
}
