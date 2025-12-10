<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HotTub\Controllers\EquipmentController;
use HotTub\Controllers\AuthController;
use HotTub\Services\EnvLoader;
use HotTub\Services\IftttClientFactory;
use HotTub\Services\AuthService;
use HotTub\Middleware\AuthMiddleware;

// CORS headers for frontend
header('Content-Type: application/json');
$allowedOrigins = ['http://localhost:5173', 'https://misuse.org'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} elseif ($origin === '') {
    // Same-origin requests don't have Origin header
    header('Access-Control-Allow-Origin: https://misuse.org');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Credentials: true');

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

// Create services
$authService = new AuthService($config);
$authMiddleware = new AuthMiddleware($authService);
$authController = new AuthController($authService);

// Create IFTTT client via factory
// The factory uses php://stderr by default for console visibility
$factory = new IftttClientFactory($config, $logFile);
$iftttClient = $factory->create($config['IFTTT_MODE'] ?? 'auto');

// Create controller with IFTTT client
$equipmentController = new EquipmentController($logFile, $iftttClient);

// Route the request
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];

// Strip base path for subdirectory deployments (e.g., /tub/backend/public)
$scriptName = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptName !== '/' && str_starts_with($uri, $scriptName)) {
    $uri = substr($uri, strlen($scriptName)) ?: '/';
}

// Get headers and cookies for auth
$headers = [];
if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
    $headers['Authorization'] = $_SERVER['HTTP_AUTHORIZATION'];
}
$cookies = $_COOKIE;

// Helper to require auth for protected routes
$requireAuth = function () use ($authMiddleware, $headers, $cookies): ?array {
    return $authMiddleware->requireAuth($headers, $cookies);
};

// Helper to get current user
$getUser = function () use ($authMiddleware, $headers, $cookies): ?array {
    return $authMiddleware->authenticate($headers, $cookies);
};

// Route based on path and method
$response = match (true) {
    // Public routes
    $uri === '/api/health' && $method === 'GET'
        => $equipmentController->health(),

    // Auth routes (public)
    $uri === '/api/auth/login' && $method === 'POST'
        => handleLogin($authController),
    $uri === '/api/auth/logout' && $method === 'POST'
        => handleLogout($authController),
    $uri === '/api/auth/me' && $method === 'GET'
        => handleMe($authController, $headers, $cookies),

    // Protected equipment routes
    $uri === '/api/equipment/heater/on' && $method === 'POST'
        => $requireAuth() ?? $equipmentController->heaterOn(),
    $uri === '/api/equipment/heater/off' && $method === 'POST'
        => $requireAuth() ?? $equipmentController->heaterOff(),
    $uri === '/api/equipment/pump/run' && $method === 'POST'
        => $requireAuth() ?? $equipmentController->pumpRun(),

    default => [
        'status' => 404,
        'body' => ['error' => 'Not found'],
    ],
};

http_response_code($response['status']);
echo json_encode($response['body']);

// Auth route handlers
function handleLogin(AuthController $controller): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    $response = $controller->login($username, $password);

    // Set httpOnly cookie if login successful
    if ($response['status'] === 200 && isset($response['body']['token'])) {
        setcookie(
            'auth_token',
            $response['body']['token'],
            [
                'expires' => time() + (24 * 3600),
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
                'secure' => isset($_SERVER['HTTPS']),
            ]
        );
    }

    return $response;
}

function handleLogout(AuthController $controller): array
{
    // Clear the auth cookie
    setcookie(
        'auth_token',
        '',
        [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']),
        ]
    );

    return $controller->logout();
}

function handleMe(AuthController $controller, array $headers, array $cookies): array
{
    // Try Authorization header first, then cookie
    $token = null;
    if (isset($headers['Authorization']) && str_starts_with($headers['Authorization'], 'Bearer ')) {
        $token = substr($headers['Authorization'], 7);
    } elseif (isset($cookies['auth_token'])) {
        $token = $cookies['auth_token'];
    }

    if ($token === null) {
        return [
            'status' => 401,
            'body' => ['error' => 'Authentication required'],
        ];
    }

    return $controller->me($token);
}
