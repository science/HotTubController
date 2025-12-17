#!/usr/bin/env php
<?php

/**
 * Cleanup script for test healthchecks.io checks.
 *
 * This script lists all checks from healthchecks.io and deletes those
 * matching known test patterns. It's safe to run - it only deletes
 * checks with specific test prefixes, never production checks.
 *
 * Usage:
 *   php scripts/cleanup-test-healthchecks.php           # Dry run (list only)
 *   php scripts/cleanup-test-healthchecks.php --delete  # Actually delete
 *
 * Test check patterns:
 *   - poc-test-*      (from HealthchecksIoTest.php)
 *   - live-test-*     (from HealthchecksClientLiveTest.php)
 *   - workflow-test-* (from HealthchecksClientLiveTest.php)
 *   - channel-test-*  (from HealthchecksClientLiveTest.php)
 */

declare(strict_types=1);

// Test check name patterns
const TEST_CHECK_PATTERNS = [
    '/^poc-test-/',
    '/^live-test-/',
    '/^workflow-test-/',
    '/^channel-test-/',
];

/**
 * Check if a check name matches a test pattern.
 */
function isTestCheck(string $name): bool
{
    foreach (TEST_CHECK_PATTERNS as $pattern) {
        if (preg_match($pattern, $name)) {
            return true;
        }
    }
    return false;
}

/**
 * Load API key from config file.
 */
function loadApiKey(): ?string
{
    $envFile = __DIR__ . '/../config/env.production';

    if (!file_exists($envFile)) {
        return null;
    }

    $content = file_get_contents($envFile);
    if (preg_match('/^HEALTHCHECKS_IO_KEY=(.+)$/m', $content, $matches)) {
        return trim($matches[1]);
    }

    return null;
}

/**
 * List all checks from healthchecks.io.
 */
function listChecks(string $apiKey): array
{
    $ch = curl_init('https://healthchecks.io/api/v3/checks/');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'X-Api-Key: ' . $apiKey,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return [];
    }

    $data = json_decode($response, true);
    return $data['checks'] ?? [];
}

/**
 * Delete a check by UUID.
 */
function deleteCheck(string $apiKey, string $uuid): bool
{
    $ch = curl_init('https://healthchecks.io/api/v3/checks/' . $uuid);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_HTTPHEADER => [
            'X-Api-Key: ' . $apiKey,
        ],
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return $httpCode === 200;
}

// Main script
$apiKey = loadApiKey();

if ($apiKey === null) {
    echo "Error: HEALTHCHECKS_IO_KEY not found in config/env.production\n";
    exit(1);
}

$deleteMode = in_array('--delete', $argv);

echo "Healthchecks.io Test Check Cleanup\n";
echo "===================================\n\n";

if (!$deleteMode) {
    echo "DRY RUN MODE - use --delete to actually delete checks\n\n";
}

// List all checks
$checks = listChecks($apiKey);

if (empty($checks)) {
    echo "No checks found.\n";
    exit(0);
}

// Find test checks
$testChecks = [];
foreach ($checks as $check) {
    if (isTestCheck($check['name'])) {
        $testChecks[] = $check;
    }
}

if (empty($testChecks)) {
    echo "No test checks found (checked " . count($checks) . " total checks).\n";
    exit(0);
}

echo "Found " . count($testChecks) . " test check(s) out of " . count($checks) . " total:\n\n";

foreach ($testChecks as $check) {
    $status = $check['status'] ?? 'unknown';
    $created = $check['created'] ?? 'unknown';

    echo "  - {$check['name']}\n";
    echo "    UUID: {$check['uuid']}\n";
    echo "    Status: {$status}\n";
    echo "    Created: {$created}\n";

    if ($deleteMode) {
        $result = deleteCheck($apiKey, $check['uuid']);
        echo "    Deleted: " . ($result ? "YES" : "FAILED") . "\n";

        // Small delay to avoid rate limiting
        usleep(100000); // 100ms
    }

    echo "\n";
}

if ($deleteMode) {
    echo "Cleanup complete.\n";
} else {
    echo "Run with --delete to remove these checks.\n";
}
