<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use HotTub\Controllers\EquipmentController;
use HotTub\Controllers\BlindsController;
use HotTub\Controllers\AuthController;
use HotTub\Controllers\ScheduleController;
use HotTub\Controllers\UserController;
use HotTub\Controllers\TemperatureController;
use HotTub\Controllers\MaintenanceController;
use HotTub\Controllers\Esp32TemperatureController;
use HotTub\Controllers\Esp32SensorConfigController;
use HotTub\Services\TemperatureStateService;
use HotTub\Services\Esp32TemperatureService;
use HotTub\Services\Esp32SensorConfigService;
use HotTub\Services\Esp32CalibratedTemperatureService;
use HotTub\Services\LogRotationService;
use HotTub\Services\EnvLoader;
use HotTub\Services\EquipmentStatusService;
use HotTub\Services\IftttClientFactory;
use HotTub\Services\WirelessTagClientFactory;
use HotTub\Services\AuthService;
use HotTub\Services\UserRepositoryFactory;
use HotTub\Services\SchedulerService;
use HotTub\Services\CrontabAdapter;
use HotTub\Services\CrontabBackupService;
use HotTub\Services\HealthchecksClientFactory;
use HotTub\Services\RequestLogger;
use HotTub\Middleware\AuthMiddleware;
use HotTub\Middleware\CorsMiddleware;
use HotTub\Routing\Router;

// Start timing for request logging
$requestStartTime = microtime(true);

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
$apiLogFile = __DIR__ . '/../storage/logs/api.log';
$usersFile = __DIR__ . '/../storage/users/users.json';

// Create request logger for API audit trail
$requestLogger = new RequestLogger($apiLogFile);

// Create user repository with bootstrap logic
$userRepoFactory = new UserRepositoryFactory($usersFile, $config);
$userRepository = $userRepoFactory->create();

// Create services
$authService = new AuthService($userRepository, $config);
$authMiddleware = new AuthMiddleware($authService);
$authController = new AuthController($authService);
$userController = new UserController($userRepository);

// Create IFTTT client via factory (uses EXTERNAL_API_MODE from config)
$factory = new IftttClientFactory($config, $logFile);
$iftttClient = $factory->create();

// Create equipment status service for tracking heater/pump state
$equipmentStatusFile = __DIR__ . '/../storage/state/equipment-status.json';
$equipmentStatusService = new EquipmentStatusService($equipmentStatusFile);

// Create controller with IFTTT client and status service
$equipmentController = new EquipmentController($logFile, $iftttClient, $equipmentStatusService);

// Create blinds controller (optional feature, isolated by config)
$blindsController = new BlindsController($logFile, $iftttClient, $config);

// Create WirelessTag client and temperature controller with state service
// (uses EXTERNAL_API_MODE from config)
$wirelessTagFactory = new WirelessTagClientFactory($config);
$wirelessTagClient = $wirelessTagFactory->create();
$temperatureStateFile = __DIR__ . '/../storage/temperature_state.json';
$temperatureStateService = new TemperatureStateService($temperatureStateFile);
// Create ESP32 temperature service and controller
// (must be created before TemperatureController so we can inject it)
$esp32TemperatureFile = __DIR__ . '/../storage/state/esp32-temperature.json';
$esp32ConfigFile = __DIR__ . '/../storage/state/esp32-sensor-config.json';
$esp32TemperatureService = new Esp32TemperatureService($esp32TemperatureFile);
$esp32SensorConfigService = new Esp32SensorConfigService($esp32ConfigFile);
$esp32CalibratedService = new Esp32CalibratedTemperatureService($esp32TemperatureService, $esp32SensorConfigService);
$esp32ApiKey = $config['ESP32_API_KEY'] ?? '';
$esp32TemperatureController = new Esp32TemperatureController($esp32TemperatureService, $esp32ApiKey);
$esp32SensorConfigController = new Esp32SensorConfigController($esp32SensorConfigService, $esp32TemperatureService);

// Create temperature controller with ESP32 fallback support
$temperatureController = new TemperatureController(
    $wirelessTagClient,
    $wirelessTagFactory,
    $temperatureStateService,
    $esp32CalibratedService
);

// Create scheduler service and controller
$jobsDir = __DIR__ . '/../storage/scheduled-jobs';
$cronRunnerPath = __DIR__ . '/../storage/bin/cron-runner.sh';
$crontabBackupDir = __DIR__ . '/../storage/crontab-backups';

// Construct API base URL from request
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$apiBaseUrl = $protocol . '://' . $host . $scriptDir;

// Create crontab adapter with backup service
$crontabBackupService = new CrontabBackupService($crontabBackupDir);
$crontabAdapter = new CrontabAdapter($crontabBackupService);

// Create Healthchecks.io client for job monitoring (feature flag: disabled if no API key)
$healthchecksFactory = new HealthchecksClientFactory($config);
$healthchecksClient = $healthchecksFactory->create();

$schedulerService = new SchedulerService(
    $jobsDir,
    $cronRunnerPath,
    $apiBaseUrl,
    $crontabAdapter,
    null, // TimeConverter (use default)
    $healthchecksClient
);
$scheduleController = new ScheduleController($schedulerService);

