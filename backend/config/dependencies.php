<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use HotTubController\Application\Actions\Admin\CreateUserAction;
use HotTubController\Application\Actions\Admin\ListUsersAction;
use HotTubController\Application\Actions\Auth\AuthenticateAction;
use HotTubController\Application\Actions\Proxy\ProxyRequestAction;
use HotTubController\Application\Actions\StatusAction;
use HotTubController\Application\Middleware\CorsMiddleware;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Domain\Token\TokenRepositoryInterface;
use HotTubController\Infrastructure\Http\CurlHttpClient;
use HotTubController\Infrastructure\Http\HttpClientInterface;
use HotTubController\Infrastructure\Persistence\JsonTokenRepository;
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
        
        // HTTP Client
        HttpClientInterface::class => function (ContainerInterface $c): HttpClientInterface {
            $settings = $c->get('settings')['http_client'];
            return new CurlHttpClient(
                $settings['timeout'],
                $settings['user_agent'],
                $settings['verify_ssl']
            );
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

        // Actions
        StatusAction::class => function (ContainerInterface $c): StatusAction {
            return new StatusAction($c->get(LoggerInterface::class));
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

        ProxyRequestAction::class => function (ContainerInterface $c): ProxyRequestAction {
            return new ProxyRequestAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $c->get(HttpClientInterface::class)
            );
        },

        CreateUserAction::class => function (ContainerInterface $c): CreateUserAction {
            $settings = $c->get('settings');
            return new CreateUserAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $settings['auth']['master_password_hash']
            );
        },

        ListUsersAction::class => function (ContainerInterface $c): ListUsersAction {
            $settings = $c->get('settings');
            return new ListUsersAction(
                $c->get(LoggerInterface::class),
                $c->get(TokenService::class),
                $settings['auth']['master_password_hash']
            );
        },
    ]);
};