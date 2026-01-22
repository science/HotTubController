<?php
/**
 * ESP32 Firmware Download Endpoint
 *
 * Serves firmware binary files for HTTP OTA updates.
 * Uses same API key authentication as temperature endpoint.
 *
 * GET /api/esp32/firmware/download
 * Header: X-ESP32-API-KEY: <key>
 *
 * Response: Binary firmware file with appropriate headers
 */

declare(strict_types=1);

// Configuration paths (relative to backend root)
$backendRoot = __DIR__ . '/../../../../..';
$envFile = $backendRoot . '/.env';
$firmwareConfigFile = $backendRoot . '/storage/firmware/config.json';
$firmwareDir = $backendRoot . '/storage/firmware';

// Only allow GET requests
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Load API key from .env
$expectedApiKey = null;
if (file_exists($envFile)) {
    $content = file_get_contents($envFile);
    if (preg_match('/^ESP32_API_KEY=(.+)$/m', $content, $matches)) {
        $expectedApiKey = trim($matches[1]);
    }
}

if ($expectedApiKey === null) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Server configuration error']);
    exit;
}

// Validate API key
$apiKey = $_SERVER['HTTP_X_ESP32_API_KEY'] ?? null;
if ($apiKey === null || $apiKey !== $expectedApiKey) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid or missing API key']);
    exit;
}

// Load firmware config
if (!file_exists($firmwareConfigFile)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No firmware available']);
    exit;
}

$configContent = file_get_contents($firmwareConfigFile);
$config = json_decode($configContent, true);

if (!is_array($config) || !isset($config['filename'])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Invalid firmware configuration']);
    exit;
}

// Get firmware file path
$firmwarePath = $firmwareDir . '/' . $config['filename'];

if (!file_exists($firmwarePath)) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Firmware file not found']);
    exit;
}

// Serve the firmware file
$fileSize = filesize($firmwarePath);
$filename = basename($config['filename']);

header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $fileSize);
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Add version header for ESP32 to verify
if (isset($config['version'])) {
    header('X-Firmware-Version: ' . $config['version']);
}

// Stream the file
readfile($firmwarePath);
