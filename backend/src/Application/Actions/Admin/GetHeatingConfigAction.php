<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\AdminAuthenticatedAction;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Config\HeatingConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

/**
 * Get current heating configuration
 *
 * Returns the current heating system configuration including
 * heating rate, units, and validation bounds.
 */
class GetHeatingConfigAction extends AdminAuthenticatedAction
{
    private HeatingConfig $heatingConfig;

    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        ?HeatingConfig $heatingConfig = null
    ) {
        parent::__construct($logger, $tokenService);
        $this->heatingConfig = $heatingConfig ?? new HeatingConfig();
    }

    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->logger->info('Getting heating configuration');

            $config = $this->heatingConfig->toArray();

            $this->logger->info('Successfully retrieved heating configuration', [
                'heating_rate' => $config['heating_rate'],
                'unit' => $config['unit']
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'data' => $config
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            $this->logger->error('Failed to get heating configuration', [
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to retrieve heating configuration'
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
