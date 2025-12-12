#!/usr/bin/env php
<?php
/**
 * Log Rotation CLI Script
 *
 * Rotates log files and crontab backups according to retention policies:
 * - API logs: compress after 7 days, delete after 90 days
 * - Crontab backups: compress after 7 days, delete after 30 days
 *
 * Usage: php rotate-logs.php [--dry-run] [--verbose]
 *
 * Options:
 *   --dry-run   Show what would be done without making changes
 *   --verbose   Show detailed output
 *   --help      Show this help message
 *
 * Recommended cron schedule: daily at 3am
 *   0 3 * * * /usr/bin/php /path/to/storage/bin/rotate-logs.php
 */

declare(strict_types=1);

// Determine paths relative to this script
$scriptDir = dirname(__FILE__);
$storageDir = dirname($scriptDir);
$backendDir = dirname($storageDir);

require_once $backendDir . '/vendor/autoload.php';

use HotTub\Services\LogRotationService;

// Parse command line arguments
$dryRun = in_array('--dry-run', $argv);
$verbose = in_array('--verbose', $argv);
$help = in_array('--help', $argv) || in_array('-h', $argv);

if ($help) {
    echo <<<HELP
Log Rotation CLI Script

Usage: php rotate-logs.php [--dry-run] [--verbose]

Options:
  --dry-run   Show what would be done without making changes
  --verbose   Show detailed output
  --help      Show this help message

Directories processed:
  - storage/logs/*.log        compress after 7 days, delete after 90 days
  - storage/crontab-backups/  compress after 7 days, delete after 30 days

HELP;
    exit(0);
}

// Configuration for rotation
$configs = [
    [
        'name' => 'API Logs',
        'path' => $storageDir . '/logs',
        'pattern' => '*.log*',
        'daysToCompress' => 7,
        'daysToDelete' => 90,
    ],
    [
        'name' => 'Crontab Backups',
        'path' => $storageDir . '/crontab-backups',
        'pattern' => 'crontab-*.txt*',
        'daysToCompress' => 7,
        'daysToDelete' => 30,
    ],
];

$service = new LogRotationService();
$timestamp = date('Y-m-d H:i:s');
$totalCompressed = 0;
$totalDeleted = 0;

echo "[$timestamp] Log rotation " . ($dryRun ? '(DRY RUN)' : 'started') . "\n";

foreach ($configs as $config) {
    $name = $config['name'];
    $path = $config['path'];
    $pattern = $config['pattern'];
    $daysToCompress = $config['daysToCompress'];
    $daysToDelete = $config['daysToDelete'];

    if (!is_dir($path)) {
        if ($verbose) {
            echo "  - $name: directory does not exist ($path)\n";
        }
        continue;
    }

    if ($dryRun) {
        // Dry run: show what would happen
        $files = glob($path . '/' . $pattern) ?: [];
        $now = time();
        $compressThreshold = $now - ($daysToCompress * 24 * 60 * 60);
        $deleteThreshold = $now - ($daysToDelete * 24 * 60 * 60);

        $wouldCompress = [];
        $wouldDelete = [];

        foreach ($files as $file) {
            if (is_dir($file)) {
                continue;
            }
            $mtime = filemtime($file);
            $age = (int)(($now - $mtime) / 86400);

            if ($mtime < $deleteThreshold) {
                $wouldDelete[] = basename($file) . " ({$age} days old)";
            } elseif ($mtime < $compressThreshold && !str_ends_with($file, '.gz')) {
                $wouldCompress[] = basename($file) . " ({$age} days old)";
            }
        }

        echo "  - $name:\n";
        if (empty($wouldCompress) && empty($wouldDelete)) {
            echo "    (no files to process)\n";
        } else {
            foreach ($wouldCompress as $file) {
                echo "    [compress] $file\n";
            }
            foreach ($wouldDelete as $file) {
                echo "    [delete] $file\n";
            }
        }
    } else {
        // Actual rotation
        $result = $service->rotate($path, $pattern, $daysToCompress, $daysToDelete);
        $compressedCount = count($result['compressed']);
        $deletedCount = count($result['deleted']);
        $totalCompressed += $compressedCount;
        $totalDeleted += $deletedCount;

        if ($verbose) {
            echo "  - $name: compressed=$compressedCount, deleted=$deletedCount\n";
            foreach ($result['compressed'] as $file) {
                echo "    [compressed] " . basename($file) . "\n";
            }
            foreach ($result['deleted'] as $file) {
                echo "    [deleted] " . basename($file) . "\n";
            }
        } elseif ($compressedCount > 0 || $deletedCount > 0) {
            echo "  - $name: compressed=$compressedCount, deleted=$deletedCount\n";
        }
    }
}

if (!$dryRun) {
    echo "[$timestamp] Rotation complete: compressed=$totalCompressed, deleted=$totalDeleted\n";
}
