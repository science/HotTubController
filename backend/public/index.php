<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HotTub\Controllers\EquipmentController;
use HotTub\Controllers\AuthController;
use HotTub\Controllers\ScheduleController;
use HotTub\Services\EnvLoader;
use HotTub\Services\IftttClientFactory;
use HotTub\Services\AuthService;
use HotTub\Services\SchedulerService;
use HotTub\Services\CrontabAdapter;
use HotTub\Middleware\AuthMiddleware;
use HotTub\Middleware\CorsMiddleware;
use HotTub\Routing\Router;

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

// CORS middleware
$allowedOrigins = ['http://localhost:5173', 'https://misuse.org'];
$cors = new CorsMiddleware($allowedOrigins);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

// Handle CORS headers and preflight
$corsResult = $cors->handle($origin, $method);
if ($corsResult !== null) {
    http_response_code($corsResult['status']);
    exit;
}

// Paths
$logFile = __DIR__ . '/../logs/events.log';

// Create services
$authService = new AuthService($config);
$authMiddleware = new AuthMiddleware($authService);
$authController = new AuthController($authService);

// Create IFTTT client via factory
$factory = new IftttClientFactory($config, $logFile);
$iftttClient = $factory->create($config['IFTTT_MODE'] ?? 'auto');

// Create controller with IFTTT client
$equipmentController = new EquipmentController($logFile, $iftttClient);

// Create scheduler service and controller
$jobsDir = __DIR__ . '/../storage/scheduled-jobs';
$cronRunnerPath = __DIR__ . '/../storage/bin/cron-runner.sh';

// Construct API base URL from request
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$apiBaseUrl = $protocol . '://' . $host . $scriptDir;

$schedulerService = new SchedulerService(
    $jobsDir,
    $cronRunnerPath,
    $apiBaseUrl,
    new CrontabAdapter()
);
$scheduleController = new ScheduleController($schedulerService);

// Parse request URI
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

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

// Auth middleware for protected routes
$requireAuth = fn() => $authMiddleware->requireAuth($headers, $cookies);

// Configure routes
$router = new Router();

// Public routes
$router->get('/api/health', fn() => $equipmentController->health());

// Auth routes
$router->post('/api/auth/login', fn() => handleLogin($authController));
$router->post('/api/auth/logout', fn() => handleLogout($authController));
$router->get('/api/auth/me', fn() => handleMe($authController, $headers, $cookies));

// Protected equipment routes (with auth middleware)
$router->post('/api/equipment/heater/on', fn() => $equipmentController->heaterOn(), $requireAuth);
$router->post('/api/equipment/heater/off', fn() => $equipmentController->heaterOff(), $requireAuth);
$router->post('/api/equipment/pump/run', fn() => $equipmentController->pumpRun(), $requireAuth);

// Protected schedule routes (with auth middleware)
$router->post('/api/schedule', fn() => handleScheduleCreate($scheduleController), $requireAuth);
$router->get('/api/schedule', fn() => $scheduleController->list(), $requireAuth);
$router->delete('/api/schedule/{id}', fn($params) => $scheduleController->cancel($params['id']), $requireAuth);

// Dispatch request
$response = $router->dispatch($method, $uri);

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

function handleScheduleCreate(ScheduleController $controller): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    return $controller->create($input);
}