// Create maintenance controller for log rotation
// Loads ping URL from state file (created by deploy script)
$logsDir = __DIR__ . '/../storage/logs';
$logRotationService = new LogRotationService();
$logRotationHealthcheckStateFile = __DIR__ . '/../storage/state/log-rotation-healthcheck.json';
$logRotationPingUrl = null;
if (file_exists($logRotationHealthcheckStateFile)) {
    $logRotationState = json_decode(file_get_contents($logRotationHealthcheckStateFile), true);
    $logRotationPingUrl = $logRotationState['ping_url'] ?? null;
}
$maintenanceController = new MaintenanceController(
    $logRotationService,
    $logsDir,
    $healthchecksClient,
    $logRotationPingUrl
);

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
$requireAdmin = fn() => $authMiddleware->requireAdmin($headers, $cookies);

// Configure routes
$router = new Router();

// Public routes
$router->get('/api/health', function() use ($equipmentController, $blindsController) {
    $response = $equipmentController->health();
    $response['body']['blindsEnabled'] = $blindsController->isEnabled();
    return $response;
});

// Auth routes
$router->post('/api/auth/login', fn() => handleLogin($authController, $config));
$router->post('/api/auth/logout', fn() => handleLogout($authController));
$router->get('/api/auth/me', fn() => handleMe($authController, $headers, $cookies));

// Protected equipment routes (with auth middleware)
$router->post('/api/equipment/heater/on', fn() => $equipmentController->heaterOn(), $requireAuth);
$router->post('/api/equipment/heater/off', fn() => $equipmentController->heaterOff(), $requireAuth);
$router->post('/api/equipment/pump/run', fn() => $equipmentController->pumpRun(), $requireAuth);

// Protected blinds routes (optional feature - returns 404 if not enabled)
$router->post('/api/blinds/open', fn() => $blindsController->open(), $requireAuth);
$router->post('/api/blinds/close', fn() => $blindsController->close(), $requireAuth);

// Protected temperature routes (with auth middleware)
$router->get('/api/temperature', fn() => $temperatureController->get(), $requireAuth);
$router->get('/api/temperature/all', fn() => $temperatureController->getAll(), $requireAuth);
$router->post('/api/temperature/refresh', fn() => $temperatureController->refresh(), $requireAuth);

// ESP32 temperature endpoint (uses API key auth, not JWT)
$router->post('/api/esp32/temperature', fn() => handleEsp32Temperature($esp32TemperatureController));

// ESP32 sensor configuration endpoints (require auth)
$router->get('/api/esp32/sensors', fn() => $esp32SensorConfigController->list(), $requireAuth);
$router->put('/api/esp32/sensors/{address}', fn($params) => handleEsp32SensorUpdate($esp32SensorConfigController, $params['address']), $requireAuth);

// Protected schedule routes (with auth middleware)
$router->post('/api/schedule', fn() => handleScheduleCreate($scheduleController), $requireAuth);
$router->get('/api/schedule', fn() => $scheduleController->list(), $requireAuth);
$router->delete('/api/schedule/{id}', fn($params) => $scheduleController->cancel($params['id']), $requireAuth);

// Admin-only user management routes
$router->get('/api/users', fn() => $userController->list(), $requireAdmin);
$router->post('/api/users', fn() => handleUserCreate($userController), $requireAdmin);
$router->delete('/api/users/{username}', fn($params) => $userController->delete($params['username']), $requireAdmin);
$router->put('/api/users/{username}/password', fn($params) => handleUserPasswordUpdate($userController, $params['username']), $requireAdmin);

// Protected maintenance routes (called by cron with CRON_JWT)
$router->post('/api/maintenance/logs/rotate', fn() => $maintenanceController->rotateLogs(), $requireAuth);

// Dispatch request
$response = $router->dispatch($method, $uri);

http_response_code($response['status']);
echo json_encode($response['body']);

// Log the request
$responseTimeMs = (microtime(true) - $requestStartTime) * 1000;
$clientIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$errorMsg = ($response['status'] >= 400 && isset($response['body']['error']))
    ? $response['body']['error']
    : null;

// Try to get authenticated username from auth middleware (if available)
// Note: authenticate() returns user array on success, null on failure (no exceptions)
$loggedUsername = null;
$user = $authMiddleware->authenticate($headers, $cookies);
if ($user !== null && isset($user['sub'])) {
    $loggedUsername = $user['sub'];
}

$requestLogger->log(
    method: $method,
    uri: $uri,
    statusCode: $response['status'],
    ip: $clientIp,
    responseTimeMs: $responseTimeMs,
    username: $loggedUsername,
    error: $errorMsg
);

// Auth route handlers
function handleLogin(AuthController $controller, array $config): array
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
                'expires' => time() + ((int)($config['JWT_EXPIRY_HOURS'] ?? 24) * 3600),
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

function handleUserCreate(UserController $controller): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    return $controller->create($input);
}

function handleUserPasswordUpdate(UserController $controller, string $username): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    return $controller->updatePassword($username, $input);
}

function handleEsp32Temperature(Esp32TemperatureController $controller): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $apiKey = $_SERVER['HTTP_X_ESP32_API_KEY'] ?? null;
    return $controller->receive($input, $apiKey);
}

function handleEsp32SensorUpdate(Esp32SensorConfigController $controller, string $address): array
{
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    return $controller->update($address, $input);
}
