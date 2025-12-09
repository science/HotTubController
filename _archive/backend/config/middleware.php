<?php

declare(strict_types=1);

use HotTubController\Application\Middleware\CorsMiddleware;
use HotTubController\Application\Middleware\ResponseTimeMiddleware;
use Slim\App;

return function (App $app) {
    // Response time tracking middleware (should be early to capture full request duration)
    $app->add(ResponseTimeMiddleware::class);
    
    // CORS Middleware (must be after response time tracking)
    $app->add(CorsMiddleware::class);
    
    // Parse JSON body
    $app->addBodyParsingMiddleware();
    
    // Route parsing middleware (should be added last)
    $app->addRoutingMiddleware();
    
    // Error handling middleware (should be added last)
    $errorMiddleware = $app->addErrorMiddleware(
        displayErrorDetails: true,
        logErrors: true,
        logErrorDetails: true
    );
};