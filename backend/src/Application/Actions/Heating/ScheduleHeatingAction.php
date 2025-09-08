<?php

declare(strict_types=1);

namespace HotTubController\Application\Actions\Heating;

use HotTubController\Application\Actions\Action;
use HotTubController\Domain\Heating\CronJobBuilder;
use HotTubController\Domain\Heating\Models\HeatingEvent;
use HotTubController\Domain\Heating\Repositories\HeatingEventRepository;
use HotTubController\Domain\Token\TokenService;
use HotTubController\Services\CronManager;
use HotTubController\Services\WirelessTagClient;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use DateTime;
use Exception;
use RuntimeException;

class ScheduleHeatingAction extends Action
{
    private TokenService $tokenService;
    private HeatingEventRepository $eventRepository;
    private CronManager $cronManager;
    private CronJobBuilder $cronJobBuilder;
    private WirelessTagClient $wirelessTagClient;
    
    public function __construct(
        LoggerInterface $logger,
        TokenService $tokenService,
        HeatingEventRepository $eventRepository,
        CronManager $cronManager,
        CronJobBuilder $cronJobBuilder,
        WirelessTagClient $wirelessTagClient
    ) {
        parent::__construct($logger);
        $this->tokenService = $tokenService;
        $this->eventRepository = $eventRepository;
        $this->cronManager = $cronManager;
        $this->cronJobBuilder = $cronJobBuilder;
        $this->wirelessTagClient = $wirelessTagClient;
    }
    
