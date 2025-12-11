<?php

declare(strict_types=1);

/**
 * Generate CRON_JWT and add it to .env file.
 *
 * This script is run during deployment to create a long-lived JWT
 * that the cron runner uses to authenticate with the API.
 *
 * Usage: php bin/generate-cron-jwt.php [path/to/.env]
 */

// Autoload dependencies (Firebase JWT)
require_once __DIR__ . '/../vendor/autoload.php';

use Firebase\JWT\JWT;

/**
 * Generate a CRON_JWT and write it to the .env file.
 *
 * @param string $envPath Path to the .env file
 * @return array{success: bool, message: string}
 */
function generateCronJwt(string $envPath): array
{
    if (!file_exists($envPath)) {
        return ['success' => false, 'message' => 'Env file not found: ' . $envPath];
    }

    $envContent = file_get_contents($envPath);

    // Parse JWT_SECRET from .env
    if (!preg_match('/^JWT_SECRET=(.+)$/m', $envContent, $matches)) {
        return ['success' => false, 'message' => 'JWT_SECRET not found in .env'];
    }
    $jwtSecret = trim($matches[1]);

    // Generate JWT with 30-year expiry
    $payload = [
        'iat' => time(),
        'exp' => time() + (30 * 365 * 24 * 60 * 60), // 30 years
        'sub' => 'cron-system',
        'role' => 'admin',
    ];

    $jwt = JWT::encode($payload, $jwtSecret, 'HS256');

    // Update existing CRON_JWT or append new one
    if (preg_match('/^CRON_JWT=.*/m', $envContent)) {
        $envContent = preg_replace('/^CRON_JWT=.*/m', "CRON_JWT=$jwt", $envContent);
    } else {
        $envContent = rtrim($envContent) . "\nCRON_JWT=$jwt\n";
    }

    file_put_contents($envPath, $envContent);

    return ['success' => true, 'message' => 'CRON_JWT generated successfully'];
}

// Run if executed directly (not included/required)
if (php_sapi_name() === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    // Determine .env path
    $envPath = $argv[1] ?? dirname(__DIR__) . '/.env';

    echo "Generating CRON_JWT...\n";
    echo "Env file: $envPath\n";

    $result = generateCronJwt($envPath);

    if ($result['success']) {
        echo "✓ {$result['message']}\n";
        exit(0);
    } else {
        echo "✗ Error: {$result['message']}\n";
        exit(1);
    }
}
