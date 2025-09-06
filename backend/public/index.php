<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// Create DI Container
$containerBuilder = new ContainerBuilder();

// Add service definitions
$dependencies = require __DIR__ . '/../config/dependencies.php';
$dependencies($containerBuilder);

$container = $containerBuilder->build();

// Create Slim App
AppFactory::setContainer($container);
$app = AppFactory::create();

// Configure middleware
$middleware = require __DIR__ . '/../config/middleware.php';
$middleware($app);

// Configure routes
$routes = require __DIR__ . '/../config/routes.php';
$routes($app);

// Run the app
$app->run();