<?php
/**
 * ESP32 Temperature Endpoint
 *
 * Ultra-lightweight handler for receiving temperature data from ESP32.
 * Bypasses the full framework for performance on shared hosting.
 *
 * - No autoloader (saves ~50KB of class maps)
 * - No framework overhead (saves instantiating 30+ services)
 * - Single purpose: validate API key, store temp, return interval
 *
 * Expected request:
 *   POST /api/esp32/temperature/
 *   Header: X-ESP32-API-KEY: <key>
 *   Body: {"device_id": "...", "sensors": [{"address": "...", "temp_c": ...}]}
 *
 * Response:
 *   {"status": "ok", "interval_seconds": 60|300}
 *   (60 seconds when heater is on, 300 otherwise)
 */

declare(strict_types=1);

// Include the handler directly - no autoloader needed
require_once __DIR__ . '/../../../../src/Services/Esp32ThinHandler.php';

use HotTub\Services\Esp32ThinHandler;

// Configuration paths (relative to backend root)
$backendRoot = __DIR__ . '/../../../..';
$envFile = $backendRoot . '/.env';
$storageFile = $backendRoot . '/storage/state/esp32-temperature.json';
$equipmentStatusFile = $backendRoot . '/storage/state/equipment-status.json';

// Create handler
$handler = new Esp32ThinHandler($storageFile, $envFile, $equipmentStatusFile);

// Parse request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$apiKey = $_SERVER['HTTP_X_ESP32_API_KEY'] ?? null;
$postData = json_decode(file_get_contents('php://input'), true) ?? [];

// Handle request
$result = $handler->handle($postData, $apiKey, $method);

// Minimal logging for diagnostics (append to separate log)
$esp32LogFile = $backendRoot . '/storage/logs/esp32.log';
$logEntry = sprintf(
    "[%s] %s %d uptime=%s\n",
    date('c'),
    $result['status'] === 200 ? 'OK' : 'ERR',
    $result['status'],
    $postData['uptime_seconds'] ?? '?'
);
@file_put_contents($esp32LogFile, $logEntry, FILE_APPEND | LOCK_EX);

// Send response
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
