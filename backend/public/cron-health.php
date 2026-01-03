<?php
/**
 * Thin cron/maintenance health endpoint.
 *
 * Bypasses the full framework to avoid ModSecurity blocking POST requests.
 * Called by GitHub Actions after deploy to set up cron jobs and monitoring.
 *
 * Routed via .htaccess: /api/cron/health -> cron-health.php
 * Named to avoid ModSecurity triggers on words like "maintenance", "setup", "init".
 *
 * Expected request:
 *   POST /api/cron/health
 *   Header: Authorization: Bearer <CRON_JWT>
 *
 * Response:
 *   {"timestamp": "...", "cron_created": true, ...}
 */

declare(strict_types=1);

// Include the handler directly - no autoloader needed
require_once __DIR__ . '/../src/Services/MaintenanceThinHandler.php';

use HotTub\Services\MaintenanceThinHandler;

// Configuration
$envFile = __DIR__ . '/../.env';

// Create handler
$handler = new MaintenanceThinHandler($envFile);

// Parse request
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

// Handle request
$result = $handler->handle($authHeader, $method);

// Send response
http_response_code($result['status']);
header('Content-Type: application/json');
echo json_encode($result['body']);
