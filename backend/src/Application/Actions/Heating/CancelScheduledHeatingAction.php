<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\Action;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Services\CronManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Exception;
use RuntimeException;

class CancelScheduledHeatingAction extends Action
{
    private TokenService $tokenService;
    private HeatingEventRepository $eventRepository;
    private CronManager $cronManager;
    
    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        HeatingEventRepository $eventRepository,
        CronManager $cronManager
    ) {
        parent::__construct($logger);
        $this->tokenService = $tokenService;
        $this->eventRepository = $eventRepository;
        $this->cronManager = $cronManager;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            // Authenticate user token
            $this->authenticateUserToken($request);
            
            // Parse and validate parameters
            $input = $this->getJsonInput($request);
            $eventId = $input['event_id'] ?? null;
            
            if (empty($eventId)) {
                throw new RuntimeException('Missing event_id parameter');
            }
            
            $this->logger->info('Cancelling scheduled heating event', [
                'event_id' => $eventId,
            ]);
            
            // Find the event
            $event = $this->eventRepository->findById($eventId);
            if (!$event) {
                throw new RuntimeException('Event not found');
            }
            
            // Validate event can be cancelled
            if (!$event->isScheduled()) {
                throw new RuntimeException(
                    "Cannot cancel event with status '{$event->getStatus()}'. Only scheduled events can be cancelled."
                );
            }
            
            // Only allow cancelling start events (not monitor events)
            if (!$event->isStartEvent()) {
                throw new RuntimeException('Only start events can be cancelled via this API');
            }
            
            // Remove associated cron job
            $this->removeCronJob($event);
            
            // Cancel the event
            $event->cancel();
            $event->addMetadata('cancelled_via_api', true);
            $event->addMetadata('cancelled_at', (new \DateTime())->format('c'));
            $event->save();
            
            $this->logger->info('Heating event cancelled successfully', [
                'event_id' => $eventId,
                'scheduled_for' => $event->getScheduledFor()->format('Y-m-d H:i:s'),
            ]);
            
            return $this->jsonResponse([
                'status' => 'cancelled',
                'event_id' => $eventId,
                'message' => 'Scheduled heating event cancelled successfully',
                'was_scheduled_for' => $event->getScheduledFor()->format('c'),
                'target_temp' => $event->getTargetTemp(),
                'name' => $event->getMetadata()['name'] ?? 'Scheduled Heating',
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to cancel scheduled heating event', [
                'event_id' => $eventId ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse('Failed to cancel heating event: ' . $e->getMessage(), 400);
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
    
    private function removeCronJob(HeatingEvent $event): void
    {
        try {
            // Remove cron job using the event ID
            $removed = $this->cronManager->removeStartEvents($event->getId());
            
            $this->logger->info('Removed cron job for cancelled event', [
                'event_id' => $event->getId(),
                'removed_count' => $removed,
            ]);
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to remove cron job for cancelled event', [
                'event_id' => $event->getId(),
                'error' => $e->getMessage(),
            ]);
            
            // Don't fail the cancellation if cron removal fails
            // The event will still be marked as cancelled
        }
    }
}