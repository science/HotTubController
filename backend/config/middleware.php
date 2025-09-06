<?php

declare(strict_types=1);

use HotTubController\Application\Middleware\CorsMiddleware;
use Slim\App;

return function (App $app) {
    // CORS Middleware (must be first to handle OPTIONS requests)
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