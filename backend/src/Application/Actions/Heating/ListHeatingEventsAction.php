<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\AuthenticatedAction;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Models\HeatingCycle;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Heating\Repositories\HeatingCycleRepository;
use HotTubController\Domain\Token\TokenService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use RuntimeException;

class ListHeatingEventsAction extends AuthenticatedAction
{
    private HeatingEventRepository $eventRepository;
    private HeatingCycleRepository $cycleRepository;
    
    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        HeatingEventRepository $eventRepository,
        HeatingCycleRepository $cycleRepository
    ) {
        parent::__construct($logger, $tokenService);
        $this->eventRepository = $eventRepository;
        $this->cycleRepository = $cycleRepository;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            // Authenticate user token
            $this->authenticateUserToken($request);
            
            // Parse query parameters
            $filters = $this->parseFilters($request);
            $pagination = $this->parsePagination($request);
            
            $this->logger->info('Listing heating events', [
                'filters' => $filters,
                'pagination' => $pagination,
            ]);
            
            // Build query with filters
            $query = $this->eventRepository->query();
            
            // Apply status filter
            if (!empty($filters['status'])) {
                $query->where('status', $filters['status']);
            }
            
            // Apply date range filter
            if (!empty($filters['from_date'])) {
                $query->where('scheduled_for', '>=', $filters['from_date']);
            }
            
            if (!empty($filters['to_date'])) {
                $query->where('scheduled_for', '<=', $filters['to_date']);
            }
            
            // Apply event type filter
            if (!empty($filters['event_type'])) {
                $query->where('event_type', $filters['event_type']);
            }
            
            // Get total count for pagination
            $totalCount = $query->count();
            
            // Apply pagination and sorting
            $events = $query
                ->orderBy('scheduled_for', 'desc')
                ->offset($pagination['offset'])
                ->limit($pagination['limit'])
                ->get();
            
            // Enrich events with related cycle data
            $enrichedEvents = $this->enrichEventsWithCycleData($events);
            
            $response = [
                'events' => $enrichedEvents,
                'pagination' => [
                    'total' => $totalCount,
                    'limit' => $pagination['limit'],
                    'offset' => $pagination['offset'],
                    'has_more' => ($pagination['offset'] + $pagination['limit']) < $totalCount,
                ],
                'filters' => $filters,
            ];
            
            return $this->jsonResponse($response);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to list heating events', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse('Failed to list heating events: ' . $e->getMessage(), 400);
        }
    }
    
    private function authenticateUserToken(ServerRequestInterface $request): void
    {
        $authHeader = $request->getHeaderLine('Authorization');
        
        if (empty($authHeader)) {
            throw new RuntimeException('Missing Authorization header');
        }
        
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            throw new RuntimeException('Invalid Authorization header format');
        }
        
        $token = $matches[1];
        
        if (!$this->tokenService->validateToken($token)) {
            throw new RuntimeException('Invalid or expired token');
        }
        
        // Update token last used timestamp
        $this->tokenService->updateTokenLastUsed($token);
    }
    
    private function parseFilters(ServerRequestInterface $request): array
    {
        $filters = [];
        $queryParams = $request->getQueryParams();
        
        // Status filter
        $status = $queryParams['status'] ?? null;
        if (!empty($status)) {
            $validStatuses = [
                HeatingEvent::STATUS_SCHEDULED,
                HeatingEvent::STATUS_TRIGGERED,
                HeatingEvent::STATUS_CANCELLED,
                HeatingEvent::STATUS_ERROR,
            ];
            
            if (!in_array($status, $validStatuses)) {
                throw new RuntimeException("Invalid status filter: {$status}");
            }
            
            $filters['status'] = $status;
        }
        
        // Event type filter
        $eventType = $queryParams['event_type'] ?? null;
        if (!empty($eventType)) {
            $validTypes = [HeatingEvent::EVENT_TYPE_START, HeatingEvent::EVENT_TYPE_MONITOR];
            
            if (!in_array($eventType, $validTypes)) {
                throw new RuntimeException("Invalid event_type filter: {$eventType}");
            }
            
            $filters['event_type'] = $eventType;
        }
        
        // Date range filters
        $fromDate = $queryParams['from_date'] ?? null;
        if (!empty($fromDate)) {
            try {
                $fromDateTime = new DateTime($fromDate);
                $filters['from_date'] = $fromDateTime->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                throw new RuntimeException("Invalid from_date format: {$fromDate}");
            }
        }
        
        $toDate = $queryParams['to_date'] ?? null;
        if (!empty($toDate)) {
            try {
                $toDateTime = new DateTime($toDate);
                $filters['to_date'] = $toDateTime->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                throw new RuntimeException("Invalid to_date format: {$toDate}");
            }
        }
        
        return $filters;
    }
    
    private function parsePagination(ServerRequestInterface $request): array
    {
        $queryParams = $request->getQueryParams();
        $limit = (int) ($queryParams['limit'] ?? 20);
        $offset = (int) ($queryParams['offset'] ?? 0);
        
        // Validate limits
        if ($limit < 1 || $limit > 100) {
            throw new RuntimeException('Limit must be between 1 and 100');
        }
        
        if ($offset < 0) {
            throw new RuntimeException('Offset cannot be negative');
        }
        
        return [
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
    
    private function enrichEventsWithCycleData(array $events): array
    {
        $enrichedEvents = [];
        
        foreach ($events as $event) {
            $eventData = $this->formatEventData($event);
            
            // Add related cycle information for triggered events
            if ($event->isTriggered() && $event->getCycleId()) {
                $cycle = $this->cycleRepository->findById($event->getCycleId());
                if ($cycle) {
                    $eventData['cycle'] = $this->formatCycleData($cycle);
                }
            }
            
            // Add cron execution time for scheduled events
            if ($event->isScheduled() && $event->getCronExpression()) {
                $eventData['next_execution'] = $event->getScheduledFor()->format('c');
                $eventData['time_until_execution'] = $event->getTimeUntilExecution();
            }
            
            $enrichedEvents[] = $eventData;
        }
        
        return $enrichedEvents;
    }
    
    private function formatEventData(HeatingEvent $event): array
    {
        $metadata = $event->getMetadata();
        
        return [
            'id' => $event->getId(),
            'event_type' => $event->getEventType(),
            'status' => $event->getStatus(),
            'scheduled_for' => $event->getScheduledFor()->format('c'),
            'target_temp' => $event->getTargetTemp(),
            'cron_expression' => $event->getCronExpression(),
            'cycle_id' => $event->getCycleId(),
            'name' => $metadata['name'] ?? null,
            'description' => $metadata['description'] ?? null,
            'created_at' => $event->getCreatedAt()?->format('c'),
            'updated_at' => $event->getUpdatedAt()?->format('c'),
            'metadata' => $metadata,
        ];
    }
    
    private function formatCycleData(HeatingCycle $cycle): array
    {
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
        ];
    }
}