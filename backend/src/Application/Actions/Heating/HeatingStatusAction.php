<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\AuthenticatedAction;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Services\WirelessTagClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;

class HeatingStatusAction extends AuthenticatedAction
{
    private WirelessTagClient $wirelessTagClient;
    private HeatingEventRepository $eventRepository;
    private HeatingCycleRepository $cycleRepository;
    
    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        WirelessTagClient $wirelessTagClient,
        HeatingEventRepository $eventRepository,
        HeatingCycleRepository $cycleRepository
    ) {
        parent::__construct($logger, $tokenService);
        $this->wirelessTagClient = $wirelessTagClient;
        $this->eventRepository = $eventRepository;
        $this->cycleRepository = $cycleRepository;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $this->logger->info('Getting heating system status');
            
            $status = [
                'timestamp' => (new DateTime())->format('c'),
                'temperature' => $this->getCurrentTemperature(),
                'active_cycle' => $this->getActiveCycle(),
                'next_scheduled_event' => $this->getNextScheduledEvent(),
                'system_health' => $this->getSystemHealth(),
            ];
            
            return $this->jsonResponse($status);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to get heating system status', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            // Return partial status even if some operations fail
            return $this->jsonResponse([
                'timestamp' => (new DateTime())->format('c'),
                'temperature' => null,
                'active_cycle' => null,
                'next_scheduled_event' => null,
                'system_health' => [
                    'status' => 'error',
                    'error' => $e->getMessage(),
                ],
            ]);
        }
    }
    
    private function getCurrentTemperature(): ?array
    {
        try {
            // Use a default device ID - this should be configurable in the future
            $deviceId = '217af407-0165-462d-be07-809e82f6a865';
            
            // Get cached temperature data (status API should be fast)
            $temperatureData = $this->wirelessTagClient->getCachedTemperatureData($deviceId);
            
            if (empty($temperatureData)) {
                return null;
            }
            
            // Process the raw temperature data
            $processed = $this->wirelessTagClient->processTemperatureData($temperatureData);
            
            return [
                'value' => $processed['water_temperature']['fahrenheit'],
                'unit' => 'fahrenheit',
                'sensor_name' => $processed['sensor_info']['name'] ?? 'Hot Tub Sensor',
                'last_updated' => $processed['sensor_info']['timestamp'] ?? (new DateTime())->format('c'),
                'battery_level' => $processed['sensor_info']['battery_level'] ?? null,
                'signal_strength' => $processed['sensor_info']['signal_strength'] ?? null,
            ];
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to get current temperature', [
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }
    
    private function getActiveCycle(): ?array
    {
        try {
            // Find active heating cycle
            $activeCycles = $this->cycleRepository->query()
                ->where('status', HeatingCycle::STATUS_HEATING)
                ->orderBy('started_at', 'desc')
                ->limit(1)
                ->get();
            
            if (empty($activeCycles)) {
                return null;
            }
            
            $cycle = reset($activeCycles);
            
            return [
                'id' => $cycle->getId(),
                'status' => $cycle->getStatus(),
                'started_at' => $cycle->getStartedAt()->format('c'),
                'target_temp' => $cycle->getTargetTemp(),
                'current_temp' => $cycle->getCurrentTemp(),
                'estimated_completion' => $cycle->getEstimatedCompletion()?->format('c'),
                'last_check' => $cycle->getLastCheck()?->format('c'),
                'elapsed_time_seconds' => $cycle->getElapsedTime(),
                'estimated_time_remaining_seconds' => $cycle->getEstimatedTimeRemaining(),
                'temperature_difference' => $cycle->getTemperatureDifference(),
                'progress' => $this->calculateCycleProgress($cycle),
                'metadata' => $cycle->getMetadata(),
            ];
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to get active cycle', [
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }
    
    private function getNextScheduledEvent(): ?array
    {
        try {
            $nextEvent = $this->eventRepository->getNextScheduledEvent(HeatingEvent::EVENT_TYPE_START);
            
            if (!$nextEvent) {
                return null;
            }
            
            $metadata = $nextEvent->getMetadata();
            
            return [
                'id' => $nextEvent->getId(),
                'event_type' => $nextEvent->getEventType(),
                'scheduled_for' => $nextEvent->getScheduledFor()->format('c'),
                'target_temp' => $nextEvent->getTargetTemp(),
                'time_until_execution' => $nextEvent->getTimeUntilExecution(),
                'name' => $metadata['name'] ?? 'Scheduled Heating',
                'description' => $metadata['description'] ?? '',
                'cron_expression' => $nextEvent->getCronExpression(),
            ];
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to get next scheduled event', [
                'error' => $e->getMessage(),
            ]);
            
            return null;
        }
    }
    
    private function getSystemHealth(): array
    {
        $health = [
            'status' => 'healthy',
            'issues' => [],
            'last_check' => (new DateTime())->format('c'),
        ];
        
        try {
            // Check for orphaned crons (events that should have triggered)
            $pastDueEvents = $this->eventRepository->findPastDueEvents();
            if (!empty($pastDueEvents)) {
                $health['status'] = 'warning';
                $health['issues'][] = [
                    'type' => 'past_due_events',
                    'message' => count($pastDueEvents) . ' events are past due',
                    'count' => count($pastDueEvents),
                ];
            }
            
            // Check for multiple active cycles (should not happen)
            $activeCycles = $this->cycleRepository->query()
                ->where('status', HeatingCycle::STATUS_HEATING)
                ->get();
            
            if (count($activeCycles) > 1) {
                $health['status'] = 'error';
                $health['issues'][] = [
                    'type' => 'multiple_active_cycles',
                    'message' => 'Multiple active heating cycles detected',
                    'count' => count($activeCycles),
                ];
            }
            
            // Check for very old active cycles (potential stuck cycles)
            if (!empty($activeCycles)) {
                $cycle = reset($activeCycles);
                $maxDuration = 4 * 3600; // 4 hours in seconds
                
                if ($cycle->getElapsedTime() > $maxDuration) {
                    $health['status'] = 'warning';
                    $health['issues'][] = [
                        'type' => 'long_running_cycle',
                        'message' => 'Heating cycle has been running for over 4 hours',
                        'cycle_id' => $cycle->getId(),
                        'elapsed_hours' => round($cycle->getElapsedTime() / 3600, 1),
                    ];
                }
            }
            
            // Check scheduled events count
            $scheduledCount = $this->eventRepository->countScheduledEvents();
            
            return [
                ...$health,
                'statistics' => [
                    'scheduled_events' => $scheduledCount,
                    'active_cycles' => count($activeCycles),
                    'past_due_events' => count($pastDueEvents),
                ],
            ];
            
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'issues' => [
                    [
                        'type' => 'health_check_failed',
                        'message' => 'Failed to check system health: ' . $e->getMessage(),
                    ],
                ],
                'last_check' => (new DateTime())->format('c'),
            ];
        }
    }
    
    private function calculateCycleProgress(HeatingCycle $cycle): ?float
    {
        if ($cycle->getCurrentTemp() === null) {
            return null;
        }
        
        $tempDiff = $cycle->getTemperatureDifference();
        if ($tempDiff === null || $tempDiff <= 0) {
            return 1.0; // Already at target
        }
        
        // Estimate initial temperature difference
        // This is approximate since we don't store the initial temp differential
        $elapsedMinutes = $cycle->getElapsedTime() / 60;
        $heatingRate = 0.5; // degrees per minute (approximate)
        $estimatedInitialDiff = $tempDiff + ($elapsedMinutes * $heatingRate);
        
        if ($estimatedInitialDiff <= 0) {
            return 1.0;
        }
        
        $progress = 1.0 - ($tempDiff / $estimatedInitialDiff);
        
        return max(0.0, min(1.0, $progress));
    }
}