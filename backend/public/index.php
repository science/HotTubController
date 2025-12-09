<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HotTub\Controllers\EquipmentController;

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

$logFile = __DIR__ . '/../logs/events.log';
$controller = new EquipmentController($logFile);

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