    protected function action(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            // Authenticate user token
            $this->authenticateUserToken($request);
            
            // Parse and validate parameters
            $startTime = $this->parseStartTime($request);
            $targetTemp = $this->parseTargetTemp($request);
            $input = $this->getJsonInput($request);
            $name = $input['name'] ?? 'Scheduled Heating';
            $description = $input['description'] ?? '';
            
            $this->logger->info('Scheduling heating cycle', [
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'target_temp' => $targetTemp,
                'name' => $name,
            ]);
            
            // Check for overlapping heating events
            $this->checkForOverlappingEvents($startTime);
            
            // Check if target temperature is already reached
            $currentTemp = $this->getCurrentTemperature();
            if ($currentTemp >= $targetTemp) {
                return $this->jsonResponse([
                    'status' => 'already_at_target',
                    'current_temp' => $currentTemp,
                    'target_temp' => $targetTemp,
                    'message' => 'Hot tub is already at the desired temperature',
                ]);
            }
            
            // Create heating event
            $event = $this->createHeatingEvent($startTime, $targetTemp, $name, $description);
            
            // Schedule cron job
            $cronId = $this->scheduleCronJob($event, $startTime);
            
            $this->logger->info('Heating cycle scheduled successfully', [
                'event_id' => $event->getId(),
                'cron_id' => $cronId,
                'start_time' => $startTime->format('Y-m-d H:i:s'),
                'target_temp' => $targetTemp,
            ]);
            
            return $this->jsonResponse([
                'status' => 'scheduled',
                'event_id' => $event->getId(),
                'start_time' => $startTime->format('c'),
                'target_temp' => $targetTemp,
                'current_temp' => $currentTemp,
                'name' => $name,
                'description' => $description,
                'cron_id' => $cronId,
            ]);
            
        } catch (Exception $e) {
            $this->logger->error('Failed to schedule heating cycle', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            
            return $this->errorResponse('Failed to schedule heating: ' . $e->getMessage(), 400);
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
    
    private function parseStartTime(ServerRequestInterface $request): DateTime
    {
        $input = $this->getJsonInput($request);
        $startTimeStr = $input['start_time'] ?? null;
        
        if (empty($startTimeStr)) {
            throw new RuntimeException('Missing start_time parameter');
        }
        
        try {
            $startTime = new DateTime($startTimeStr);
        } catch (Exception $e) {
            throw new RuntimeException('Invalid start_time format: ' . $e->getMessage());
        }
        
        // Validate future time only
        $now = new DateTime();
        if ($startTime <= $now) {
            throw new RuntimeException('start_time must be in the future');
        }
        
        return $startTime;
    }
    
    private function parseTargetTemp(ServerRequestInterface $request): float
    {
        $input = $this->getJsonInput($request);
        $targetTemp = $input['target_temp'] ?? null;
        
        if ($targetTemp === null) {
            return 102.0; // Default target temperature
        }
        
        $targetTemp = (float) $targetTemp;
        
        // Validate temperature range (50-110°F)
        if ($targetTemp < 50.0 || $targetTemp > 110.0) {
            throw new RuntimeException("Target temperature out of safe range (50-110°F): {$targetTemp}");
        }
        
        return $targetTemp;
    }
    
    private function checkForOverlappingEvents(DateTime $startTime): void
    {
        // Query for scheduled events within 30 minutes of the new start time
        $bufferMinutes = 30;
        $windowStart = (clone $startTime)->modify("-{$bufferMinutes} minutes");
        $windowEnd = (clone $startTime)->modify("+{$bufferMinutes} minutes");
        
        // Find scheduled start events in the time window
        $overlappingEvents = $this->eventRepository->findByTimeRange(
            $windowStart,
            $windowEnd,
            HeatingEvent::STATUS_SCHEDULED,
            HeatingEvent::EVENT_TYPE_START
        );
        
        if (!empty($overlappingEvents)) {
            $conflictEvent = reset($overlappingEvents);
            throw new RuntimeException(
                "Overlapping heating event detected. " .
                "Conflicting event ID: {$conflictEvent->getId()} " .
                "scheduled for {$conflictEvent->getScheduledFor()->format('Y-m-d H:i:s')}"
            );
        }
    }
    
    private function getCurrentTemperature(): float
    {
        try {
            // Use a default device ID - this should be configurable in the future
            $deviceId = '217af407-0165-462d-be07-809e82f6a865';
            
            // Get fresh temperature reading for scheduling accuracy
            $temperatureData = $this->wirelessTagClient->getFreshTemperatureData($deviceId);
            
            if (empty($temperatureData)) {
                throw new RuntimeException('No temperature data available from sensors');
            }
            
            // Process the raw temperature data
            $processed = $this->wirelessTagClient->processTemperatureData($temperatureData);
            
            return $processed['water_temperature']['fahrenheit'];
            
        } catch (Exception $e) {
            $this->logger->warning('Failed to get current temperature', [
                'error' => $e->getMessage(),
            ]);
            
            // Return a reasonable default rather than failing the scheduling
            return 85.0;
        }
    }
    
    private function createHeatingEvent(DateTime $startTime, float $targetTemp, string $name, string $description): HeatingEvent
    {
        $event = new HeatingEvent();
        $event->setScheduledFor($startTime);
        $event->setEventType(HeatingEvent::EVENT_TYPE_START);
        $event->setTargetTemp($targetTemp);
        $event->setStatus(HeatingEvent::STATUS_SCHEDULED);
        $event->addMetadata('name', $name);
        $event->addMetadata('description', $description);
        $event->addMetadata('scheduled_via_api', true);
        $event->addMetadata('scheduled_at', (new DateTime())->format('c'));
        
        $this->eventRepository->save($event);
        
        return $event;
    }
    
    private function scheduleCronJob(HeatingEvent $event, DateTime $startTime): string
    {
        // Build cron config for start heating endpoint
        $cronConfig = $this->cronJobBuilder->buildStartHeatingCron(
            $startTime,
            $event->getId(),
            $event->getTargetTemp()
        );
        
        // Schedule the cron job (CronManager builds the cron expression internally)
        $cronId = $this->cronManager->addStartEvent(
            $startTime,
            $event->getId(),
            $cronConfig['config_file']
        );
        
        // Store the cron ID for tracking
        $event->addMetadata('cron_id', $cronId);
        $this->eventRepository->save($event);
        
        return $cronId;
    }
}