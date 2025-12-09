<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HotTub\Controllers\EquipmentController;
use HotTub\Services\EnvLoader;
use HotTub\Services\IftttClientFactory;

// CORS headers for frontend
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load environment configuration from .env file
// This enables simple FTP/cPanel deployment: just copy the correct .env file
$loader = new EnvLoader();
$envPath = $loader->getDefaultPath();

if (file_exists($envPath)) {
    // File-based config (preferred for production deployments)
    $config = $loader->load($envPath);
} else {
    // Fallback to system environment (for container/PaaS deployments)
    $config = [
        'APP_ENV' => $_ENV['APP_ENV'] ?? getenv('APP_ENV') ?: 'development',
        'IFTTT_MODE' => $_ENV['IFTTT_MODE'] ?? getenv('IFTTT_MODE') ?: 'auto',
        'IFTTT_WEBHOOK_KEY' => $_ENV['IFTTT_WEBHOOK_KEY'] ?? getenv('IFTTT_WEBHOOK_KEY') ?: null,
    ];
}

// Paths
$logFile = __DIR__ . '/../logs/events.log';

// Create IFTTT client via factory
// The factory uses php://stderr by default for console visibility
$factory = new IftttClientFactory($config, $logFile);
$iftttClient = $factory->create($config['IFTTT_MODE'] ?? 'auto');

// Create controller with IFTTT client
$controller = new EquipmentController($logFile, $iftttClient);

// Route the request
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

$response = match (true) {
    $uri === '/api/health' && $method === 'GET'
        => $controller->health(),
    $uri === '/api/equipment/heater/on' && $method === 'POST'
        => $controller->heaterOn(),
    $uri === '/api/equipment/heater/off' && $method === 'POST'
        => $controller->heaterOff(),
    $uri === '/api/equipment/pump/run' && $method === 'POST'
        => $controller->pumpRun(),
    default => [
        'status' => 404,
        'body' => ['error' => 'Not found'],
    ],
};

http_response_code($response['status']);
echo json_encode($response['body']);
