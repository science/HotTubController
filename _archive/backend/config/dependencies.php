<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use HotTubController\Application\Actions\Admin\BootstrapAction;
use HotTubController\Application\Actions\Admin\CreateUserAction;
use HotTubController\Application\Actions\Admin\ListUsersAction;
use HotTubController\Application\Actions\Auth\AuthenticateAction;
use HotTubController\Application\Actions\Heating\MonitorTempAction;
use HotTubController\Application\Actions\Heating\StartHeatingAction;
use HotTubController\Application\Actions\Heating\StopHeatingAction;
use HotTubController\Application\Actions\Heating\ScheduleHeatingAction;
use HotTubController\Application\Actions\Heating\CancelScheduledHeatingAction;
use HotTubController\Application\Actions\Heating\ListHeatingEventsAction;
use HotTubController\Application\Actions\Heating\HeatingStatusAction;
use HotTubController\Application\Actions\StatusAction;
use HotTubController\Application\Middleware\CorsMiddleware;
use HotTubController\Application\Middleware\ResponseTimeMiddleware;
use HotTubController\Application\Middleware\TokenValidationMiddleware;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Domain\Token\TokenRepositoryInterface;
use HotTubController\Infrastructure\Persistence\JsonTokenRepository;
use HotTubController\Infrastructure\Storage\JsonStorageManager;
use HotTubController\Services\CronManager;
use HotTubController\Services\CronSecurityManager;
use HotTubController\Services\IftttWebhookClientFactory;
use HotTubController\Services\WirelessTagClientFactory;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

