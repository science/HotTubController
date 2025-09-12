<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Admin;

use HotTubController\Application\Actions\AdminAuthenticatedAction;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Config\HeatingConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use InvalidArgumentException;

/**
 * Update heating configuration
 *
 * Updates the heating system configuration with new values.
 * Requires admin authentication and validates input parameters.
 */
class UpdateHeatingConfigAction extends AdminAuthenticatedAction
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
            $this->logger->info('Updating heating configuration');

            $data = $this->getJsonInput($request);

            // Validate required fields
            if (!isset($data['heating_rate'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Missing required field: heating_rate'
                ]));

                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            if (!isset($data['unit'])) {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'error' => 'Missing required field: unit'
                ]));

                return $response
                    ->withHeader('Content-Type', 'application/json')
                    ->withStatus(400);
            }

            // Update configuration with validation
            $this->heatingConfig->updateFromArray($data);

            // Persist configuration to storage
            $this->heatingConfig->persistToStorage();

            $updatedConfig = $this->heatingConfig->toArray();

            $this->logger->info('Successfully updated heating configuration', [
                'heating_rate' => $updatedConfig['heating_rate'],
                'unit' => $updatedConfig['unit']
            ]);

            $response->getBody()->write(json_encode([
                'success' => true,
                'message' => 'Heating configuration updated successfully',
                'data' => $updatedConfig
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (InvalidArgumentException $e) {
            $this->logger->warning('Invalid heating configuration parameters', [
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(422); // Unprocessable Entity
        } catch (\Exception $e) {
            $this->logger->error('Failed to update heating configuration', [
                'error' => $e->getMessage()
            ]);

            $response->getBody()->write(json_encode([
                'success' => false,
                'error' => 'Failed to update heating configuration'
            ]));

            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withStatus(500);
        }
    }
}
