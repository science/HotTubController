<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Response;

abstract class Action
{
    protected LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            return $this->action($request, $response, $args);
        } catch (\Throwable $e) {
            $this->logger->error('Action error', [
                'action' => static::class,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return $this->errorResponse('Internal server error', 500);
        }
    }

    abstract protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface;

    protected function jsonResponse(array $data, int $statusCode = 200): ResponseInterface
    {
        $response = new Response($statusCode);
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        return $response->withHeader('Content-Type', 'application/json');
    }

    protected function errorResponse(string $message, int $statusCode = 400): ResponseInterface
    {
        return $this->jsonResponse(['error' => $message], $statusCode);
    }

    protected function getJsonInput(ServerRequestInterface $request): array
    {
        $input = $request->getParsedBody();

        if (!is_array($input)) {
            return [];
        }

        return $input;
    }

    protected function validateRequired(array $data, array $required): array
    {
        $missing = [];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '' || $data[$field] === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}