return function (ContainerBuilder $containerBuilder) {
    $containerBuilder->addDefinitions([
        
        // Settings
        'settings' => require __DIR__ . '/settings.php',
        
        // Logger
        LoggerInterface::class => function (ContainerInterface $c): Logger {
            $settings = $c->get('settings')['logger'];
            
            $logger = new Logger($settings['name']);
            
            // Create log directory if it doesn't exist
            $logFile = $settings['path'];
            $logDir = dirname($logFile);
            if (!is_dir($logDir)) {
                mkdir($logDir, 0755, true);
            }
            
            $handler = new StreamHandler($logFile, $settings['level']);
            
            // Custom format for better readability
            $formatter = new LineFormatter(
                "[%datetime%] %level_name%: %message% %context% %extra%\n",
                'Y-m-d H:i:s'
            );
            $handler->setFormatter($formatter);
            
            $logger->pushHandler($handler);
            
            return $logger;
        },
        
        
        // Token Repository
        TokenRepositoryInterface::class => function (ContainerInterface $c): TokenRepositoryInterface {
            $settings = $c->get('settings');
            $logger = $c->get(LoggerInterface::class);
            
            return new JsonTokenRepository(
                $settings['storage']['token_file'],
                $logger
            );
        },

        // CORS Middleware
        CorsMiddleware::class => function (ContainerInterface $c): CorsMiddleware {
            $settings = $c->get('settings')['cors'];
            
            return new CorsMiddleware(
                $settings['allowed_origins'],
                $settings['allowed_methods'],
                $settings['allowed_headers'],
                $settings['max_age']
            );
        },

        // Response Time Middleware
        ResponseTimeMiddleware::class => function (ContainerInterface $c): ResponseTimeMiddleware {
            $settings = $c->get('settings');
            $enableLogging = $settings['performance']['enable_logging'] ?? true;
            $slowThreshold = $settings['performance']['slow_threshold_ms'] ?? 1000;
            
            return new ResponseTimeMiddleware($enableLogging, $slowThreshold);
        },

        // Actions
        StatusAction::class => function (ContainerInterface $c): StatusAction {
            return new StatusAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class)
            );
        },

        // Token Service
        TokenService::class => function (ContainerInterface $c): TokenService {
            return new TokenService($c->get(TokenRepositoryInterface::class));
        },

        // Actions
        AuthenticateAction::class => function (ContainerInterface $c): AuthenticateAction {
            $settings = $c->get('settings');
            return new AuthenticateAction(
                $c->get(LoggerInterface::class),
                $settings['auth']['master_password_hash']
            );
        },

        BootstrapAction::class => function (ContainerInterface $c): BootstrapAction {
            $settings = $c->get('settings');
            return new BootstrapAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $settings['auth']['master_password_hash']
            );
        },

        CreateUserAction::class => function (ContainerInterface $c): CreateUserAction {
            return new CreateUserAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class)
            );
        },

        ListUsersAction::class => function (ContainerInterface $c): ListUsersAction {
            return new ListUsersAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class)
            );
        },

        // Cron Management Services
        CronSecurityManager::class => function (ContainerInterface $c): CronSecurityManager {
            return new CronSecurityManager();
        },

        CronManager::class => function (ContainerInterface $c): CronManager {
            return new CronManager();
        },

        CronJobBuilder::class => function (ContainerInterface $c): CronJobBuilder {
            return new CronJobBuilder();
        },

        // External API Clients
        'WirelessTagClient' => function (ContainerInterface $c) {
            return WirelessTagClientFactory::create();
        },

        'IftttWebhookClient' => function (ContainerInterface $c) {
            return IftttWebhookClientFactory::create();
        },

        // Storage Manager
        JsonStorageManager::class => function (ContainerInterface $c): JsonStorageManager {
            $settings = $c->get('settings');
            return new JsonStorageManager(__DIR__ . '/../storage');
        },

        // Heating Repositories
        HeatingCycleRepository::class => function (ContainerInterface $c): HeatingCycleRepository {
            return new HeatingCycleRepository($c->get(JsonStorageManager::class));
        },

        HeatingEventRepository::class => function (ContainerInterface $c): HeatingEventRepository {
            return new HeatingEventRepository($c->get(JsonStorageManager::class));
        },

        // Heating Control Actions
        StartHeatingAction::class => function (ContainerInterface $c): StartHeatingAction {
            return new StartHeatingAction(
                $c->get(LoggerInterface::class),
                $c->get('WirelessTagClient'),
                $c->get('IftttWebhookClient'),
                $c->get(CronManager::class),
                $c->get(CronSecurityManager::class),
                $c->get(CronJobBuilder::class),
                $c->get(HeatingCycleRepository::class),
                $c->get(HeatingEventRepository::class)
            );
        },

        MonitorTempAction::class => function (ContainerInterface $c): MonitorTempAction {
            return new MonitorTempAction(
                $c->get(LoggerInterface::class),
                $c->get('WirelessTagClient'),
                $c->get('IftttWebhookClient'),
                $c->get(CronManager::class),
                $c->get(CronSecurityManager::class),
                $c->get(CronJobBuilder::class),
                $c->get(HeatingCycleRepository::class)
            );
        },

        StopHeatingAction::class => function (ContainerInterface $c): StopHeatingAction {
            return new StopHeatingAction(
                $c->get(LoggerInterface::class),
                $c->get('WirelessTagClient'),
                $c->get('IftttWebhookClient'),
                $c->get(CronManager::class),
                $c->get(TokenService::class),
                $c->get(HeatingCycleRepository::class)
            );
        },

        // Management API Actions
        ScheduleHeatingAction::class => function (ContainerInterface $c): ScheduleHeatingAction {
            return new ScheduleHeatingAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $c->get(HeatingEventRepository::class),
                $c->get(CronManager::class),
                $c->get(CronJobBuilder::class),
                $c->get('WirelessTagClient')
            );
        },

        CancelScheduledHeatingAction::class => function (ContainerInterface $c): CancelScheduledHeatingAction {
            return new CancelScheduledHeatingAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $c->get(HeatingEventRepository::class),
                $c->get(CronManager::class)
            );
        },

        ListHeatingEventsAction::class => function (ContainerInterface $c): ListHeatingEventsAction {
            return new ListHeatingEventsAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $c->get(HeatingEventRepository::class),
                $c->get(HeatingCycleRepository::class)
            );
        },

        HeatingStatusAction::class => function (ContainerInterface $c): HeatingStatusAction {
            return new HeatingStatusAction(
                $c->get(LoggerInterface::class),
                $c->get('WirelessTagClient'),
                $c->get(HeatingEventRepository::class),
                $c->get(HeatingCycleRepository::class)
            );
        },

        // Middleware
        TokenValidationMiddleware::class => function (ContainerInterface $c): TokenValidationMiddleware {
            return new TokenValidationMiddleware(
                $c->get(TokenService::class),
                $c->get(LoggerInterface::class)
            );
        },
    ]);
};