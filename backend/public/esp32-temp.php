<?php
/**
 * Ultra-lightweight ESP32 temperature endpoint.
 *
 * This file is designed to minimize server load for the frequent (every 5 min)
 * ESP32 temperature pings. It bypasses the full framework:
 *
 * - No autoloader (saves ~50KB of class maps)
 * - No framework overhead (saves instantiating 30+ unused services)
 * - Single purpose: validate API key, store temp, return interval
 *
 * Expected request:
 *   POST /esp32-temp.php
 *   Header: X-ESP32-API-KEY: <key>
 *   Body: {"device_id": "...", "sensors": [{"address": "...", "temp_c": ...}]}
 *
 * Response:
 *   {"status": "ok", "interval_seconds": 300}
 */

declare(strict_types=1);

// Include the handler directly - no autoloader needed
require_once __DIR__ . '/../src/Services/Esp32ThinHandler.php';

use HotTub\Services\Esp32ThinHandler;

// Configuration paths
$envFile = __DIR__ . '/../.env';
$storageFile = __DIR__ . '/../storage/state/esp32-temperature.json';

// Create handler
$handler = new Esp32ThinHandler($storageFile, $envFile);

// Parse request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$apiKey = $_SERVER['HTTP_X_ESP32_API_KEY'] ?? null;
$postData = json_decode(file_get_contents('php://input'), true) ?? [];

// Handle request
$result = $handler->handle($postData, $apiKey, $method);

// Send response
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
