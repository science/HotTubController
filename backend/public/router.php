<?php
/**
 * Router script for PHP built-in development server.
 *
 * Usage: php -S localhost:8080 router.php
 *
 * This mimics the .htaccess routing behavior for environments that don't
 * support .htaccess (like PHP built-in server used in integration tests).
 *
 * Note: PHP's built-in server sets SCRIPT_NAME to the requested path when using
 * a router script, which breaks index.php's path stripping logic. We fix this
 * by setting SCRIPT_NAME to /index.php before requiring it.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ESP32 temperature endpoint - serve directly from its index.php
if ($uri === '/api/esp32/temperature' || $uri === '/api/esp32/temperature/') {
    require __DIR__ . '/api/esp32/temperature/index.php';
    return true;
}

// Cron health endpoint - serve directly
if ($uri === '/api/cron/health') {
    require __DIR__ . '/cron-health.php';
    return true;
}

// Check if request is for a real file
$path = __DIR__ . $uri;
if (is_file($path)) {
    return false; // Let PHP built-in server handle static files
}

// Route everything else to index.php
// Fix SCRIPT_NAME for index.php's path stripping logic
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';
return true;
