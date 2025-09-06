<?php

declare(strict_types=1);

use HotTubController\Application\Actions\Admin\CreateUserAction;
use HotTubController\Application\Actions\Admin\ListUsersAction;
use HotTubController\Application\Actions\Auth\AuthenticateAction;
use HotTubController\Application\Actions\Proxy\ProxyRequestAction;
use HotTubController\Application\Actions\StatusAction;
use Slim\App;

return function (App $app) {
    
    // Health/Status endpoint
    $app->get('/', StatusAction::class);
    $app->get('/index.php', StatusAction::class);
    
    // API routes
    $app->group('/api/v1', function ($group) {
        
        // Authentication
        $group->post('/auth', AuthenticateAction::class);
        
        // Main proxy endpoint
        $group->post('/proxy', ProxyRequestAction::class);
        
        // Admin endpoints
        $group->post('/admin/user', CreateUserAction::class);
        $group->get('/admin/users', ListUsersAction::class);
        
    });
    
    // Handle preflight requests
    $app->options('/{routes:.*}', function ($request, $response) {
        return $response;
    });
};