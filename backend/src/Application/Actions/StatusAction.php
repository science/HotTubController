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
            'service' => 'Hot Tub Controller',
            'status' => 'running',
            'timestamp' => date('c')
        ]);
    }
}