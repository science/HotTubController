<?php

declare(strict_types=1);

use Monolog\Logger;

// Load environment variables
// Use .env.testing if APP_ENV is set to testing, otherwise use .env
$envFile = '.env';
if (isset($_ENV['APP_ENV']) && $_ENV['APP_ENV'] === 'testing' && file_exists(__DIR__ . '/../.env.testing')) {
    $envFile = '.env.testing';
} elseif (file_exists(__DIR__ . '/../.env.testing') && !file_exists(__DIR__ . '/../.env')) {
    // Fallback to .env.testing if .env doesn't exist (useful for CI/testing environments)
    $envFile = '.env.testing';
}

if (file_exists(__DIR__ . '/../' . $envFile)) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../', $envFile);
    $dotenv->load();
}

return [
    // Environment
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    
    // Logging
    'logger' => [
        'name' => 'hot-tub-controller',
        'path' => $_ENV['LOG_PATH'] ?? __DIR__ . '/../storage/logs/app.log',
        'level' => match ($_ENV['LOG_LEVEL'] ?? 'info') {
            'debug' => Logger::DEBUG,
            'info' => Logger::INFO,
            'notice' => Logger::NOTICE,
            'warning' => Logger::WARNING,
            'error' => Logger::ERROR,
            'critical' => Logger::CRITICAL,
            'alert' => Logger::ALERT,
            'emergency' => Logger::EMERGENCY,
            default => Logger::INFO,
        },
    ],
    
    // Authentication
    'auth' => [
        'master_password_hash' => $_ENV['MASTER_PASSWORD_HASH'] ?? '$2y$12$LQv3c1yqBWVHxkd0LQ4mFOArhSB2cxgE5k4dJ2K8Z3cUcF9OcGi4W',
    ],
    
    // CORS
    'cors' => [
        'allowed_origins' => json_decode($_ENV['CORS_ALLOWED_ORIGINS'] ?? '["*"]', true),
        'allowed_methods' => json_decode($_ENV['CORS_ALLOWED_METHODS'] ?? '["GET", "POST", "PUT", "DELETE", "OPTIONS"]', true),
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-Requested-With'],
        'max_age' => 86400, // 24 hours
    ],
    
    // Rate Limiting
    'rate_limit' => [
        'requests_per_minute' => (int) ($_ENV['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 60),
        'requests_per_hour' => (int) ($_ENV['RATE_LIMIT_REQUESTS_PER_HOUR'] ?? 1000),
    ],
    
    // Storage
    'storage' => [
        'token_file' => $_ENV['TOKEN_STORAGE_PATH'] ?? __DIR__ . '/../storage/tokens.json',
    ],
    
    // Performance Monitoring
    'performance' => [
        'enable_logging' => filter_var($_ENV['PERFORMANCE_LOGGING'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'slow_threshold_ms' => (int) ($_ENV['SLOW_THRESHOLD_MS'] ?? 1000),
    ],
    
    // Server
    'server' => [
        'host' => $_ENV['SERVER_HOST'] ?? 'localhost',
        'port' => (int) ($_ENV['SERVER_PORT'] ?? 8080),
    ],
];