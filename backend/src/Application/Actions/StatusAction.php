<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class StatusAction extends Action
{
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->jsonResponse([
            'service' => 'Hot Tub Controller PHP Proxy',
            'version' => '1.0.0',
            'status' => 'running',
            'timestamp' => date('c'),
            'environment' => $_ENV['APP_ENV'] ?? 'production'
        ]);
    }
}