<?php

declare(strict_types=1);

use HotTubController\Application\Actions\Admin\BootstrapAction;
use HotTubController\Application\Actions\Admin\CreateUserAction;
use HotTubController\Application\Actions\Admin\ListUsersAction;
use HotTubController\Application\Actions\Admin\GetHeatingConfigAction;
use HotTubController\Application\Actions\Admin\UpdateHeatingConfigAction;
use HotTubController\Application\Actions\Auth\AuthenticateAction;
use HotTubController\Application\Actions\Heating\MonitorTempAction;
use HotTubController\Application\Actions\Heating\StartHeatingAction;
use HotTubController\Application\Actions\Heating\StopHeatingAction;
use HotTubController\Application\Actions\Heating\ScheduleHeatingAction;
use HotTubController\Application\Actions\Heating\CancelScheduledHeatingAction;
use HotTubController\Application\Actions\Heating\ListHeatingEventsAction;
use HotTubController\Application\Actions\Heating\HeatingStatusAction;
use HotTubController\Application\Actions\StatusAction;
use HotTubController\Application\Middleware\TokenValidationMiddleware;
use Slim\App;

return function (App $app) {
    
    // Health/Status endpoint
    $app->get('/', StatusAction::class);
    $app->get('/index.php', StatusAction::class);
    
    // API routes
    $app->group('/api/v1', function ($group) {
        
        // System status endpoint for warming/health checks (public, no auth required)
        $group->get('/status', StatusAction::class);
        
        // Authentication
        $group->post('/auth', AuthenticateAction::class);
        
        // Bootstrap endpoint for initial admin token creation (uses master password)
        $group->post('/admin/bootstrap', BootstrapAction::class);
        
        // Admin endpoints (token-authenticated with admin role required)
        $group->post('/admin/user', CreateUserAction::class);
        $group->get('/admin/users', ListUsersAction::class);
        
        // Heating configuration management (admin only)
        $group->get('/admin/config/heating', GetHeatingConfigAction::class);
        $group->put('/admin/config/heating', UpdateHeatingConfigAction::class);
        
    });
    
    // Heating Control API
    $app->group('/api', function ($group) {
        
        // Core heating control (cron-authenticated - handled by CronAuthenticatedAction)
        $group->post('/start-heating', StartHeatingAction::class);
        $group->get('/monitor-temp', MonitorTempAction::class);
        
        // Emergency stop (admin or cron authenticated - handled internally)
        $group->post('/stop-heating', StopHeatingAction::class);
        
        // Management APIs (user-authenticated - handled by AuthenticatedAction base classes)
        $group->post('/schedule-heating', ScheduleHeatingAction::class);
        $group->post('/cancel-scheduled-heating', CancelScheduledHeatingAction::class);
        $group->get('/list-heating-events', ListHeatingEventsAction::class);
        
        // Status API (user-authenticated - no longer public)
        $group->get('/heating-status', HeatingStatusAction::class);
        
    });
    
    // Handle preflight requests
    $app->options('/{routes:.*}', function ($request, $response) {
        return $response;
    });
};